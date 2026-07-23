import { test, expect } from '@playwright/test';

// Local-only: not tagged @smoke. Relies on the local DB's demo SKU and is
// excluded from prod runs on principle — session-only, but no need to
// touch the live cart.

const DEMO_SKU = 'NAR-SADA-KLASIK';

test('adding a product to the cart shows it on the cart page', async ({ page }) => {
  await page.goto(`/cs/shop/${DEMO_SKU}`);
  const productName = await page.locator('h1').innerText();

  await page.locator('.add-to-cart-form button[type="submit"]').click();

  await expect(page).toHaveURL(/\/cs\/cart$/);
  await expect(page.locator('.cart-table')).toContainText(productName);
});
