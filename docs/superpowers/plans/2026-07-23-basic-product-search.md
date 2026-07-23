# Basic Product Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let shoppers find products from a search box in the global nav, filtering the existing `/shop` product grid by name or SKU.

**Architecture:** Extend `ProductModel::allActive()` with an optional `$query` LIKE filter that combines (AND) with the existing category filter; `ShopController::index()` reads a `q` query param and passes it through; the nav search form (in `base.twig`) submits `GET /{lang}/shop?q=...`, reusing the existing product-grid template rather than a new route/controller.

**Tech Stack:** Slim 4, Twig 3, PDO/MySQL (PHPUnit 11 for model tests), Playwright/TypeScript for e2e.

## Global Constraints

- No build step: CSS/JS changes are plain hand-written files, no bundler, no new npm/composer dependencies.
- SQL access only through prepared statements with bound parameters — no string-interpolated user input, ever.
- All five `lang/{cs,en,ru,uk,sk}.json` files must keep an identical set of keys — every new key added to one is added to all five, in the same alphabetically-sorted position.
- Every new visible string goes through `t('key')` — no hardcoded user-facing text in templates.
- Run the full suite (`php vendor/bin/phpunit`) before any commit that touches PHP; it must be green.
- CSS: use existing design tokens (`--border`, `--surface`, `--text`, `--accent`, `--ui-font`, etc.), flat kebab-case class names, responsive rules placed next to the component they modify (768px/480px breakpoints only).
- Twig: rely on auto-escaping; never use `|raw` on the new `query` value.

---

### Task 1: `ProductModel::allActive()` search filter

**Files:**
- Modify: `src/Models/ProductModel.php:8-30` (the `allActive()` method)
- Test: `tests/Unit/Models/ProductModelTest.php`

**Interfaces:**
- Consumes: nothing new — this is the base of the feature.
- Produces: `ProductModel::allActive(string $lang, ?int $categoryId = null, ?string $query = null): array`. `$query`, when non-null, filters rows to those where `product_t.name` (in the requested `$lang`) or `products.sku` contains `$query` as a substring (case/accent-insensitive, via the table's default `utf8mb4` collation). Combines with `$categoryId` via `AND` when both are supplied. Later tasks (`ShopController`) call this with the trimmed `q` query param (or `null`).

- [ ] **Step 1: Write the failing tests**

Add these three tests to `tests/Unit/Models/ProductModelTest.php`, right after `test_filter_by_category()` (around line 228):

```php
    public function test_all_active_filters_by_search_query_matching_name(): void
    {
        $unique = 'Zebra-' . uniqid();
        $matchId = $this->makeProduct();
        ProductModel::setTranslations($matchId, ['en' => ['name' => 'Balloon ' . $unique]]);
        $otherId = $this->makeProduct();
        ProductModel::setTranslations($otherId, ['en' => ['name' => 'Unrelated Balloon']]);

        $this->assertNotNull($this->activeRow(null, $unique, $matchId));
        $this->assertNull($this->activeRow(null, $unique, $otherId));
    }

    public function test_all_active_filters_by_search_query_matching_sku(): void
    {
        $productId = $this->makeProduct();
        $sku       = $this->skuOf($productId);

        $this->assertNotNull($this->activeRow(null, $sku, $productId));
    }

    public function test_all_active_filters_by_search_query_and_category_combined(): void
    {
        $unique = 'Griffin-' . uniqid();
        $catA   = $this->makeCategory();
        $catB   = $this->makeCategory();
        $pdo    = Database::getConnection();

        $skuA = 'COMBO-' . strtoupper(uniqid());
        $pdo->prepare('INSERT INTO products (category_id, sku, price) VALUES (?, ?, 9.99)')->execute([$catA, $skuA]);
        $idA = (int) $pdo->lastInsertId();
        ProductModel::setTranslations($idA, ['en' => ['name' => 'Balloon ' . $unique]]);

        $skuB = 'COMBO-' . strtoupper(uniqid());
        $pdo->prepare('INSERT INTO products (category_id, sku, price) VALUES (?, ?, 9.99)')->execute([$catB, $skuB]);
        $idB = (int) $pdo->lastInsertId();
        ProductModel::setTranslations($idB, ['en' => ['name' => 'Balloon ' . $unique]]);

        // Both idA and idB match the query text, but only idA is in catA —
        // proves category + query combine via AND, not OR.
        $this->assertNotNull($this->activeRow($catA, $unique, $idA));
        $this->assertNull($this->activeRow($catA, $unique, $idB));
    }

    private function activeRow(?int $categoryId, ?string $query, int $productId): ?array
    {
        foreach (ProductModel::allActive('en', $categoryId, $query) as $row) {
            if ((int) $row['id'] === $productId) {
                return $row;
            }
        }
        return null;
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Ensure the local DB is up first: `docker compose up -d`

Run: `php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php --filter test_all_active_filters_by_search_query`
Expected: FAIL — `allActive()` only accepts 2 arguments, so this is a `TypeError` / argument count error for all three new tests.

- [ ] **Step 3: Implement the minimal change**

Replace the body of `ProductModel::allActive()` in `src/Models/ProductModel.php`:

```php
    public static function allActive(string $lang, ?int $categoryId = null, ?string $query = null): array
    {
        $pdo    = Database::getConnection();
        $sql    = '
            SELECT p.id, p.category_id, p.sku, p.price, p.stock_type, p.stock_qty,
                   COALESCE(t.name, p.sku) AS name,
                   t.description,
                   i.filename AS primary_image,
                   (SELECT MIN(price) FROM product_subtypes WHERE product_id = p.id) AS min_subtype_price
            FROM products p
            LEFT JOIN product_t t ON t.product_id = p.id AND t.lang_code = :lang
            LEFT JOIN product_images i ON i.product_id = p.id AND i.is_primary = 1
            WHERE p.is_active = 1
        ';
        $params = ['lang' => $lang];
        if ($categoryId !== null) {
            $sql .= ' AND p.category_id = :cat';
            $params['cat'] = $categoryId;
        }
        if ($query !== null) {
            $sql .= ' AND (t.name LIKE :q OR p.sku LIKE :q)';
            $params['q'] = '%' . $query . '%';
        }
        $sql .= ' ORDER BY p.sort_order, p.id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php`
Expected: PASS — the whole file, not just the filtered tests (confirms the signature change didn't break `test_all_active_returns_array`, `test_filter_by_category`, or the min-subtype-price tests, all of which call `allActive()`).

- [ ] **Step 5: Run the full suite**

Run: `php vendor/bin/phpunit`
Expected: PASS (no regressions elsewhere).

- [ ] **Step 6: Commit**

```bash
git add src/Models/ProductModel.php tests/Unit/Models/ProductModelTest.php
git commit -m "feat: add search query filter to ProductModel::allActive"
```

---

### Task 2: Translation keys

**Files:**
- Modify: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`

**Interfaces:**
- Consumes: nothing.
- Produces four new keys, used by Task 3's templates: `nav.search_placeholder`, `nav.search_submit`, `shop.search_results_for` (interpolates `{query}`), `shop.no_results_for_query` (interpolates `{query}`).

- [ ] **Step 1: Add `nav.search_placeholder` and `nav.search_submit`**

In each of the five files, insert two new lines between the existing `"nav.register"` and `"nav.services"` lines (currently lines 111-112 in every file, confirmed identical across all five):

`lang/cs.json`:
```json
  "nav.register": "Registrace",
  "nav.search_placeholder": "Hledat produkty",
  "nav.search_submit": "Hledat",
  "nav.services": "Služby",
```

`lang/en.json`:
```json
  "nav.register": "Register",
  "nav.search_placeholder": "Search products",
  "nav.search_submit": "Search",
  "nav.services": "Services",
```

`lang/ru.json`:
```json
  "nav.register": "Регистрация",
  "nav.search_placeholder": "Поиск товаров",
  "nav.search_submit": "Поиск",
  "nav.services": "Услуги",
```

`lang/uk.json`:
```json
  "nav.register": "Реєстрація",
  "nav.search_placeholder": "Пошук товарів",
  "nav.search_submit": "Пошук",
  "nav.services": "Послуги",
```

`lang/sk.json`:
```json
  "nav.register": "Registrácia",
  "nav.search_placeholder": "Hľadať produkty",
  "nav.search_submit": "Hľadať",
  "nav.services": "Služby",
```

- [ ] **Step 2: Add `shop.search_results_for` and `shop.no_results_for_query`**

In each of the five files, insert `shop.no_results_for_query` right after the existing `"shop.no_products"` line, and `shop.search_results_for` right after the existing `"shop.recently_viewed_title"` line (both confirmed at identical positions across all five files: `no_products` at line 140, `recently_viewed_title` at line 144).

`lang/cs.json`:
```json
  "shop.no_products": "Žádné produkty v této kategorii.",
  "shop.no_results_for_query": "Pro dotaz {query} nebyly nalezeny žádné produkty.",
```
```json
  "shop.recently_viewed_title": "Naposledy zobrazené produkty",
  "shop.search_results_for": "Výsledky hledání pro {query}",
```

`lang/en.json`:
```json
  "shop.no_products": "No products in this category.",
  "shop.no_results_for_query": "No products found for {query}.",
```
```json
  "shop.recently_viewed_title": "Recently viewed products",
  "shop.search_results_for": "Search results for {query}",
```

`lang/ru.json`:
```json
  "shop.no_products": "Нет товаров в этой категории.",
  "shop.no_results_for_query": "По запросу {query} товары не найдены.",
```
```json
  "shop.recently_viewed_title": "Недавно просмотренные товары",
  "shop.search_results_for": "Результаты поиска по запросу {query}",
```

`lang/uk.json`:
```json
  "shop.no_products": "Немає товарів у цій категорії.",
  "shop.no_results_for_query": "За запитом {query} товарів не знайдено.",
```
```json
  "shop.recently_viewed_title": "Нещодавно переглянуті товари",
  "shop.search_results_for": "Результати пошуку за запитом {query}",
```

`lang/sk.json`:
```json
  "shop.no_products": "Žiadne produkty v tejto kategórii.",
  "shop.no_results_for_query": "Pre dopyt {query} neboli nájdené žiadne produkty.",
```
```json
  "shop.recently_viewed_title": "Naposledy zobrazené produkty",
  "shop.search_results_for": "Výsledky hľadania pre {query}",
```

- [ ] **Step 3: Verify all five files still have identical key sets**

Run:
```bash
for f in cs en ru uk sk; do php -r "echo implode(\"\n\", array_keys(json_decode(file_get_contents('lang/'.\$argv[1].'.json'), true))), \"\n\";" "$f" | sort > /tmp/keys-$f.txt; done
diff /tmp/keys-cs.txt /tmp/keys-en.txt && diff /tmp/keys-cs.txt /tmp/keys-ru.txt && diff /tmp/keys-cs.txt /tmp/keys-uk.txt && diff /tmp/keys-cs.txt /tmp/keys-sk.txt && echo "KEYS MATCH"
```
Expected: `KEYS MATCH` with no diff output above it. Also verify each file is still valid JSON: `php -r "json_decode(file_get_contents('lang/cs.json')); echo json_last_error_msg();"` for each file — expected output `No error`.

- [ ] **Step 4: Commit**

```bash
git add lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json
git commit -m "feat: add translation keys for product search"
```

---

### Task 3: Wire the search query through `ShopController` and templates

**Files:**
- Modify: `src/Controllers/ShopController.php:15-27` (the `index()` method)
- Modify: `templates/layout/base.twig:37-38` (nav)
- Modify: `templates/public/shop/index.twig` (category links, results heading, empty state)

**Interfaces:**
- Consumes: `ProductModel::allActive(string $lang, ?int $categoryId, ?string $query)` from Task 1; `nav.search_placeholder`/`nav.search_submit`/`shop.search_results_for`/`shop.no_results_for_query` from Task 2.
- Produces: `ShopController::index()` passes a new `query` template variable (trimmed `?q=` string, or `null`) to `public/shop/index.twig`. The nav search form in `base.twig` is present on every public page and submits to `/{lang}/shop`. This is what Task 5's e2e tests drive.

- [ ] **Step 1: Update `ShopController::index()`**

Replace the method body in `src/Controllers/ShopController.php`:

```php
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang       = $request->getAttribute('lang');
        $params     = $request->getQueryParams();
        $categoryId = isset($params['category']) ? (int) $params['category'] : null;
        $query      = isset($params['q']) ? trim((string) $params['q']) : '';
        $query      = $query !== '' ? $query : null;

        return $this->render($request, $response, 'public/shop/index.twig', [
            'categories'      => CategoryModel::allWithTranslation($lang),
            'products'        => ProductModel::allActive($lang, $categoryId, $query),
            'active_cat'      => $categoryId,
            'query'           => $query,
            'wishlist_skus'   => Wishlist::skus(),
            'compare_skus'    => Compare::skus(),
            'recently_viewed' => RecentlyViewed::items($lang),
        ]);
    }
```

- [ ] **Step 2: Add the nav search form to `base.twig`**

In `templates/layout/base.twig`, insert a search form right after the closing `</nav>` tag (line 37) and before the `wishlist-link` (line 38), so it renders before the wishlist/compare/cart/account links:

```twig
                </div>
            </nav>
            <form class="nav-search" action="/{{ lang }}/shop" method="GET">
                <input type="search" name="q" value="{{ query ?? '' }}"
                       placeholder="{{ t('nav.search_placeholder') }}"
                       aria-label="{{ t('nav.search_placeholder') }}">
                <button type="submit" aria-label="{{ t('nav.search_submit') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </button>
            </form>
            <a href="/{{ lang }}/wishlist" class="wishlist-link">{{ t('nav.wishlist') }}</a>
```

(`query` is only set by `ShopController`; on every other page it's undefined, and since the app's Twig environment doesn't set `strict_variables`, `{{ query ?? '' }}` safely renders empty there.)

- [ ] **Step 3: Preserve the active search across category links, in `templates/public/shop/index.twig`**

Replace the category filter block:

```twig
        {% if categories %}
        <div class="cat-filter-list">
            <a href="/{{ lang }}/shop{{ query ? '?q=' ~ query|url_encode : '' }}" class="cat-filter {{ active_cat is null ? 'active' : '' }}">
                {{ t('shop.all') }}
            </a>
            {% for cat in categories %}
            <a href="/{{ lang }}/shop?category={{ cat.id }}{{ query ? '&q=' ~ query|url_encode : '' }}"
               class="cat-filter {{ active_cat == cat.id ? 'active' : '' }}">
                {{ cat.name }}
            </a>
            {% endfor %}
        </div>
        {% endif %}
```

- [ ] **Step 4: Add a results heading and query-aware empty state, in `templates/public/shop/index.twig`**

Replace the `<main class="shop-main">` block's opening and empty-state branch:

```twig
    <main class="shop-main">
        {% if query %}
        <p class="shop-search-heading">{{ t('shop.search_results_for', {query: query}) }}</p>
        {% endif %}
        {% if products %}
        <div class="product-grid">
```

...and:

```twig
        {% else %}
        <p class="empty-state">{{ query ? t('shop.no_results_for_query', {query: query}) : t('shop.no_products') }}</p>
        {% endif %}
```

- [ ] **Step 5: Manually verify by rendering the page**

Per `.claude/rules/unit-testing.md`, Twig templates are verified by rendering, not unit tests. With `docker compose up -d` running and `php -S localhost:8080 -t www` started in another terminal:

```bash
curl -s "http://localhost:8080/en/shop?q=Classic" | grep -o 'Search results for Classic'
curl -s "http://localhost:8080/en/shop?q=zzz-no-such-product-zzz" | grep -o 'No products found for zzz-no-such-product-zzz'
curl -s "http://localhost:8080/en/shop" | grep -o 'class="nav-search"'
```
Expected: each command prints the matched string (confirms the heading, the query-aware empty state, and that the nav search form renders on a page with no active query).

- [ ] **Step 6: Run the full PHP suite**

Run: `php vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/ShopController.php templates/layout/base.twig templates/public/shop/index.twig
git commit -m "feat: wire product search query through shop controller and templates"
```

---

### Task 4: Nav search box styling

**Files:**
- Modify: `www/assets/css/style.css:49-75` (header/nav rules)

**Interfaces:**
- Consumes: the `.nav-search` markup from Task 3.
- Produces: no new interface — visual only.

- [ ] **Step 1: Add desktop styles**

In `www/assets/css/style.css`, right after the existing `.cart-link, .wishlist-link, .compare-link, .account-link:hover` rule (line 51) and before the `/* Hamburger toggle */` comment (line 53), add:

```css
/* Nav search box */
.nav-search { order: 3; display: flex; align-items: center; gap: .35rem; }
.nav-search input[type="search"] { border: 1px solid var(--border); border-radius: 4px; padding: .35rem .6rem; font-family: var(--ui-font); font-size: .85rem; width: 160px; background: var(--surface); color: var(--text); }
.nav-search button { display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border: 1px solid var(--border); border-radius: 4px; background: none; color: var(--text); cursor: pointer; }
.nav-search button:hover { color: var(--accent); border-color: var(--accent); }
.nav-search svg { width: 16px; height: 16px; }
```

(`order: 3` matches the existing `.cart-link`/`.wishlist-link`/etc. group; since the search form's markup comes before those links in `base.twig`, equal-order flex items keep their source order, so the search box renders first among that group — no change to the existing links' `order` values needed.)

- [ ] **Step 2: Add mobile (768px) styles**

In the existing `@media (max-width: 768px)` block for the header (lines 58-75), fold the search box into the collapsible menu the same way `.main-nav`/`.lang-switcher` already are. Change:

```css
    .main-nav, .lang-switcher { display: none; flex-basis: 100%; }
    .main-nav { order: 4; flex-direction: column; gap: 0; }
```

to:

```css
    .main-nav, .lang-switcher, .nav-search { display: none; flex-basis: 100%; }
    .main-nav { order: 4; flex-direction: column; gap: 0; }
    .nav-search { order: 4; padding: .75rem 0; border-top: 1px solid var(--border); }
    .nav-search input[type="search"] { flex: 1; }
```

and change:

```css
    .header-inner.is-open .main-nav,
    .header-inner.is-open .lang-switcher { display: flex; }
```

to:

```css
    .header-inner.is-open .main-nav,
    .header-inner.is-open .lang-switcher,
    .header-inner.is-open .nav-search { display: flex; }
```

- [ ] **Step 3: Manually verify in a browser**

With `php -S localhost:8080 -t www` running, open `http://localhost:8080/en/shop` and:
- At full desktop width: confirm the search box appears in the nav, before the wishlist/compare/cart links, styled with the site's bronze/border tokens (not raw black borders or default browser styling).
- Resize below 768px (or use browser dev tools device toolbar): confirm the search box disappears along with the rest of the nav, and reappears when the hamburger menu is opened, stacked below the nav links.

- [ ] **Step 4: Commit**

```bash
git add www/assets/css/style.css
git commit -m "style: add nav search box styling"
```

---

### Task 5: E2E tests

**Files:**
- Modify: `tests/e2e/pages/ShopPage.ts`
- Create: `tests/e2e/shop.spec.ts`

**Interfaces:**
- Consumes: `ShopPage.goto()` (existing), the rendered `.nav-search` form and `.product-card-wrap`/`.empty-state` markup from Task 3.
- Produces: `ShopPage.search(term: string): Promise<void>` and `ShopPage.productCard(sku: string): Locator`, usable by future shop-related specs.

- [ ] **Step 1: Extend `ShopPage`**

Replace the contents of `tests/e2e/pages/ShopPage.ts`:

```typescript
import { Locator, Response } from '@playwright/test';
import { BasePage } from './BasePage';

export class ShopPage extends BasePage {
  async goto(): Promise<void> {
    await this.page.goto(`/${this.lang}/shop`);
  }

  async gotoProduct(sku: string): Promise<Response | null> {
    return this.page.goto(`/${this.lang}/shop/${sku}`);
  }

  async search(term: string): Promise<void> {
    await this.page.fill('.nav-search input[name="q"]', term);
    await this.page.click('.nav-search button[type="submit"]');
  }

  productCard(sku: string): Locator {
    return this.page.locator('.product-card-wrap', {
      has: this.page.locator(`a[href="/${this.lang}/shop/${sku}"]`),
    });
  }
}
```

- [ ] **Step 2: Write the spec**

Create `tests/e2e/shop.spec.ts`:

```typescript
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
```

- [ ] **Step 3: Run the new spec**

Ensure `docker compose up -d` is running (Playwright manages the local PHP server itself per `npm run test:e2e`).

Run: `npx playwright test tests/e2e/shop.spec.ts`
Expected: both tests PASS.

- [ ] **Step 4: Run the full e2e suite**

Run: `npm run test:e2e`
Expected: PASS (no regressions in other specs — in particular `home.spec.ts`, which also uses `ShopPage`).

- [ ] **Step 5: Commit**

```bash
git add tests/e2e/pages/ShopPage.ts tests/e2e/shop.spec.ts
git commit -m "test: add e2e coverage for product search"
```
