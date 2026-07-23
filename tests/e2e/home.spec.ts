import { test, expect } from '@playwright/test';

test('homepage loads and shows main nav', async ({ page }) => {
  await page.goto('/cs/');
  await expect(page).toHaveURL(/\/cs\/$/);
  await expect(page.locator('.lang-switcher')).toBeVisible();
  await expect(page.locator('a.cart-link')).toBeVisible();
});

test('language switcher navigates to the same page in another language', async ({ page }) => {
  await page.goto('/cs/shop');
  await page.locator('.lang-switcher a', { hasText: 'EN' }).click();
  await expect(page).toHaveURL(/\/en\/shop$/);
});

test('unknown product returns 404', async ({ page }) => {
  const response = await page.goto('/cs/shop/DOES-NOT-EXIST');
  expect(response?.status()).toBe(404);
});
