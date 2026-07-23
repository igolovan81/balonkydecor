import { test, expect } from '@playwright/test';

const DEMO_SKU = 'NAR-SADA-KLASIK';

test('full checkout flow completes via the GoPay dev bypass', async ({ page }) => {
  await page.goto(`/cs/shop/${DEMO_SKU}`);
  await page.locator('.add-to-cart-form button[type="submit"]').click();
  await expect(page).toHaveURL(/\/cs\/cart$/);

  await page.locator(`a[href="/cs/checkout"]`).click();
  await expect(page).toHaveURL(/\/cs\/checkout$/);

  await page.locator('input[name="customer_name"]').fill('Playwright Test');
  await page.locator('input[name="customer_email"]').fill(`playwright-${Date.now()}@example.com`);
  await page.locator('input[name="customer_phone"]').fill('+420123456789');
  await page.locator('form.contact-form button[type="submit"]').click();

  await expect(page).toHaveURL(/\/cs\/checkout\/confirm$/);

  await page.locator('button', { hasText: /platit|pay/i }).click();

  await expect(page).toHaveURL(/\/cs\/order\/\S+$/);
  await expect(page.locator('.order-status')).toBeVisible();
});
