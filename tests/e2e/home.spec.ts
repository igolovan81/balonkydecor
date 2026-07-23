import { test, expect } from '@playwright/test';

// Read-only checks, safe to run against production — tagged @smoke so
// `npm run test:e2e:prod` can select just these.

test('@smoke homepage loads and shows main nav', async ({ page }) => {
  await page.goto('/cs/');
  await expect(page).toHaveURL(/\/cs\/$/);
  await expect(page.locator('.lang-switcher')).toBeVisible();
  await expect(page.locator('a.cart-link')).toBeVisible();
});

test('@smoke language switcher navigates to the same page in another language', async ({ page }) => {
  await page.goto('/cs/shop');
  await page.locator('.lang-switcher a', { hasText: 'EN' }).click();
  await expect(page).toHaveURL(/\/en\/shop$/);
});

test('@smoke unknown product returns 404', async ({ page }) => {
  const response = await page.goto('/cs/shop/DOES-NOT-EXIST');
  expect(response?.status()).toBe(404);
});
