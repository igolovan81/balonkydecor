# Recently Viewed Products Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show visitors the products they've recently viewed — on the product detail
page, the shop listing sidebar, and the homepage — persisted across visits via a
long-lived cookie (not the session, which is too short-lived for this).

**Architecture:** A new static service `RecentlyViewed` (same shape as the existing
`Compare`/`Wishlist` services) reads/writes a JSON array of SKUs in a cookie instead
of `$_SESSION`. Controllers call `track()` on product view and pass `items()` to
templates. Two small, reusable Twig partials render the data: a horizontal card row
(product page, homepage) and a compact sidebar list (shop listing).

**Tech Stack:** PHP 8 / Slim 4, PDO/MySQL, Twig 3, plain CSS (no build step), PHPUnit
11 against real Docker MySQL.

## Global Constraints

- TDD: write the failing test first, watch it fail, then implement (`.claude/rules/unit-testing.md`).
- Run the whole suite (`php vendor/bin/phpunit`) before considering any task done — not just the file touched.
- Model/service tests use real Docker MySQL, no mocks; use `INSERT IGNORE` for shared fixtures and `uniqid()`-style unique values only where a uniqueness constraint demands it.
- All public-facing strings go through `t('key')`; every new key must be added to **all five** `lang/{cs,en,ru,uk,sk}.json` files with identical key sets.
- Public links are language-prefixed: `href="/{{ lang }}/..."`.
- CSS: only the existing two breakpoints (768px, 480px), tokens from `:root` in `www/assets/css/style.css` (no hardcoded colors), flat kebab-case class names, `--modifier` suffix for variants.
- Twig auto-escaping stays on; no `|raw` on anything not already sanitized/JSON-encoded server-side.
- Prepared statements only; no request data interpolated into SQL.
- Public controllers return `$this->render(...)`; missing entities return `$response->withStatus(404)` (not applicable here — no new routes/controllers in this feature).

---

### Task 1: `RecentlyViewed` service

**Files:**
- Create: `src/Services/RecentlyViewed.php`
- Test: `tests/Unit/Services/RecentlyViewedTest.php`

**Interfaces:**
- Produces (used by Task 2): `RecentlyViewed::track(string $sku): void`,
  `RecentlyViewed::skus(?string $exclude = null): array`,
  `RecentlyViewed::items(string $lang, ?string $exclude = null): array`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/RecentlyViewedTest.php`:

```php
<?php
namespace Tests\Unit\Services;

use App\Models\Database;
use App\Services\RecentlyViewed;
use PHPUnit\Framework\TestCase;

class RecentlyViewedTest extends TestCase
{
    private static int $categoryId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO categories (slug) VALUES ('test-recently-viewed')");
        $row = $pdo->query("SELECT id FROM categories WHERE slug='test-recently-viewed'")->fetch();
        self::$categoryId = (int) $row['id'];

        $pdo->exec("INSERT IGNORE INTO products (category_id, sku, price) VALUES (" . self::$categoryId . ", 'TEST-RV-SKU-001', 19.90)");
        $id = $pdo->query("SELECT id FROM products WHERE sku='TEST-RV-SKU-001'")->fetch()['id'];
        $pdo->exec("INSERT IGNORE INTO product_t (product_id, lang_code, name) VALUES ({$id}, 'en', 'Recently Viewed Test Balloon')");
    }

    protected function setUp(): void
    {
        unset($_COOKIE['recently_viewed']);
    }

    public function test_track_adds_sku_to_empty_list(): void
    {
        RecentlyViewed::track('SKU-1');
        $this->assertSame(['SKU-1'], RecentlyViewed::skus());
    }

    public function test_track_prepends_new_sku_before_older_ones(): void
    {
        RecentlyViewed::track('SKU-1');
        RecentlyViewed::track('SKU-2');
        $this->assertSame(['SKU-2', 'SKU-1'], RecentlyViewed::skus());
    }

    public function test_track_moves_existing_sku_to_front_instead_of_duplicating(): void
    {
        RecentlyViewed::track('SKU-1');
        RecentlyViewed::track('SKU-2');
        RecentlyViewed::track('SKU-1');
        $this->assertSame(['SKU-1', 'SKU-2'], RecentlyViewed::skus());
    }

    public function test_track_caps_list_at_eight_dropping_oldest(): void
    {
        for ($i = 1; $i <= 9; $i++) {
            RecentlyViewed::track("SKU-{$i}");
        }
        $this->assertSame(
            ['SKU-9', 'SKU-8', 'SKU-7', 'SKU-6', 'SKU-5', 'SKU-4', 'SKU-3', 'SKU-2'],
            RecentlyViewed::skus()
        );
    }

    public function test_skus_excludes_given_sku_when_asked(): void
    {
        RecentlyViewed::track('SKU-1');
        RecentlyViewed::track('SKU-2');
        $this->assertSame(['SKU-1'], RecentlyViewed::skus('SKU-2'));
    }

    public function test_skus_is_empty_when_no_cookie_set(): void
    {
        $this->assertSame([], RecentlyViewed::skus());
    }

    public function test_items_hydrates_saved_product(): void
    {
        RecentlyViewed::track('TEST-RV-SKU-001');
        $items = RecentlyViewed::items('en');
        $this->assertCount(1, $items);
        $this->assertSame('TEST-RV-SKU-001', $items[0]['sku']);
        $this->assertSame('Recently Viewed Test Balloon', $items[0]['name']);
    }

    public function test_items_skips_sku_that_no_longer_resolves(): void
    {
        RecentlyViewed::track('TEST-RV-SKU-001');
        RecentlyViewed::track('SKU-DOES-NOT-EXIST');
        $items = RecentlyViewed::items('en');
        $skus  = array_column($items, 'sku');
        $this->assertSame(['TEST-RV-SKU-001'], $skus);
    }

    public function test_items_excludes_given_sku(): void
    {
        RecentlyViewed::track('TEST-RV-SKU-001');
        $items = RecentlyViewed::items('en', 'TEST-RV-SKU-001');
        $this->assertSame([], $items);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Services/RecentlyViewedTest.php --testdox`
Expected: FAIL / ERROR — `Class "App\Services\RecentlyViewed" not found`.

- [ ] **Step 3: Implement the service**

Create `src/Services/RecentlyViewed.php`:

```php
<?php
namespace App\Services;

use App\Models\ProductModel;

class RecentlyViewed
{
    private const COOKIE_NAME = 'recently_viewed';
    private const MAX_ITEMS   = 8;
    private const TTL_DAYS    = 90;

    private static function read(): array
    {
        $raw     = $_COOKIE[self::COOKIE_NAME] ?? '';
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private static function write(array $skus): void
    {
        $value = json_encode($skus);
        $_COOKIE[self::COOKIE_NAME] = $value;
        setcookie(self::COOKIE_NAME, $value, [
            'expires'  => time() + self::TTL_DAYS * 86400,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function track(string $sku): void
    {
        $skus = self::read();
        $skus = array_values(array_diff($skus, [$sku]));
        array_unshift($skus, $sku);
        $skus = array_slice($skus, 0, self::MAX_ITEMS);
        self::write($skus);
    }

    public static function skus(?string $exclude = null): array
    {
        $skus = self::read();
        if ($exclude !== null) {
            $skus = array_values(array_diff($skus, [$exclude]));
        }
        return $skus;
    }

    public static function items(string $lang, ?string $exclude = null): array
    {
        $items = [];
        foreach (self::skus($exclude) as $sku) {
            $product = ProductModel::findBySku($sku, $lang);
            if ($product !== null) {
                $items[] = $product;
            }
        }
        return $items;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Services/RecentlyViewedTest.php --testdox`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/RecentlyViewed.php tests/Unit/Services/RecentlyViewedTest.php
git commit -m "feat: add cookie-backed RecentlyViewed service"
```

---

### Task 2: Wire tracking into controllers

**Files:**
- Modify: `src/Controllers/ShopController.php`
- Modify: `src/Controllers/HomeController.php`

**Interfaces:**
- Consumes: `RecentlyViewed::track(string $sku): void`,
  `RecentlyViewed::items(string $lang, ?string $exclude = null): array` (Task 1).
- Produces: `recently_viewed` template variable available in `shop/index.twig`,
  `shop/product.twig`, `home.twig` for Tasks 3 and 4.

No new tests — controllers stay untested per project convention
(`.claude/rules/unit-testing.md`); verified manually in Task 6.

- [ ] **Step 1: Update `ShopController`**

In `src/Controllers/ShopController.php`, add the import next to the existing ones:

```php
use App\Services\Compare;
use App\Services\RecentlyViewed;
use App\Services\Wishlist;
```

Replace the `index()` method body's return array:

```php
        return $this->render($request, $response, 'public/shop/index.twig', [
            'categories'      => CategoryModel::allWithTranslation($lang),
            'products'        => ProductModel::allActive($lang, $categoryId),
            'active_cat'      => $categoryId,
            'wishlist_skus'   => Wishlist::skus(),
            'compare_skus'    => Compare::skus(),
            'recently_viewed' => RecentlyViewed::items($lang),
        ]);
```

Replace the `product()` method:

```php
    public function product(Request $request, Response $response, array $args): Response
    {
        $lang    = $request->getAttribute('lang');
        $product = ProductModel::findBySku($args['slug'], $lang);

        if (!$product) {
            return $response->withStatus(404);
        }

        RecentlyViewed::track($product['sku']);

        $subtypePrices = array_column($product['subtypes'], 'price');

        return $this->render($request, $response, 'public/shop/product.twig', [
            'product'           => $product,
            'min_subtype_price' => $subtypePrices ? min($subtypePrices) : null,
            'max_subtype_price' => $subtypePrices ? max($subtypePrices) : null,
            'in_wishlist'       => Wishlist::has($product['sku']),
            'in_compare'        => Compare::has($product['sku']),
            'recently_viewed'   => RecentlyViewed::items($lang, $product['sku']),
        ]);
    }
```

- [ ] **Step 2: Update `HomeController`**

In `src/Controllers/HomeController.php`, add the import:

```php
use App\Models\PageModel;
use App\Services\RecentlyViewed;
```

Replace the `index()` method body's return array:

```php
        return $this->render($request, $response, 'public/home.twig', [
            'page'            => PageModel::find('home', $lang),
            'recently_viewed' => RecentlyViewed::items($lang),
        ]);
```

- [ ] **Step 3: Verify the app still boots**

Run: `php -l src/Controllers/ShopController.php && php -l src/Controllers/HomeController.php`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 4: Commit**

```bash
git add src/Controllers/ShopController.php src/Controllers/HomeController.php
git commit -m "feat: track and pass recently viewed products from controllers"
```

---

### Task 3: Recently-viewed row partial (product page + homepage)

**Files:**
- Create: `templates/public/partials/recently-viewed-row.twig`
- Modify: `templates/public/shop/product.twig`
- Modify: `templates/public/home.twig`
- Modify: `www/assets/css/style.css`

**Interfaces:**
- Consumes: `recently_viewed` (array of product rows, each with `sku`, `name`,
  `price`, `images` — the same shape `ProductModel::findBySku()` returns, as used
  today in `templates/public/compare.twig`), `lang` (both already injected into every
  template by `BaseController::render()`).

No unit tests — Twig templates/CSS are verified by rendering locally
(`.claude/rules/unit-testing.md`), done in Task 6.

- [ ] **Step 1: Create the partial**

Create `templates/public/partials/recently-viewed-row.twig`:

```twig
{% if recently_viewed %}
<div class="container recently-viewed-section">
    <h2 class="recently-viewed-heading">{{ t('shop.recently_viewed_title') }}</h2>
    <div class="recently-viewed-row">
        {% for product in recently_viewed %}
        <a href="/{{ lang }}/shop/{{ product.sku }}" class="recently-viewed-card">
            <div class="product-img">
                {% if product.images[0] %}
                    <img src="/assets/uploads/products/{{ product.images[0] }}" alt="{{ product.name }}">
                {% else %}
                    <div class="product-img-placeholder"></div>
                {% endif %}
            </div>
            <p class="recently-viewed-name">{{ product.name }}</p>
            <p class="recently-viewed-price">{{ product.price|number_format(2, '.', ' ') }} Kč</p>
        </a>
        {% endfor %}
    </div>
</div>
{% endif %}
```

- [ ] **Step 2: Include it in the product detail page**

In `templates/public/shop/product.twig`, the file currently ends with:

```twig
{% if product.legal_notice %}
<div class="container product-specs">
    <div class="specs-scroll">
        <table class="specs-table">
            <tbody>
                <tr class="specs-row-header">
                    <td colspan="2" data-label="">{{ t('shop.notice_title') }}</td>
                </tr>
                <tr>
                    <td data-label="{{ t('shop.specs_attribute_name') }}">{{ t('shop.notice_legal_label') }}</td>
                    <td data-label="{{ t('shop.specs_attribute_value') }}">{{ product.legal_notice }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
{% endif %}
{% endblock %}
```

Replace the last two lines (`{% endif %}` / `{% endblock %}`) with:

```twig
{% endif %}

{% include 'public/partials/recently-viewed-row.twig' %}
{% endblock %}
```

- [ ] **Step 3: Include it on the homepage**

In `templates/public/home.twig`, the file currently is:

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ page.meta_title ?? t('home.hero_title') }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{{ page.meta_desc ?? '' }}{% endblock %}

{% block content %}
<section class="hero">
    <div class="container">
        <h1>{{ t('home.hero_title') }}</h1>
        <p class="hero-subtitle">{{ t('home.hero_subtitle') }}</p>
        <a href="/{{ lang }}/shop" class="btn btn-primary">{{ t('home.cta') }}</a>
    </div>
</section>
{% endblock %}
```

Replace the final two lines (`</section>` / `{% endblock %}`) with:

```twig
</section>

{% include 'public/partials/recently-viewed-row.twig' %}
{% endblock %}
```

- [ ] **Step 4: Add CSS**

In `www/assets/css/style.css`, after the `.product-price` rule (the block ending
`.product-price { font-family: var(--ui-font); color: var(--accent); font-size: .95rem; }`),
add:

```css

/* Recently viewed products (row: product page + homepage) */
.recently-viewed-section { padding: 2.5rem 1.5rem; }
.recently-viewed-heading { font-size: 1.4rem; font-weight: normal; margin-bottom: 1rem; }
.recently-viewed-row { display: flex; gap: 1.25rem; overflow-x: auto; padding-bottom: .5rem; -webkit-overflow-scrolling: touch; }
.recently-viewed-card { flex: 0 0 160px; text-decoration: none; color: var(--text); }
.recently-viewed-card .product-img { border-radius: 2px; }
.recently-viewed-name { font-size: .9rem; margin-top: .5rem; }
.recently-viewed-price { font-family: var(--ui-font); color: var(--accent); font-size: .85rem; }
```

In the existing `@media (max-width: 768px)` block titled
`/* Responsive: shop sidebar & product/gallery grids */`, add one line after the
`.product-grid` rule:

```css
    .product-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 1rem; }
    .recently-viewed-card { flex-basis: 130px; }
```

- [ ] **Step 5: Commit**

```bash
git add templates/public/partials/recently-viewed-row.twig templates/public/shop/product.twig templates/public/home.twig www/assets/css/style.css
git commit -m "feat: render recently viewed row on product page and homepage"
```

---

### Task 4: Recently-viewed sidebar partial (shop listing)

**Files:**
- Create: `templates/public/partials/recently-viewed-sidebar.twig`
- Modify: `templates/public/shop/index.twig`
- Modify: `www/assets/css/style.css`

**Interfaces:**
- Consumes: same `recently_viewed` shape as Task 3.

This task also fixes a layout conflict: the existing `.shop-sidebar` mobile CSS
(`flex-direction: row` + horizontal scroll) assumes every direct child is a
`.cat-filter` pill. Adding a second, differently-shaped box (heading + stacked list)
as a sibling would get squashed into that same horizontal pill row. The fix: move the
category-links-specific flex/scroll behavior onto a new `.cat-filter-list` wrapper
around just the category links, leaving `.shop-sidebar` itself a plain vertical
stack of boxes (category box, recently-viewed box) at every breakpoint.

No unit tests — verified by rendering locally, done in Task 6.

- [ ] **Step 1: Create the partial**

Create `templates/public/partials/recently-viewed-sidebar.twig`:

```twig
{% if recently_viewed %}
<div class="sidebar-box recently-viewed-sidebar">
    <h2 class="sidebar-box-heading">{{ t('shop.recently_viewed_title') }}</h2>
    {% for product in recently_viewed %}
    <a href="/{{ lang }}/shop/{{ product.sku }}" class="recently-viewed-sidebar-item">
        <div class="recently-viewed-sidebar-thumb">
            {% if product.images[0] %}
                <img src="/assets/uploads/products/{{ product.images[0] }}" alt="{{ product.name }}">
            {% else %}
                <div class="product-img-placeholder"></div>
            {% endif %}
        </div>
        <span>{{ product.name }}</span>
    </a>
    {% endfor %}
</div>
{% endif %}
```

- [ ] **Step 2: Restructure the shop sidebar**

In `templates/public/shop/index.twig`, replace:

```twig
    {% if categories %}
    <aside class="shop-sidebar">
        <a href="/{{ lang }}/shop" class="cat-filter {{ active_cat is null ? 'active' : '' }}">
            {{ t('shop.all') }}
        </a>
        {% for cat in categories %}
        <a href="/{{ lang }}/shop?category={{ cat.id }}"
           class="cat-filter {{ active_cat == cat.id ? 'active' : '' }}">
            {{ cat.name }}
        </a>
        {% endfor %}
    </aside>
    {% endif %}
```

with:

```twig
    {% if categories or recently_viewed %}
    <aside class="shop-sidebar">
        {% if categories %}
        <div class="cat-filter-list">
            <a href="/{{ lang }}/shop" class="cat-filter {{ active_cat is null ? 'active' : '' }}">
                {{ t('shop.all') }}
            </a>
            {% for cat in categories %}
            <a href="/{{ lang }}/shop?category={{ cat.id }}"
               class="cat-filter {{ active_cat == cat.id ? 'active' : '' }}">
                {{ cat.name }}
            </a>
            {% endfor %}
        </div>
        {% endif %}
        {% include 'public/partials/recently-viewed-sidebar.twig' %}
    </aside>
    {% endif %}
```

- [ ] **Step 3: Update the sidebar CSS**

In `www/assets/css/style.css`, replace:

```css
/* Shop layout */
.shop-layout { display: grid; grid-template-columns: 200px 1fr; gap: 2.5rem; padding: 2.5rem 1.5rem; }
.shop-sidebar { display: flex; flex-direction: column; gap: .5rem; }
.cat-filter { display: block; padding: .5rem .75rem; color: var(--text); text-decoration: none; font-family: var(--ui-font); font-size: .9rem; border-radius: 2px; }
.cat-filter:hover, .cat-filter.active { background: var(--border); color: var(--accent); }
```

with:

```css
/* Shop layout */
.shop-layout { display: grid; grid-template-columns: 200px 1fr; gap: 2.5rem; padding: 2.5rem 1.5rem; }
.shop-sidebar { display: flex; flex-direction: column; gap: 1.5rem; }
.cat-filter-list { display: flex; flex-direction: column; gap: .5rem; }
.cat-filter { display: block; padding: .5rem .75rem; color: var(--text); text-decoration: none; font-family: var(--ui-font); font-size: .9rem; border-radius: 2px; }
.cat-filter:hover, .cat-filter.active { background: var(--border); color: var(--accent); }

/* Sidebar boxes (recently viewed, future sidebar widgets) */
.sidebar-box-heading { font-size: .85rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); margin-bottom: .75rem; }
.recently-viewed-sidebar-item { display: flex; align-items: center; gap: .6rem; padding: .35rem 0; text-decoration: none; color: var(--text); font-size: .85rem; }
.recently-viewed-sidebar-item:hover { color: var(--accent); }
.recently-viewed-sidebar-thumb { width: 44px; height: 44px; flex-shrink: 0; border-radius: 2px; overflow: hidden; background: var(--border); }
.recently-viewed-sidebar-thumb img { width: 100%; height: 100%; object-fit: cover; }
```

Then, in the `@media (max-width: 768px)` block titled
`/* Responsive: shop sidebar & product/gallery grids */`, replace:

```css
    .shop-sidebar {
        flex-direction: row; overflow-x: auto; gap: .5rem; padding-bottom: .5rem;
        -webkit-overflow-scrolling: touch;
    }
```

with:

```css
    .cat-filter-list {
        flex-direction: row; overflow-x: auto; gap: .5rem; padding-bottom: .5rem;
        -webkit-overflow-scrolling: touch;
    }
```

- [ ] **Step 4: Commit**

```bash
git add templates/public/partials/recently-viewed-sidebar.twig templates/public/shop/index.twig www/assets/css/style.css
git commit -m "feat: render recently viewed sidebar box on shop listing"
```

---

### Task 5: Translations

**Files:**
- Modify: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`

**Interfaces:**
- Produces: `shop.recently_viewed_title`, used by both partials from Tasks 3 and 4.

All five files currently have, at lines 86-87:

```json
  "shop.qty": "...",
  "shop.specs_attribute_name": "...",
```

- [ ] **Step 1: Add the key to `lang/cs.json`**

Replace:

```json
  "shop.qty": "Množství",
  "shop.specs_attribute_name": "Název atributu",
```

with:

```json
  "shop.qty": "Množství",
  "shop.recently_viewed_title": "Naposledy zobrazené produkty",
  "shop.specs_attribute_name": "Název atributu",
```

- [ ] **Step 2: Add the key to `lang/en.json`**

Replace:

```json
  "shop.qty": "Quantity",
  "shop.specs_attribute_name": "Attribute name",
```

with:

```json
  "shop.qty": "Quantity",
  "shop.recently_viewed_title": "Recently viewed products",
  "shop.specs_attribute_name": "Attribute name",
```

- [ ] **Step 3: Add the key to `lang/ru.json`**

Replace:

```json
  "shop.qty": "Количество",
  "shop.specs_attribute_name": "Название атрибута",
```

with:

```json
  "shop.qty": "Количество",
  "shop.recently_viewed_title": "Недавно просмотренные товары",
  "shop.specs_attribute_name": "Название атрибута",
```

- [ ] **Step 4: Add the key to `lang/uk.json`**

Replace:

```json
  "shop.qty": "Кількість",
  "shop.specs_attribute_name": "Назва атрибута",
```

with:

```json
  "shop.qty": "Кількість",
  "shop.recently_viewed_title": "Нещодавно переглянуті товари",
  "shop.specs_attribute_name": "Назва атрибута",
```

- [ ] **Step 5: Add the key to `lang/sk.json`**

Replace:

```json
  "shop.qty": "Množstvo",
  "shop.specs_attribute_name": "Názov atribútu",
```

with:

```json
  "shop.qty": "Množstvo",
  "shop.recently_viewed_title": "Naposledy zobrazené produkty",
  "shop.specs_attribute_name": "Názov atribútu",
```

- [ ] **Step 6: Verify all five files still parse and have identical key sets**

Run:
```bash
for f in cs en ru uk sk; do php -r "json_decode(file_get_contents('lang/$f.json'), true) === null && exit(1);" || echo "INVALID: $f"; done
php -r '
$keys = null;
foreach (["cs","en","ru","uk","sk"] as $l) {
    $k = array_keys(json_decode(file_get_contents("lang/$l.json"), true));
    sort($k);
    if ($keys === null) { $keys = $k; continue; }
    if ($k !== $keys) { echo "MISMATCH: $l\n"; }
}
echo "done\n";
'
```
Expected: no `INVALID:`/`MISMATCH:` lines, ending in `done`.

- [ ] **Step 7: Commit**

```bash
git add lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json
git commit -m "feat: add recently viewed products translation key to all languages"
```

---

### Task 6: Full verification

**Files:** none (verification only)

- [ ] **Step 1: Run the whole test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests pass, including the 9 new `RecentlyViewedTest` tests.

- [ ] **Step 2: Start the local server**

Run: `docker compose up -d` (if not already running), then
`php -S localhost:8080 -t www` in the background.

- [ ] **Step 3: Manually verify the product page**

- Visit `http://localhost:8080/en/shop` and open two or three different products in
  turn.
- On each product page, confirm a "Recently viewed products" row appears below the
  specs section listing the *other* previously-viewed products (never the one
  currently open).
- Re-open the first product again and confirm it moves back to the front of the list
  on a subsequent page (order is most-recent-first).

- [ ] **Step 4: Manually verify the shop sidebar**

- Visit `http://localhost:8080/en/shop`.
- Confirm the sidebar shows the category filter list *and*, below it, a "Recently
  viewed products" box with thumbnails from the products visited in Step 3.
- Resize the browser below 768px width and confirm the category pills scroll
  horizontally as before, while the recently-viewed box still stacks normally below
  them (not squashed into the horizontal scroller).

- [ ] **Step 5: Manually verify the homepage**

- Visit `http://localhost:8080/en/`.
- Confirm the recently viewed row renders below the hero section.
- Clear the `recently_viewed` cookie via browser devtools and reload; confirm the
  section disappears entirely (no empty heading/row).

- [ ] **Step 6: Confirm cookie persistence**

- In browser devtools, inspect the `recently_viewed` cookie: confirm it has an
  expiry roughly 90 days out, `HttpOnly` set, `Path=/`.

No commit for this task — it's verification only. If any step surfaces a bug, fix it
as a follow-up commit referencing the specific broken behavior.
