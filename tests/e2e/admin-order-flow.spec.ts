import { test, expect, Page } from '@playwright/test';
import { createTempEditor, deleteTempEditor } from './helpers/admin-fixture';

// Local-only: NOT tagged @smoke. Creates a real order and a real (throwaway)
// admin user row via the fixture helper — must never run against production
// (npm run test:e2e:prod only selects @smoke tests).

async function addProductToCart(page: Page, sku: string): Promise<void> {
  await page.goto(`/cs/shop/${sku}`);
  await page.locator('.add-to-cart-form button[type="submit"]').click();
  await expect(page).toHaveURL(/\/cs\/cart$/);
}

async function checkout(page: Page): Promise<string> {
  await page.locator('a[href="/cs/checkout"]').click();
  await expect(page).toHaveURL(/\/cs\/checkout$/);

  await page.locator('input[name="customer_name"]').fill('Playwright Order Flow');
  await page.locator('input[name="customer_email"]').fill(`order-flow-${Date.now()}@example.com`);
  await page.locator('input[name="customer_phone"]').fill('+420123456789');
  await page.locator('form.contact-form button[type="submit"]').click();

  await expect(page).toHaveURL(/\/cs\/checkout\/confirm$/);
  await page.locator('button', { hasText: /platit|pay/i }).click();

  await expect(page).toHaveURL(/\/cs\/order\/\S+$/);
  const match = page.url().match(/\/cs\/order\/([^/?#]+)/);
  if (!match) throw new Error(`Could not extract order number from URL: ${page.url()}`);
  return match[1];
}

async function loginAsEditor(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/admin/login');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(/\/admin\/?$/);
}

async function setOrderStatus(page: Page, orderNumber: string, status: string): Promise<void> {
  await page.goto(`/admin/orders/${orderNumber}`);
  await page.locator('select[name="status"]').selectOption(status);
  await page.locator('form[action$="/status"] button[type="submit"]').click();
  await expect(page).toHaveURL(new RegExp(`/admin/orders/${orderNumber}$`));
  await expect(page.locator('select[name="status"]')).toHaveValue(status);
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
