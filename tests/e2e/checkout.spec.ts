import { test, expect } from '@playwright/test';
import { ProductPage } from './pages/ProductPage';
import { CartPage } from './pages/CartPage';
import { CheckoutPage } from './pages/CheckoutPage';
import { OrderPage } from './pages/OrderPage';

// Local-only: NOT tagged @smoke, and must never run against production.
// It creates a real order and, wherever GoPay credentials are actually
// configured (prod), would submit a real payment request — this test
// only works because local dev has no gopay_go_id set (dev bypass).

const DEMO_SKU = 'NAR-SADA-KLASIK';

test('full checkout flow completes via the GoPay dev bypass', async ({ page }) => {
  const product = new ProductPage(page);
  await product.goto(DEMO_SKU);
  await product.addToCart();
  await expect(page).toHaveURL(/\/cs\/cart$/);

  const cart = new CartPage(page);
  await cart.goToCheckout();
  await expect(page).toHaveURL(/\/cs\/checkout$/);

  const checkout = new CheckoutPage(page);
  await checkout.fillCustomerDetails({
    name: 'Playwright Test',
    email: `playwright-${Date.now()}@example.com`,
    phone: '+420123456789',
  });
  await expect(page).toHaveURL(/\/cs\/checkout\/confirm$/);

  await checkout.pay();

  await expect(page).toHaveURL(/\/cs\/order\/\S+$/);
  const order = new OrderPage(page);
  await expect(order.status).toBeVisible();
});
