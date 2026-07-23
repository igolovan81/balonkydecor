# Shop Grid Quick Add-to-Cart Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a quick "Add to cart" action to each product card in the shop grid (`/{lang}/shop`), matching the action that already exists on the product detail page.

**Architecture:** Extend the existing `templates/public/shop/index.twig` card loop with a second action button (above the existing "Add to compare"). Simple products get a real form posting to the existing `/{lang}/cart/add` endpoint (no backend changes). Products with subtypes/variants get a plain link to the product detail page instead, labeled "View options" — the grid doesn't carry subtype data, and `CartController::add()` silently no-ops without a valid `subtype_id`, so a one-click add would look broken for those products.

**Tech Stack:** Twig 3, vanilla CSS (no build step), Playwright/TypeScript for e2e.

## Global Constraints

- `product.min_subtype_price is not null` is the existing, single source of truth in this template for "this product has subtypes" — reuse it, don't add a second check.
- All public-facing strings go through `t('key')`; the one new key (`shop.view_options`) is added to **all five** `lang/{cs,en,ru,uk,sk}.json` files, alphabetically sorted (matches existing file convention).
- No backend/controller/route changes — `CartController::add()` and `ShopController` already pass everything this feature needs.
- Run `php vendor/bin/phpunit` (whole suite) before every commit — it must be fully green (this feature touches no PHP, but the convention is unconditional).
- Twig/CSS changes are verified by rendering locally, not PHPUnit, per `.claude/rules/unit-testing.md`.
- `docker compose up -d` must be running (real MySQL) for the Playwright `webServer` and for the PHPUnit suite.

---

### Task 1: Failing e2e test for grid quick add-to-cart

**Files:**
- Modify: `tests/e2e/pages/ShopPage.ts`
- Modify: `tests/e2e/cart.spec.ts`

**Interfaces:**
- Consumes: `BasePage` (constructor `page`, `lang` — existing), `CartPage.table` locator (existing, `tests/e2e/pages/CartPage.ts`).
- Produces (used by Task 2's manual verification, and by nothing else in this plan): `ShopPage.addToCartForm(sku: string): Locator`, `ShopPage.addToCart(sku: string): Promise<void>`.

- [ ] **Step 1: Add the new locator/action to `ShopPage`**

Replace the full contents of `tests/e2e/pages/ShopPage.ts`:

```ts
import { Response } from '@playwright/test';
import { BasePage } from './BasePage';

export class ShopPage extends BasePage {
  async goto(): Promise<void> {
    await this.page.goto(`/${this.lang}/shop`);
  }

  async gotoProduct(sku: string): Promise<Response | null> {
    return this.page.goto(`/${this.lang}/shop/${sku}`);
  }

  addToCartForm(sku: string) {
    return this.page.locator('form.add-to-cart-form', {
      has: this.page.locator(`input[name="sku"][value="${sku}"]`),
    });
  }

  async addToCart(sku: string): Promise<void> {
    await this.addToCartForm(sku).locator('button[type="submit"]').click();
  }
}
```

- [ ] **Step 2: Add the failing test case to `cart.spec.ts`**

In `tests/e2e/cart.spec.ts`, add the import and the new test (keep the existing test and its `DEMO_SKU` constant as-is):

```ts
import { test, expect } from '@playwright/test';
import { ProductPage } from './pages/ProductPage';
import { CartPage } from './pages/CartPage';
import { ShopPage } from './pages/ShopPage';

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

test('quick-adding a product from the shop grid shows it on the cart page', async ({ page }) => {
  const product = new ProductPage(page);
  await product.goto(DEMO_SKU);
  const productName = await product.heading.innerText();

  const shop = new ShopPage(page);
  await shop.goto();
  await shop.addToCart(DEMO_SKU);

  await expect(page).toHaveURL(/\/cs\/cart$/);
  const cart = new CartPage(page);
  await expect(cart.table).toContainText(productName);
});
```

(The new test visits the product page first only to read its display name via `product.heading`, then navigates to the grid to do the actual add — this mirrors how the existing test learns the name, without hardcoding the demo product's display copy into the test.)

- [ ] **Step 3: Run the new test to verify it fails**

Run:
```bash
docker compose up -d
npx playwright test cart.spec.ts -g "quick-adding"
```
Expected: **FAIL** — timeout waiting for `form.add-to-cart-form` matching `NAR-SADA-KLASIK` on the shop grid (the grid has no such form yet).

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/pages/ShopPage.ts tests/e2e/cart.spec.ts
git commit -m "test: add failing e2e case for shop grid quick add-to-cart"
```

---

### Task 2: Template + CSS for the grid quick add-to-cart action

**Files:**
- Modify: `templates/public/shop/index.twig`
- Modify: `www/assets/css/style.css`

**Interfaces:**
- Consumes: `product.min_subtype_price`, `product.sku`, `lang` (all existing template variables in `index.twig`), translation key `shop.add_to_cart` (existing) and `shop.view_options` (new — added in Task 3; `I18n::t()` falls back to the raw key string until then, so the page still renders correctly for manual checks in this task).
- Produces: makes Task 1's `ShopPage.addToCartForm()`/`addToCart()` locators resolve.

- [ ] **Step 1: Insert the new button/link in `index.twig`**

In `templates/public/shop/index.twig`, insert immediately **before** the existing `compare-toggle-form` block (the new action ranks above compare visually):

```twig
                {% if product.min_subtype_price is not null %}
                <a href="/{{ lang }}/shop/{{ product.sku }}" class="btn btn-primary product-card-cta">
                    {{ t('shop.view_options') }}
                </a>
                {% else %}
                <form action="/{{ lang }}/cart/add" method="POST" class="add-to-cart-form add-to-cart-form--card">
                    <input type="hidden" name="sku" value="{{ product.sku }}">
                    <input type="hidden" name="qty" value="1">
                    <button type="submit" class="btn btn-primary product-card-cta">{{ t('shop.add_to_cart') }}</button>
                </form>
                {% endif %}
                <form action="/{{ lang }}/compare/toggle" method="POST" class="compare-toggle-form">
                    <input type="hidden" name="sku" value="{{ product.sku }}">
                    <input type="hidden" name="return" value="/{{ lang }}{{ current_path }}">
                    <button type="submit" class="compare-toggle {{ in_compare ? 'active' : '' }}">{{ in_compare ? t('shop.compare_remove') : t('shop.compare_add') }}</button>
                </form>
```

The surrounding `wishlist-toggle-form` block above this and the `</div>` closing `.product-card-wrap` below it are unchanged — only the new `{% if %}` block is inserted directly above the existing `compare-toggle-form`.

- [ ] **Step 2: Add CSS for the new button/link**

In `www/assets/css/style.css`, insert a new block right before the existing `/* Compare toggle (product card + detail page) */` comment (the block starting with `.compare-toggle-form { ... }`):

```css
/* Grid quick add-to-cart (product card) */
.product-card-cta { display: block; width: 100%; text-align: center; padding: .5rem 1rem; font-size: .8rem; margin-top: .5rem; }
```

`.add-to-cart-form--card` needs no rules of its own — `.product-card-cta` on the button already handles the block/width/spacing; the modifier class is kept on the `<form>` only so a future rule can target the form wrapper specifically without reaching into `.add-to-cart-form` (used elsewhere, on the detail page, with different spacing needs).

- [ ] **Step 3: Run the e2e test to verify it passes**

Run:
```bash
npx playwright test cart.spec.ts
```
Expected: **PASS** — both cases in `cart.spec.ts` green.

- [ ] **Step 4: Run the full PHPUnit suite**

Run: `php vendor/bin/phpunit`
Expected: PASS (no regressions — no PHP files changed in this task, this is the unconditional pre-commit check).

- [ ] **Step 5: Commit**

```bash
git add templates/public/shop/index.twig www/assets/css/style.css
git commit -m "feat: add quick add-to-cart action to shop grid cards"
```

---

### Task 3: Translations (all 5 languages)

**Files:**
- Modify: `lang/cs.json`
- Modify: `lang/en.json`
- Modify: `lang/sk.json`
- Modify: `lang/uk.json`
- Modify: `lang/ru.json`

**Interfaces:**
- Produces: `shop.view_options` — consumed by Task 2's template (already merged; this task only fills in the real copy, replacing the raw-key fallback).

- [ ] **Step 1: Add the key to `lang/cs.json`**

Insert alphabetically between `"shop.subtype"` and `"shop.wishlist_add"`:

```json
  "shop.subtype": "Varianta",
  "shop.view_options": "Zobrazit možnosti",
  "shop.wishlist_add": "Přidat do oblíbených",
```

- [ ] **Step 2: Add the key to `lang/en.json`** (same insertion point)

```json
  "shop.subtype": "Variant",
  "shop.view_options": "View options",
  "shop.wishlist_add": "Add to wishlist",
```

- [ ] **Step 3: Add the key to `lang/sk.json`** (same insertion point)

```json
  "shop.subtype": "Variant",
  "shop.view_options": "Zobraziť možnosti",
  "shop.wishlist_add": "Pridať do obľúbených",
```

- [ ] **Step 4: Add the key to `lang/uk.json`** (same insertion point)

```json
  "shop.subtype": "Варіант",
  "shop.view_options": "Переглянути варіанти",
  "shop.wishlist_add": "Додати в обране",
```

- [ ] **Step 5: Add the key to `lang/ru.json`** (same insertion point)

```json
  "shop.subtype": "Вариант",
  "shop.view_options": "Посмотреть варианты",
  "shop.wishlist_add": "Добавить в избранное",
```

- [ ] **Step 6: Verify all 5 files stay valid JSON with identical key sets**

Run:
```bash
for f in lang/cs.json lang/en.json lang/sk.json lang/uk.json lang/ru.json; do
  php -r "json_decode(file_get_contents('$f'), true) === null && exit(1); echo '$f OK\n';"
done
php -r '
$files = ["cs","en","sk","uk","ru"];
$base = array_keys(json_decode(file_get_contents("lang/cs.json"), true));
sort($base);
foreach ($files as $f) {
    $keys = array_keys(json_decode(file_get_contents("lang/$f.json"), true));
    sort($keys);
    if ($keys !== $base) { echo "MISMATCH in $f.json\n"; exit(1); }
}
echo "All 5 files have identical key sets.\n";
'
```
Expected: `All 5 files have identical key sets.` with no `MISMATCH` lines.

- [ ] **Step 7: Run the full PHPUnit suite and commit**

Run: `php vendor/bin/phpunit`
Expected: PASS.

```bash
git add lang/cs.json lang/en.json lang/sk.json lang/uk.json lang/ru.json
git commit -m "feat: add shop.view_options translation to all 5 languages"
```

---

### Task 4: Manual verification of both card variants

**Files:** none (verification only).

**Interfaces:** Exercises the full stack built in Tasks 1–3.

- [ ] **Step 1: Start the local stack**

Run: `docker compose up -d && php -S localhost:8080 -t www` (server in background/separate terminal).

- [ ] **Step 2: Verify a simple (no-subtype) product card**

Visit `http://localhost:8080/en/shop` in a browser. Confirm the `NAR-SADA-KLASIK` card shows, in order: wishlist heart, "Add to cart" button (filled/accent), "Add to compare" button (outlined) below it. Click "Add to cart" — confirm it lands on `/en/cart` with the product listed.

- [ ] **Step 3: Verify a subtype/variant product card**

Find a product with subtypes in the grid (a "from {price} Kč" price line is the tell — e.g. any product with a `product_subtypes` row; check via `/admin/products` if none is obviously visible locally). Confirm its card shows a "View options" button/link instead of "Add to cart", and that clicking it navigates to that product's detail page (`/en/shop/{sku}`) rather than posting a form.

- [ ] **Step 4: Verify language switching**

Switch to Czech (`/cs/shop`) and confirm the buttons read "Přidat do košíku" (existing `shop.add_to_cart` copy) and "Zobrazit možnosti" (new key) instead of the English fallback.

- [ ] **Step 5: Run both test suites one final time**

Run:
```bash
php vendor/bin/phpunit --testdox
npx playwright test
```
Expected: PASS on both, including the new grid quick-add-to-cart case from Task 1.

No commit for this task — it's verification only. If any step fails, fix the underlying task and re-run before re-verifying.
