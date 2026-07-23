import { test, expect, Page } from '@playwright/test';
import { createTempEditor, deleteTempEditor } from './helpers/admin-fixture';
import { ProductPage } from './pages/ProductPage';
import { CartPage } from './pages/CartPage';
import { CheckoutPage } from './pages/CheckoutPage';
import { OrderPage } from './pages/OrderPage';
import { AdminOrderPage } from './pages/AdminOrderPage';
import { AdminOrderListPage } from './pages/AdminOrderListPage';
import { LoginFlow } from './workflows/LoginFlow';

// Local-only: NOT tagged @smoke. Creates a real order and a real (throwaway)
// admin user row via the fixture helper — must never run against production
// (npm run test:e2e:prod only selects @smoke tests).

async function addProductToCart(page: Page, sku: string): Promise<void> {
  const product = new ProductPage(page);
  await product.goto(sku);
  await product.addToCart();
  await expect(page).toHaveURL(/\/cs\/cart$/);
}

async function checkout(page: Page): Promise<string> {
  const cart = new CartPage(page);
  await cart.goToCheckout();
  await expect(page).toHaveURL(/\/cs\/checkout$/);

  const checkoutPage = new CheckoutPage(page);
  await checkoutPage.fillCustomerDetails({
    name: 'Playwright Order Flow',
    email: `order-flow-${Date.now()}@example.com`,
    phone: '+420123456789',
  });

  await expect(page).toHaveURL(/\/cs\/checkout\/confirm$/);
  await checkoutPage.pay();

  await expect(page).toHaveURL(/\/cs\/order\/\S+$/);
  return OrderPage.numberFromUrl(page.url());
}

async function setOrderStatus(page: Page, orderNumber: string, status: string): Promise<void> {
  const adminOrder = new AdminOrderPage(page);
  await adminOrder.goto(orderNumber);
  await adminOrder.setStatus(status);
  await expect(page).toHaveURL(new RegExp(`/admin/orders/${orderNumber}$`));
  await expect(adminOrder.statusSelect).toHaveValue(status);
}

test('editor moves a 1-product order from paid to ready to completed', async ({ page }) => {
  const editor = createTempEditor();
  try {
    await addProductToCart(page, 'NAR-SADA-KLASIK');
    const orderNumber = await checkout(page);

    await new LoginFlow(page).login(editor.email, editor.password);

    await setOrderStatus(page, orderNumber, 'ready');
    await setOrderStatus(page, orderNumber, 'completed');
  } finally {
    deleteTempEditor(editor.email);
  }
});

test('editor moves a 3-product order from paid to ready to cancelled', async ({ page }) => {
  const editor = createTempEditor();
  try {
    await addProductToCart(page, 'NAR-SADA-KLASIK');
    await addProductToCart(page, 'NAR-SADA-PREMIUM');
    await addProductToCart(page, 'SVA-OBLOUK-BILY');
    const orderNumber = await checkout(page);

    await new LoginFlow(page).login(editor.email, editor.password);

    await setOrderStatus(page, orderNumber, 'ready');
    await setOrderStatus(page, orderNumber, 'cancelled');
  } finally {
    deleteTempEditor(editor.email);
  }
});

test('editor skips ready and completes an order straight from paid', async ({ page }) => {
  const editor = createTempEditor();
  try {
    await addProductToCart(page, 'NAR-SADA-KLASIK');
    const orderNumber = await checkout(page);

    await new LoginFlow(page).login(editor.email, editor.password);

    await setOrderStatus(page, orderNumber, 'completed');
  } finally {
    deleteTempEditor(editor.email);
  }
});

test('editor resubmitting the current status is a no-op', async ({ page }) => {
  const editor = createTempEditor();
  try {
    await addProductToCart(page, 'NAR-SADA-KLASIK');
    const orderNumber = await checkout(page);

    await new LoginFlow(page).login(editor.email, editor.password);

    await setOrderStatus(page, orderNumber, 'paid');
    await setOrderStatus(page, orderNumber, 'paid');
  } finally {
    deleteTempEditor(editor.email);
  }
});

test('order list filter shows an order under its own status and hides it under others', async ({ page }) => {
  const editor = createTempEditor();
  try {
    await addProductToCart(page, 'NAR-SADA-KLASIK');
    const orderNumber = await checkout(page);

    await new LoginFlow(page).login(editor.email, editor.password);

    const list = new AdminOrderListPage(page);
    await list.goto('paid');
    await expect(list.linkFor(orderNumber)).toBeVisible();

    await list.goto('completed');
    await expect(list.linkFor(orderNumber)).toHaveCount(0);
  } finally {
    deleteTempEditor(editor.email);
  }
});

test('editor visiting an unknown order number gets a 404', async ({ page }) => {
  const editor = createTempEditor();
  try {
    await new LoginFlow(page).login(editor.email, editor.password);

    const response = await page.goto('/admin/orders/BD-NONEXISTENT-00000');
    expect(response?.status()).toBe(404);
  } finally {
    deleteTempEditor(editor.email);
  }
});

test('a tampered status value outside the allowed list is rejected server-side', async ({ page }) => {
  const editor = createTempEditor();
  try {
    await addProductToCart(page, 'NAR-SADA-KLASIK');
    const orderNumber = await checkout(page);

    await new LoginFlow(page).login(editor.email, editor.password);

    // Bypasses the <select>'s fixed option list to simulate a tampered
    // request — the server must reject it, not just the browser UI.
    await page.request.post(`/admin/orders/${orderNumber}/status`, {
      form: { status: 'shipped-by-drone' },
    });

    const adminOrder = new AdminOrderPage(page);
    await adminOrder.goto(orderNumber);
    await expect(adminOrder.statusSelect).toHaveValue('paid');
  } finally {
    deleteTempEditor(editor.email);
  }
});

test('an unauthenticated status change request is redirected to login and leaves the order untouched', async ({ page }) => {
  const editor = createTempEditor();
  try {
    await addProductToCart(page, 'NAR-SADA-KLASIK');
    const orderNumber = await checkout(page);

    // No login here — this page/context has no admin session yet.
    const response = await page.request.post(`/admin/orders/${orderNumber}/status`, {
      form: { status: 'completed' },
      maxRedirects: 0,
    });
    expect(response.status()).toBe(302);
    expect(response.headers()['location']).toBe('/admin/login');

    await new LoginFlow(page).login(editor.email, editor.password);
    const adminOrder = new AdminOrderPage(page);
    await adminOrder.goto(orderNumber);
    await expect(adminOrder.statusSelect).toHaveValue('paid');
  } finally {
    deleteTempEditor(editor.email);
  }
});
