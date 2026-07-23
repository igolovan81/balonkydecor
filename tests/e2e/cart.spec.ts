import { test, expect } from '@playwright/test';
import { ProductPage } from './pages/ProductPage';
import { CartPage } from './pages/CartPage';

// Local-only: not tagged @smoke. Relies on the local DB's demo SKU and is
// excluded from prod runs on principle — session-only, but no need to
// touch the live cart.

const DEMO_SKU = 'NAR-SADA-KLASIK';

test('adding a product to the cart shows it on the cart page', async ({ page }) => {
  const product = new ProductPage(page);
  await product.goto(DEMO_SKU);
  const productName = await product.heading.innerText();

  await product.addToCart();

  await expect(page).toHaveURL(/\/cs\/cart$/);
  const cart = new CartPage(page);
  await expect(cart.table).toContainText(productName);
});
