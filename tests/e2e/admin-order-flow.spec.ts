import { test, expect, Page } from '@playwright/test';
import { createTempEditor, deleteTempEditor } from './helpers/admin-fixture';
import { ProductPage } from './pages/ProductPage';
import { CartPage } from './pages/CartPage';
import { CheckoutPage } from './pages/CheckoutPage';
import { OrderPage } from './pages/OrderPage';
import { AdminLoginPage } from './pages/AdminLoginPage';
import { AdminOrderPage } from './pages/AdminOrderPage';

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

async function loginAsEditor(page: Page, email: string, password: string): Promise<void> {
  const adminLogin = new AdminLoginPage(page);
  await adminLogin.goto();
  await adminLogin.login(email, password);
  await expect(page).toHaveURL(/\/admin\/?$/);
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

    await loginAsEditor(page, editor.email, editor.password);

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

    await loginAsEditor(page, editor.email, editor.password);

    await setOrderStatus(page, orderNumber, 'ready');
    await setOrderStatus(page, orderNumber, 'cancelled');
  } finally {
    deleteTempEditor(editor.email);
  }
});
