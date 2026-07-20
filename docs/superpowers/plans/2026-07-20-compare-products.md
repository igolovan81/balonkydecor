# Compare Products Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let visitors add up to 4 products to a session-backed compare list and view them side by side (name, price, merged specs) on a dedicated `/compare` page.

**Architecture:** Mirror the existing `Wishlist` feature end to end — a static session-backed `Compare` service, a `CompareController` with `index`/`toggle`/`clear`, POST-redirect-GET routes under `/{lang}/compare`, and toggle buttons wired into the existing shop templates. The one new piece of infrastructure is a small public flash-message mechanism on `BaseController` (copied from `AdminBaseController`'s), needed to surface the "list is full" message across a redirect.

**Tech Stack:** PHP 8 / Slim 4, Twig 3.8 (arrow-function filters available), PDO/MySQL, PHPUnit 11, vanilla CSS (no build step).

## Global Constraints

- No new DB table — compare list lives in `$_SESSION['compare']`, same as `Wishlist`/`Cart`.
- Compare list capped at **4** SKUs; toggling a 5th distinct SKU is rejected, not evicting.
- Products have no fixed spec columns — specs come from `product_specs`/`product_spec_t` as `{id, attribute_name, attribute_value}` rows (see `ProductModel::findBySku()`); the comparison table row set is the union of `attribute_name` values across compared products.
- All public-facing strings go through `t('key')`; every new key is added to **all five** `lang/{cs,en,ru,uk,sk}.json` files, alphabetically sorted (matches existing file convention — keys are flat and sorted).
- Public links are `/{{ lang }}/...`; state-changing actions are `POST` + 302 redirect (PRG), never GET.
- Run `php vendor/bin/phpunit` (whole suite) before every commit — it must be fully green.
- Controllers are untested per project convention (`.claude/rules/unit-testing.md`) — only the `Compare` service gets unit tests; controller/template wiring is verified manually via `/start` + browser.
- `docker compose up -d` must be running (real MySQL, no mocks) for the `Compare` service tests.

---

### Task 1: `Compare` service (session-backed compare list)

**Files:**
- Create: `src/Services/Compare.php`
- Test: `tests/Unit/Services/CompareTest.php`

**Interfaces:**
- Consumes: `App\Models\Database::getConnection()` (test fixtures only), `App\Models\ProductModel::findBySku(string $sku, string $lang): ?array` (existing).
- Produces (used by Tasks 4 and 5):
  - `Compare::toggle(string $sku): array` — returns `['added' => bool, 'full' => bool]`.
  - `Compare::has(string $sku): bool`
  - `Compare::skus(): array`
  - `Compare::count(): int`
  - `Compare::clear(): void`
  - `Compare::items(string $lang): array` — array of product rows as returned by `ProductModel::findBySku()`.

- [ ] **Step 1: Write the failing test file**

Create `tests/Unit/Services/CompareTest.php`:

```php
<?php
namespace Tests\Unit\Services;

use App\Models\Database;
use App\Services\Compare;
use PHPUnit\Framework\TestCase;

class CompareTest extends TestCase
{
    private static int $categoryId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO categories (slug) VALUES ('test-compare')");
        $row = $pdo->query("SELECT id FROM categories WHERE slug='test-compare'")->fetch();
        self::$categoryId = (int) $row['id'];

        $pdo->exec("INSERT IGNORE INTO products (category_id, sku, price) VALUES (" . self::$categoryId . ", 'TEST-COMPARE-SKU-001', 29.90)");
        $id = $pdo->query("SELECT id FROM products WHERE sku='TEST-COMPARE-SKU-001'")->fetch()['id'];
        $pdo->exec("INSERT IGNORE INTO product_t (product_id, lang_code, name) VALUES ({$id}, 'en', 'Compare Test Balloon')");
    }

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['compare'] = [];
    }

    public function test_toggle_adds_sku_and_returns_added_true(): void
    {
        $result = Compare::toggle('SKU-1');
        $this->assertSame(['added' => true, 'full' => false], $result);
        $this->assertSame(['SKU-1'], Compare::skus());
    }

    public function test_toggle_removes_sku_and_returns_added_false(): void
    {
        Compare::toggle('SKU-1');
        $result = Compare::toggle('SKU-1');
        $this->assertSame(['added' => false, 'full' => false], $result);
        $this->assertSame([], Compare::skus());
    }

    public function test_has_reflects_current_state(): void
    {
        $this->assertFalse(Compare::has('SKU-1'));
        Compare::toggle('SKU-1');
        $this->assertTrue(Compare::has('SKU-1'));
    }

    public function test_count_matches_number_of_saved_skus(): void
    {
        Compare::toggle('SKU-1');
        Compare::toggle('SKU-2');
        $this->assertSame(2, Compare::count());
    }

    public function test_count_is_zero_for_empty_compare_list(): void
    {
        $this->assertSame(0, Compare::count());
    }

    public function test_toggle_rejects_fifth_distinct_sku_when_full(): void
    {
        Compare::toggle('SKU-1');
        Compare::toggle('SKU-2');
        Compare::toggle('SKU-3');
        Compare::toggle('SKU-4');

        $result = Compare::toggle('SKU-5');

        $this->assertSame(['added' => false, 'full' => true], $result);
        $this->assertSame(['SKU-1', 'SKU-2', 'SKU-3', 'SKU-4'], Compare::skus());
    }

    public function test_toggle_succeeds_after_removing_one_to_free_a_slot(): void
    {
        Compare::toggle('SKU-1');
        Compare::toggle('SKU-2');
        Compare::toggle('SKU-3');
        Compare::toggle('SKU-4');
        Compare::toggle('SKU-1'); // remove, frees a slot

        $result = Compare::toggle('SKU-5');

        $this->assertSame(['added' => true, 'full' => false], $result);
        $this->assertSame(['SKU-2', 'SKU-3', 'SKU-4', 'SKU-5'], Compare::skus());
    }

    public function test_clear_empties_the_list(): void
    {
        Compare::toggle('SKU-1');
        Compare::toggle('SKU-2');
        Compare::clear();
        $this->assertSame([], Compare::skus());
        $this->assertSame(0, Compare::count());
    }

    public function test_items_hydrates_saved_product(): void
    {
        Compare::toggle('TEST-COMPARE-SKU-001');
        $items = Compare::items('en');
        $this->assertCount(1, $items);
        $this->assertSame('TEST-COMPARE-SKU-001', $items[0]['sku']);
        $this->assertSame('Compare Test Balloon', $items[0]['name']);
    }

    public function test_items_skips_sku_that_no_longer_resolves(): void
    {
        Compare::toggle('TEST-COMPARE-SKU-001');
        Compare::toggle('SKU-DOES-NOT-EXIST');
        $items = Compare::items('en');
        $skus  = array_column($items, 'sku');
        $this->assertSame(['TEST-COMPARE-SKU-001'], $skus);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose up -d && php vendor/bin/phpunit tests/Unit/Services/CompareTest.php`
Expected: FAIL — `Class "App\Services\Compare" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Services/Compare.php`:

```php
<?php
namespace App\Services;

use App\Models\ProductModel;

class Compare
{
    private const MAX_ITEMS = 4;

    private static function boot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['compare'])) {
            $_SESSION['compare'] = [];
        }
    }

    public static function toggle(string $sku): array
    {
        self::boot();
        $key = array_search($sku, $_SESSION['compare'], true);
        if ($key !== false) {
            unset($_SESSION['compare'][$key]);
            $_SESSION['compare'] = array_values($_SESSION['compare']);
            return ['added' => false, 'full' => false];
        }
        if (count($_SESSION['compare']) >= self::MAX_ITEMS) {
            return ['added' => false, 'full' => true];
        }
        $_SESSION['compare'][] = $sku;
        return ['added' => true, 'full' => false];
    }

    public static function has(string $sku): bool
    {
        self::boot();
        return in_array($sku, $_SESSION['compare'], true);
    }

    public static function skus(): array
    {
        self::boot();
        return $_SESSION['compare'];
    }

    public static function count(): int
    {
        self::boot();
        return count($_SESSION['compare']);
    }

    public static function clear(): void
    {
        self::boot();
        $_SESSION['compare'] = [];
    }

    public static function items(string $lang): array
    {
        self::boot();
        $items = [];
        foreach ($_SESSION['compare'] as $sku) {
            $product = ProductModel::findBySku($sku, $lang);
            if ($product !== null) {
                $items[] = $product;
            }
        }
        return $items;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Services/CompareTest.php --testdox`
Expected: PASS, all 10 tests green.

- [ ] **Step 5: Run the full suite and commit**

Run: `php vendor/bin/phpunit`
Expected: PASS (no regressions).

```bash
git add src/Services/Compare.php tests/Unit/Services/CompareTest.php
git commit -m "feat: add Compare service for session-backed compare list"
```

---

### Task 2: Public flash messages + `compare_count` on `BaseController`

**Files:**
- Modify: `src/Controllers/BaseController.php`

**Interfaces:**
- Consumes: `App\Services\Compare::count(): int` (Task 1).
- Produces (used by Task 3 templates, and any future public controller): `BaseController::flash(string $type, string $message, array $params = []): void` (protected, callable by subclasses), plus `flash` and `compare_count` keys injected into every template rendered via `render()`.

- [ ] **Step 1: Modify `BaseController.php`**

Replace the full contents of `src/Controllers/BaseController.php`:

```php
<?php
namespace App\Controllers;

use App\Models\Database;
use App\Services\Compare;
use App\Services\I18n;
use App\Services\Seo;
use App\Twig\I18nExtension;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

abstract class BaseController
{
    public function __construct(protected Twig $twig) {}

    protected function render(
        Request  $request,
        Response $response,
        string   $template,
        array    $data = []
    ): Response {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        /** @var I18n $i18n */
        $i18n = $request->getAttribute('i18n');
        $lang = $request->getAttribute('lang');

        $env = $this->twig->getEnvironment();
        if (!$env->hasExtension(I18nExtension::class)) {
            $env->addExtension(new I18nExtension($i18n));
        }

        $uri  = $request->getUri()->getPath();
        $path = preg_replace('#^/' . preg_quote($lang, '#') . '#', '', $uri) ?: '/';

        $pdo          = Database::getConnection();
        $settingsStmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('contact_phone','contact_email','facebook_url','instagram_url','whatsapp_phone')");
        $settingsStmt->execute();
        $settingsMap = array_column($settingsStmt->fetchAll(), 'value', 'key');

        $whatsappDigits = preg_replace('/\D+/', '', $settingsMap['whatsapp_phone'] ?? '');

        return $this->twig->render($response, $template, array_merge([
            'lang'                 => $lang,
            'current_path'         => $path,
            'base_url'             => Seo::BASE_URL,
            'canonical_url'        => Seo::canonicalUrl($lang, $path),
            'alternate_urls'       => Seo::alternateUrls($path),
            'organization_json_ld' => Seo::organizationJsonLd(
                $i18n->t('site.name'),
                $settingsMap['contact_phone'] ?? '',
                $settingsMap['contact_email'] ?? ''
            ),
            'facebook_url'         => $settingsMap['facebook_url'] ?? '',
            'instagram_url'        => $settingsMap['instagram_url'] ?? '',
            'whatsapp_url'         => $whatsappDigits !== '' ? 'https://wa.me/' . $whatsappDigits : '',
            'flash'                => $this->getFlash(),
            'compare_count'        => Compare::count(),
        ], $data));
    }

    protected function flash(string $type, string $message, array $params = []): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['flash'] = ['type' => $type, 'message' => $message, 'params' => $params];
    }

    protected function getFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $flash;
    }
}
```

- [ ] **Step 2: Run the full test suite**

Run: `php vendor/bin/phpunit`
Expected: PASS (no regressions — no existing test touches `BaseController` directly, but every public page render depends on it, so this step catches any breakage).

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/BaseController.php
git commit -m "feat: add public flash messages and compare_count to BaseController"
```

---

### Task 3: `base.twig` flash banner + compare nav link, plus their CSS

**Files:**
- Modify: `templates/layout/base.twig`
- Modify: `www/assets/css/style.css`

**Interfaces:**
- Consumes: `flash` and `compare_count` template variables (Task 2), `t('nav.compare')` (Task 8 — key won't resolve until Task 8, but `I18n::t()` falls back to the raw key string if missing, so the page still renders during intermediate manual checks).

- [ ] **Step 1: Add the flash banner and compare nav link to `base.twig`**

In `templates/layout/base.twig`, change line 38 (the wishlist nav link) so a compare link follows it:

```twig
            <a href="/{{ lang }}/wishlist" class="wishlist-link">{{ t('nav.wishlist') }}</a>
            <a href="/{{ lang }}/compare" class="compare-link">{{ t('nav.compare') }}{% if compare_count %} ({{ compare_count }}){% endif %}</a>
            <a href="/{{ lang }}/cart" class="cart-link">{{ t('nav.cart') }}</a>
```

Then insert a flash banner between `</header>` and `<main>` (originally lines 50–52):

```twig
    </header>

    {% if flash %}
    <div class="container">
        <div class="flash-{{ flash.type }}">{{ t(flash.message, flash.params ?? []) }}</div>
    </div>
    {% endif %}

    <main>{% block content %}{% endblock %}</main>
```

- [ ] **Step 2: Add CSS for the compare link and flash banner**

In `www/assets/css/style.css`, extend the existing cart/wishlist link rules (lines 50–51) to include `.compare-link`:

```css
.cart-link, .wishlist-link, .compare-link { order: 3; color: var(--text); text-decoration: none; font-family: var(--ui-font); font-size: .9rem; letter-spacing: .03em; }
.cart-link:hover, .wishlist-link:hover, .compare-link:hover { color: var(--accent); }
```

And in the `@media (max-width: 768px)` header block (line 60), extend the same selector:

```css
    .cart-link, .wishlist-link, .compare-link { order: 2; margin-left: auto; }
```

Then add a new block right after that media query closes (after line 74, before the `/* Hero */` comment):

```css
/* Flash messages (mirrors admin.css) */
.flash-success { background: #d4edda; color: #155724; padding: .75rem 1rem; border-radius: 4px; margin-top: 1rem; }
.flash-error   { background: #f8d7da; color: #721c24; padding: .75rem 1rem; border-radius: 4px; margin-top: 1rem; }
```

- [ ] **Step 3: Manually verify no visual breakage**

Run: `php -S localhost:8080 -t www` (in a background/separate terminal), then `curl -s http://localhost:8080/en/ | grep -o 'compare-link[^<]*'`
Expected: `compare-link">nav.compare` — `I18n::t()` falls back to the raw key string for missing translations (see `src/Services/I18n.php:18`), so this is correct until Task 8 adds the real translation.

- [ ] **Step 4: Run the full test suite and commit**

Run: `php vendor/bin/phpunit`
Expected: PASS.

```bash
git add templates/layout/base.twig www/assets/css/style.css
git commit -m "feat: add flash banner and compare nav link to public layout"
```

---

### Task 4: `CompareController` + routes

**Files:**
- Create: `src/Controllers/CompareController.php`
- Modify: `src/routes.php`

**Interfaces:**
- Consumes: `Compare::items(string $lang): array`, `Compare::toggle(string $sku): array`, `Compare::clear(): void` (Task 1), `BaseController::render()` / `BaseController::flash()` (Task 2).
- Produces: routes `GET /{lang}/compare`, `POST /{lang}/compare/toggle`, `POST /{lang}/compare/clear`, used by Task 3's nav link and Task 6/7's toggle forms.

- [ ] **Step 1: Create `CompareController.php`**

Create `src/Controllers/CompareController.php`:

```php
<?php
namespace App\Controllers;

use App\Services\Compare;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CompareController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang  = $request->getAttribute('lang');
        $items = Compare::items($lang);

        $attributes = [];
        foreach ($items as $product) {
            foreach ($product['specs'] as $spec) {
                if (!in_array($spec['attribute_name'], $attributes, true)) {
                    $attributes[] = $spec['attribute_name'];
                }
            }
        }

        return $this->render($request, $response, 'public/compare.twig', [
            'items'      => $items,
            'attributes' => $attributes,
        ]);
    }

    public function toggle(Request $request, Response $response, array $args): Response
    {
        $lang   = $request->getAttribute('lang');
        $body   = (array) $request->getParsedBody();
        $sku    = trim($body['sku'] ?? '');
        $return = (string) ($body['return'] ?? '');

        if ($sku !== '') {
            $result = Compare::toggle($sku);
            if ($result['full']) {
                $this->flash('error', 'compare.full');
            }
        }

        $target = preg_match('#^/[a-z]{2}/#', $return) ? $return : "/{$lang}/compare";

        return $response->withHeader('Location', $target)->withStatus(302);
    }

    public function clear(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        Compare::clear();

        return $response->withHeader('Location', "/{$lang}/compare")->withStatus(302);
    }
}
```

- [ ] **Step 2: Register routes**

In `src/routes.php`, add the import next to the existing `WishlistController` import (after line 12):

```php
use App\Controllers\CompareController;
```

Then add the three routes next to the existing wishlist routes (after line 161, `POST /{lang}/wishlist/toggle`):

```php
$app->get('/{lang}/compare',         CompareController::class . ':index');
$app->post('/{lang}/compare/toggle', CompareController::class . ':toggle');
$app->post('/{lang}/compare/clear',  CompareController::class . ':clear');
```

- [ ] **Step 3: Manually verify the routes respond**

Run: `php -S localhost:8080 -t www` (separate terminal), then:
```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/en/compare
```
Expected: `200` (page renders even with an empty list and no `compare.twig` template yet would 500 — if this 500s because `public/compare.twig` doesn't exist yet, that's expected until Task 7; skip this check if so and re-run it after Task 7).

- [ ] **Step 4: Run the full test suite and commit**

Run: `php vendor/bin/phpunit`
Expected: PASS.

```bash
git add src/Controllers/CompareController.php src/routes.php
git commit -m "feat: add CompareController and compare routes"
```

---

### Task 5: `ShopController` wiring

**Files:**
- Modify: `src/Controllers/ShopController.php`

**Interfaces:**
- Consumes: `Compare::skus(): array`, `Compare::has(string $sku): bool` (Task 1).
- Produces: `compare_skus` (array of SKUs) passed to `public/shop/index.twig`, `in_compare` (bool) passed to `public/shop/product.twig` — consumed by Task 6.

- [ ] **Step 1: Modify `ShopController.php`**

Replace the full contents of `src/Controllers/ShopController.php`:

```php
<?php
namespace App\Controllers;

use App\Models\CategoryModel;
use App\Models\ProductModel;
use App\Services\Compare;
use App\Services\Wishlist;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ShopController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang       = $request->getAttribute('lang');
        $params     = $request->getQueryParams();
        $categoryId = isset($params['category']) ? (int) $params['category'] : null;

        return $this->render($request, $response, 'public/shop/index.twig', [
            'categories'    => CategoryModel::allWithTranslation($lang),
            'products'      => ProductModel::allActive($lang, $categoryId),
            'active_cat'    => $categoryId,
            'wishlist_skus' => Wishlist::skus(),
            'compare_skus'  => Compare::skus(),
        ]);
    }

    public function product(Request $request, Response $response, array $args): Response
    {
        $lang    = $request->getAttribute('lang');
        $product = ProductModel::findBySku($args['slug'], $lang);

        if (!$product) {
            return $response->withStatus(404);
        }

        $subtypePrices = array_column($product['subtypes'], 'price');

        return $this->render($request, $response, 'public/shop/product.twig', [
            'product'           => $product,
            'min_subtype_price' => $subtypePrices ? min($subtypePrices) : null,
            'max_subtype_price' => $subtypePrices ? max($subtypePrices) : null,
            'in_wishlist'       => Wishlist::has($product['sku']),
            'in_compare'        => Compare::has($product['sku']),
        ]);
    }
}
```

- [ ] **Step 2: Run the full test suite and commit**

Run: `php vendor/bin/phpunit`
Expected: PASS.

```bash
git add src/Controllers/ShopController.php
git commit -m "feat: pass compare state from ShopController to shop templates"
```

---

### Task 6: Compare toggle buttons on product card and detail page

**Files:**
- Modify: `templates/public/shop/index.twig`
- Modify: `templates/public/shop/product.twig`
- Modify: `www/assets/css/style.css`

**Interfaces:**
- Consumes: `compare_skus` (Task 5, shop index), `in_compare` (Task 5, product detail), routes from Task 4 (`POST /{lang}/compare/toggle`), translation keys `shop.compare_add` / `shop.compare_remove` (Task 8 — raw key renders until then).

- [ ] **Step 1: Add the toggle button to the product card grid**

In `templates/public/shop/index.twig`, change the `{% set in_wishlist %}` line (line 31) and the block right after the existing `.wishlist-toggle-form` (lines 50–56):

```twig
            {% set in_wishlist = product.sku in wishlist_skus %}
            {% set in_compare = product.sku in compare_skus %}
            <div class="product-card-wrap">
                <a href="/{{ lang }}/shop/{{ product.sku }}" class="product-card">
                    <div class="product-img">
                        {% if product.primary_image %}
                            <img src="/assets/uploads/products/{{ product.primary_image }}" alt="{{ product.name }}">
                        {% else %}
                            <div class="product-img-placeholder"></div>
                        {% endif %}
                    </div>
                    <div class="product-info">
                        <h3>{{ product.name }}</h3>
                        {% if product.min_subtype_price is not null %}
                        <p class="product-price">{{ t('shop.from_price', {price: product.min_subtype_price|number_format(2, '.', ' ')}) }}</p>
                        {% else %}
                        <p class="product-price">{{ product.price|number_format(2, '.', ' ') }} Kč</p>
                        {% endif %}
                    </div>
                </a>
                <form action="/{{ lang }}/wishlist/toggle" method="POST" class="wishlist-toggle-form">
                    <input type="hidden" name="sku" value="{{ product.sku }}">
                    <input type="hidden" name="return" value="/{{ lang }}{{ current_path }}">
                    <button type="submit" class="wishlist-toggle {{ in_wishlist ? 'active' : '' }}" aria-label="{{ in_wishlist ? t('shop.wishlist_remove') : t('shop.wishlist_add') }}">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                    </button>
                </form>
                <form action="/{{ lang }}/compare/toggle" method="POST" class="compare-toggle-form">
                    <input type="hidden" name="sku" value="{{ product.sku }}">
                    <input type="hidden" name="return" value="/{{ lang }}{{ current_path }}">
                    <button type="submit" class="compare-toggle {{ in_compare ? 'active' : '' }}">{{ in_compare ? t('shop.compare_remove') : t('shop.compare_add') }}</button>
                </form>
            </div>
```

- [ ] **Step 2: Add the toggle button to the product detail page**

In `templates/public/shop/product.twig`, insert a new form right after the `.product-detail-heading` block closes (after line 65, before the price paragraph):

```twig
        </div>
        <form action="/{{ lang }}/compare/toggle" method="POST" class="compare-toggle-form compare-toggle-form--detail">
            <input type="hidden" name="sku" value="{{ product.sku }}">
            <input type="hidden" name="return" value="/{{ lang }}{{ current_path }}">
            <button type="submit" class="compare-toggle {{ in_compare ? 'active' : '' }}">{{ in_compare ? t('shop.compare_remove') : t('shop.compare_add') }}</button>
        </form>
        {% if product.subtypes %}
```

(The closing `</div>` above is the existing `.product-detail-heading` close; the following `{% if product.subtypes %}` line already exists — this step inserts the new form between them.)

- [ ] **Step 3: Add CSS for the compare toggle button**

In `www/assets/css/style.css`, insert a new block right after `.wishlist-toggle-form--lg .wishlist-toggle { background: var(--border); }` (line 127) and before `.product-img { ... }` (line 128):

```css
/* Compare toggle (product card + detail page) */
.compare-toggle-form { display: block; margin-top: .5rem; text-align: center; }
.compare-toggle-form--detail { margin: 0 0 1.25rem; text-align: left; }
.compare-toggle { background: none; border: 1px solid var(--border); color: var(--text); font-family: var(--ui-font); font-size: .8rem; padding: .4rem .9rem; border-radius: 2px; cursor: pointer; }
.compare-toggle:hover { border-color: var(--accent); color: var(--accent); }
.compare-toggle.active { background: var(--accent); border-color: var(--accent); color: var(--text-inverse); }
```

- [ ] **Step 4: Run the full test suite and commit**

Run: `php vendor/bin/phpunit`
Expected: PASS.

```bash
git add templates/public/shop/index.twig templates/public/shop/product.twig www/assets/css/style.css
git commit -m "feat: add compare toggle buttons to product card and detail page"
```

---

### Task 7: `/compare` page template

**Files:**
- Create: `templates/public/compare.twig`
- Modify: `www/assets/css/style.css`

**Interfaces:**
- Consumes: `items` (array of product rows with `specs`), `attributes` (array of distinct attribute-name strings) from `CompareController::index()` (Task 4). Reuses `.spec-swatch` (existing, for hex-color spec values) and `.btn-remove` (existing, cart's red "×" button).

- [ ] **Step 1: Create `templates/public/compare.twig`**

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('compare.title') }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{% endblock %}

{% block head %}<meta name="robots" content="noindex,nofollow">{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container"><h1>{{ t('compare.title') }}</h1></div>
</section>

<div class="container compare-page">
    {% if items %}
    <form action="/{{ lang }}/compare/clear" method="POST" class="compare-clear-form">
        <button type="submit" class="btn btn-outline">{{ t('compare.clear_list') }}</button>
    </form>
    <div class="specs-scroll">
        <table class="compare-table">
            <thead>
                <tr>
                    <th class="compare-label-col"></th>
                    {% for product in items %}
                    <th>
                        <form action="/{{ lang }}/compare/toggle" method="POST" class="compare-remove-form">
                            <input type="hidden" name="sku" value="{{ product.sku }}">
                            <input type="hidden" name="return" value="/{{ lang }}/compare">
                            <button type="submit" class="btn-remove" aria-label="{{ t('shop.compare_remove') }}">×</button>
                        </form>
                        <a href="/{{ lang }}/shop/{{ product.sku }}">
                            {% if product.images[0] %}
                                <img src="/assets/uploads/products/{{ product.images[0] }}" alt="{{ product.name }}" class="compare-thumb">
                            {% else %}
                                <div class="product-img-placeholder compare-thumb"></div>
                            {% endif %}
                        </a>
                    </th>
                    {% endfor %}
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="compare-label-col">{{ t('compare.name') }}</td>
                    {% for product in items %}
                    <td><a href="/{{ lang }}/shop/{{ product.sku }}">{{ product.name }}</a></td>
                    {% endfor %}
                </tr>
                <tr>
                    <td class="compare-label-col">{{ t('compare.price') }}</td>
                    {% for product in items %}
                    <td>{{ product.price|number_format(2, '.', ' ') }} Kč</td>
                    {% endfor %}
                </tr>
                {% for attribute in attributes %}
                <tr>
                    <td class="compare-label-col">{{ attribute }}</td>
                    {% for product in items %}
                    {% set spec = product.specs|filter(s => s.attribute_name == attribute)|first %}
                    <td>
                        {% if spec %}
                            {% if spec.attribute_value matches '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/' %}
                            <span class="spec-swatch" style="background:{{ spec.attribute_value }};" aria-label="{{ spec.attribute_value }}"></span>
                            {% else %}
                            {{ spec.attribute_value }}
                            {% endif %}
                        {% endif %}
                    </td>
                    {% endfor %}
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
    {% else %}
    <p class="empty-state">{{ t('compare.empty') }}</p>
    {% endif %}
</div>
{% endblock %}
```

- [ ] **Step 2: Add CSS for the compare table**

In `www/assets/css/style.css`, insert a new block right after `.spec-swatch { ... }` (line 166) and before the `/* Services cards */` comment (line 168):

```css
/* Compare table */
.compare-page { padding: 2.5rem 1.5rem; }
.compare-clear-form { text-align: right; margin-bottom: 1rem; }
.compare-table { width: 100%; border-collapse: collapse; font-family: var(--ui-font); font-size: .9rem; }
.compare-table th, .compare-table td { padding: .75rem .9rem; border: 1px solid var(--border); vertical-align: top; text-align: left; }
.compare-table th { background: var(--surface-warm); }
.compare-label-col { width: 140px; font-weight: 600; color: var(--muted); white-space: nowrap; }
.compare-thumb { width: 100px; height: 100px; object-fit: cover; display: block; margin: 0 auto; border-radius: 2px; background: var(--border); }
.compare-remove-form { text-align: right; margin-bottom: .5rem; }
```

- [ ] **Step 3: Manually verify the page renders**

Run: `php -S localhost:8080 -t www` (separate terminal), then:
```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/en/compare
```
Expected: `200`.

- [ ] **Step 4: Run the full test suite and commit**

Run: `php vendor/bin/phpunit`
Expected: PASS.

```bash
git add templates/public/compare.twig www/assets/css/style.css
git commit -m "feat: add compare products page"
```

---

### Task 8: Translations (all 5 languages)

**Files:**
- Modify: `lang/cs.json`
- Modify: `lang/en.json`
- Modify: `lang/sk.json`
- Modify: `lang/uk.json`
- Modify: `lang/ru.json`

**Interfaces:**
- Produces: `nav.compare`, `compare.clear_list`, `compare.empty`, `compare.full`, `compare.name`, `compare.price`, `compare.title`, `shop.compare_add`, `shop.compare_remove` — consumed by Tasks 3, 4, 6, 7. (The design doc's key list also mentioned `compare.view_product`, but `compare.twig` links to the product page directly from the name and thumbnail — the wishlist's separate "view product" button doesn't apply to the compare table layout, so that key is dropped as unused; do not add it.)

- [ ] **Step 1: Add keys to `lang/cs.json`**

Insert `"nav.compare": "Porovnání",` alphabetically between `"nav.cart"` and `"nav.contact"` (i.e. right after line 43):

```json
  "nav.cart": "Košík",
  "nav.compare": "Porovnání",
  "nav.contact": "Kontakt",
```

Insert the `compare.*` group alphabetically between `checkout.title` and `contact.email` (i.e. right after line 26):

```json
  "checkout.title": "Pokladna",
  "compare.clear_list": "Vymazat seznam",
  "compare.empty": "Zatím nemáte žádné produkty k porovnání.",
  "compare.full": "Seznam porovnání je plný (max. 4 produkty).",
  "compare.name": "Název",
  "compare.price": "Cena",
  "compare.title": "Porovnání produktů",
  "contact.email": "E-mail",
```

Insert `shop.compare_add`/`shop.compare_remove` alphabetically between `shop.all` and `shop.from_price` (i.e. right after line 72):

```json
  "shop.all": "Vše",
  "shop.compare_add": "Přidat k porovnání",
  "shop.compare_remove": "Odebrat z porovnání",
  "shop.from_price": "od {price} Kč",
```

- [ ] **Step 2: Add keys to `lang/en.json`** (same insertion points)

```json
  "nav.cart": "Cart",
  "nav.compare": "Compare",
  "nav.contact": "Contact",
```
```json
  "checkout.title": "Checkout",
  "compare.clear_list": "Clear list",
  "compare.empty": "You have no products to compare yet.",
  "compare.full": "Compare list is full (max 4 products).",
  "compare.name": "Name",
  "compare.price": "Price",
  "compare.title": "Compare Products",
  "contact.email": "Email",
```
```json
  "shop.all": "All",
  "shop.compare_add": "Add to compare",
  "shop.compare_remove": "Remove from compare",
  "shop.from_price": "from {price} Kč",
```

- [ ] **Step 3: Add keys to `lang/sk.json`** (same insertion points)

```json
  "nav.cart": "Košík",
  "nav.compare": "Porovnanie",
  "nav.contact": "Kontakt",
```
```json
  "checkout.title": "Pokladňa",
  "compare.clear_list": "Vymazať zoznam",
  "compare.empty": "Zatiaľ nemáte žiadne produkty na porovnanie.",
  "compare.full": "Zoznam porovnania je plný (max. 4 produkty).",
  "compare.name": "Názov",
  "compare.price": "Cena",
  "compare.title": "Porovnanie produktov",
  "contact.email": "E-mail",
```
```json
  "shop.all": "Všetko",
  "shop.compare_add": "Pridať na porovnanie",
  "shop.compare_remove": "Odstrániť z porovnania",
  "shop.from_price": "od {price} Kč",
```

- [ ] **Step 4: Add keys to `lang/uk.json`** (same insertion points)

```json
  "nav.cart": "Кошик",
  "nav.compare": "Порівняння",
  "nav.contact": "Контакти",
```
```json
  "checkout.title": "Оформлення замовлення",
  "compare.clear_list": "Очистити список",
  "compare.empty": "У вас поки немає товарів для порівняння.",
  "compare.full": "Список порівняння заповнений (макс. 4 товари).",
  "compare.name": "Назва",
  "compare.price": "Ціна",
  "compare.title": "Порівняння товарів",
  "contact.email": "E-mail",
```
```json
  "shop.all": "Усі",
  "shop.compare_add": "Додати до порівняння",
  "shop.compare_remove": "Прибрати з порівняння",
  "shop.from_price": "від {price} Kč",
```

- [ ] **Step 5: Add keys to `lang/ru.json`** (same insertion points)

```json
  "nav.cart": "Корзина",
  "nav.compare": "Сравнение",
  "nav.contact": "Контакты",
```
```json
  "checkout.title": "Оформление заказа",
  "compare.clear_list": "Очистить список",
  "compare.empty": "У вас пока нет товаров для сравнения.",
  "compare.full": "Список сравнения заполнен (макс. 4 товара).",
  "compare.name": "Название",
  "compare.price": "Цена",
  "compare.title": "Сравнение товаров",
  "contact.email": "E-mail",
```
```json
  "shop.all": "Все",
  "shop.compare_add": "Добавить к сравнению",
  "shop.compare_remove": "Убрать из сравнения",
  "shop.from_price": "от {price} Kč",
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

- [ ] **Step 7: Run the full test suite and commit**

Run: `php vendor/bin/phpunit`
Expected: PASS.

```bash
git add lang/cs.json lang/en.json lang/sk.json lang/uk.json lang/ru.json
git commit -m "feat: add compare products translations to all 5 languages"
```

---

### Task 9: End-to-end manual verification

**Files:** none (verification only).

**Interfaces:** Exercises the full stack built in Tasks 1–8.

- [ ] **Step 1: Start the local stack**

Run: `docker compose up -d && php -S localhost:8080 -t www` (server in background/separate terminal).

- [ ] **Step 2: Verify the empty state**

Run: `curl -s http://localhost:8080/en/compare | grep -o 'You have no products to compare yet.'`
Expected: the string is found (empty state renders with the real translation).

- [ ] **Step 3: Verify the nav link renders with a live product SKU**

In a browser, visit `http://localhost:8080/en/shop`. Confirm:
- Each product card shows an "Add to compare" button below the wishlist heart.
- Clicking it re-renders the shop page with the button now reading "Remove from compare".
- The header nav shows "Compare (1)".

- [ ] **Step 4: Verify the 4-item cap and flash message**

Add 4 different products to compare from the shop grid, then attempt to add a 5th. Confirm:
- The 5th product's button stays "Add to compare" (not toggled).
- A red flash banner appears below the header reading "Compare list is full (max 4 products)."

- [ ] **Step 5: Verify the compare page table**

Visit `http://localhost:8080/en/compare`. Confirm:
- 4 columns, one per compared product, each with a "×" remove button and thumbnail/placeholder image.
- Name and Price rows are correct for each product.
- If any compared products have specs (check via `/admin/products` → edit a product → add a spec row, or use existing seeded products with specs), the spec attribute appears as its own row, with blank cells for products lacking that attribute; hex-color values render as a small swatch instead of raw text.
- "Clear list" empties the table and shows the empty state again; nav badge returns to no count.

- [ ] **Step 6: Verify the product detail page toggle**

Visit any `/en/shop/{sku}` page. Confirm the "Add to compare" / "Remove from compare" button appears below the product title and toggles correctly, matching the shop grid's state.

- [ ] **Step 7: Run the full test suite one final time**

Run: `php vendor/bin/phpunit --testdox`
Expected: PASS, all tests green including the 10 new `CompareTest` cases.

No commit for this task — it's verification only. If any step fails, fix the underlying task and re-run `php vendor/bin/phpunit` before re-verifying.
