import { test, expect } from '@playwright/test';
import { HomePage } from './pages/HomePage';
import { ShopPage } from './pages/ShopPage';

// Read-only checks, safe to run against production — tagged @smoke so
// `npm run test:e2e:prod` can select just these.

test('@smoke homepage loads and shows main nav', async ({ page }) => {
  const home = new HomePage(page);
  await home.goto();

  await expect(page).toHaveURL(/\/cs\/$/);
  await expect(home.langSwitcher).toBeVisible();
  await expect(home.cartLink).toBeVisible();
});

test('@smoke language switcher navigates to the same page in another language', async ({ page }) => {
  const shop = new ShopPage(page);
  await shop.goto();

  await shop.switchLanguage('EN');
  await expect(page).toHaveURL(/\/en\/shop$/);
});

test('@smoke unknown product returns 404', async ({ page }) => {
  // A bare status check, not a real navigation — page.request avoids a
  // Chromium quirk where --headed sometimes turns a bodyless 404 response
  // into a net::ERR_HTTP_RESPONSE_CODE_FAILURE instead of resolving
  // page.goto() with the response (see .claude/rules/e2e-testing.md).
  const response = await page.request.get('/cs/shop/DOES-NOT-EXIST');
  expect(response.status()).toBe(404);
});
