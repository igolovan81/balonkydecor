import { test, expect } from '@playwright/test';
import { ShopPage } from './pages/ShopPage';

// Local-only: not tagged @smoke. These assertions depend on the local
// seeded demo catalog (DEMO_SKU below, from database/migrations/V002)
// which does not exist on production — see cart.spec.ts's DEMO_SKU for
// the same convention.

const DEMO_SKU = 'NAR-SADA-KLASIK'; // "Narozeninová sada Classic" (cs)

test('search filters the product grid by name', async ({ page }) => {
  const shop = new ShopPage(page);
  await shop.goto();

  await shop.search('Classic');

  await expect(page).toHaveURL(/\/cs\/shop\?q=Classic$/);
  await expect(shop.productCard(DEMO_SKU)).toBeVisible();
});

test('search with no matches shows the empty state', async ({ page }) => {
  const shop = new ShopPage(page);
  await shop.goto();

  const term = 'zzz-no-such-product-zzz';
  await shop.search(term);

  await expect(page).toHaveURL(new RegExp(`/cs/shop\\?q=${term}$`));
  await expect(page.locator('.empty-state')).toContainText(term);
});
