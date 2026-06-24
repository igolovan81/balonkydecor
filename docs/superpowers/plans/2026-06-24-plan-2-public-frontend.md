# BalonkyDecor — Plan 2: Public Frontend

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement all public-facing pages — shop, product detail, services, gallery, blog, and contact form — replacing the stub controllers from Plan 1 with real DB-backed pages.

**Architecture:** Each page follows the same pattern: a Model class runs PDO queries against the MySQL DB, a Controller fetches data and passes it to a Twig template. A shared `BaseController` eliminates the I18nExtension boilerplate repeated in every controller. The `current_path` (URL without lang prefix) is computed in `BaseController::render()` so the language switcher in `base.twig` always works correctly.

**Tech Stack:** PHP 8.1+, Slim 4, Twig 3, PDO/MySQL, PHPUnit 11

## Global Constraints

- PHP minimum: 8.1
- All source files outside `www/` (web root)
- URL structure: `/{lang}/{path}` — lang codes: `cs`, `ru`, `en`, `uk`, `sk`; default: `cs`
- Products identified in URLs by their `sku` field (unique, routed as `{slug}` parameter)
- Gallery albums and blog posts identified by their `slug` field
- Design: elegant & clean — white/pastel, `#fafaf8` bg, `#b8967a` accent, Georgia serif body font
- All DB queries use PDO prepared statements
- No placeholder text: every template shows real data or a graceful empty state

---

### Task 1: BaseController

**Files:**
- Create: `src/Controllers/BaseController.php`
- Modify: `src/Controllers/HomeController.php` — extend BaseController, remove duplicate boilerplate

**Interfaces:**
- Produces: `BaseController::render(Request, Response, string $template, array $data = []): Response`
  - Registers `I18nExtension` on first call
  - Injects `lang` (string) and `current_path` (string, URI without lang prefix) into every template automatically

- [ ] **Step 1: Create `src/Controllers/BaseController.php`**

```php
<?php
namespace App\Controllers;

use App\Services\I18n;
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
        /** @var I18n $i18n */
        $i18n = $request->getAttribute('i18n');
        $lang = $request->getAttribute('lang');

        $env = $this->twig->getEnvironment();
        if (!$env->hasExtension(I18nExtension::class)) {
            $env->addExtension(new I18nExtension($i18n));
        }

        $uri  = $request->getUri()->getPath();
        $path = preg_replace('#^/' . preg_quote($lang, '#') . '#', '', $uri) ?: '/';

        return $this->twig->render($response, $template, array_merge([
            'lang'         => $lang,
            'current_path' => $path,
        ], $data));
    }
}
```

- [ ] **Step 2: Update `src/Controllers/HomeController.php` to extend BaseController**

```php
<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HomeController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        return $this->render($request, $response, 'public/home.twig');
    }
}
```

- [ ] **Step 3: Verify home page still works**

```bash
php -S localhost:8080 -t www &
curl -s http://localhost:8080/en/ | grep -o '<h1>.*</h1>'
kill %1
```

Expected: `<h1>Beautiful balloons for every occasion</h1>`

- [ ] **Step 4: Commit**

```bash
git add src/Controllers/BaseController.php src/Controllers/HomeController.php
git commit -m "feat: BaseController with shared render() helper"
```

---

### Task 2: Shop — Category & Product Models + Pages

**Files:**
- Create: `src/Models/CategoryModel.php`
- Create: `src/Models/ProductModel.php`
- Modify: `src/Controllers/ShopController.php` — replace stub
- Create: `templates/public/shop/index.twig`
- Create: `templates/public/shop/product.twig`
- Create: `tests/Unit/Models/CategoryModelTest.php`
- Create: `tests/Unit/Models/ProductModelTest.php`

**Interfaces:**
- Consumes: `Database::getConnection(): PDO` (Plan 1)
- Produces:
  - `CategoryModel::allWithTranslation(string $lang): array` — `[['id', 'slug', 'image', 'name', 'description'], ...]`
  - `ProductModel::allActive(string $lang, ?int $categoryId = null): array` — `[['id', 'sku', 'price', 'name', 'description', 'primary_image'], ...]`
  - `ProductModel::findBySku(string $sku, string $lang): ?array` — single product row + `images` key (array of filenames)

- [ ] **Step 1: Create `tests/Unit/Models/CategoryModelTest.php`**

```php
<?php
namespace Tests\Unit\Models;

use App\Models\CategoryModel;
use PHPUnit\Framework\TestCase;

class CategoryModelTest extends TestCase
{
    public function test_returns_array(): void
    {
        $result = CategoryModel::allWithTranslation('en');
        $this->assertIsArray($result);
    }

    public function test_each_row_has_expected_keys(): void
    {
        // Insert a test category first if table is empty
        $pdo = \App\Models\Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO categories (slug, sort_order) VALUES ('test-cat', 99)");
        $pdo->exec("INSERT IGNORE INTO category_t (category_id, lang_code, name)
                    SELECT id, 'en', 'Test Category' FROM categories WHERE slug='test-cat'");

        $result = CategoryModel::allWithTranslation('en');
        $this->assertNotEmpty($result);
        $row = $result[0];
        foreach (['id', 'slug', 'name'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
    }
}
```

- [ ] **Step 2: Run test to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Models/CategoryModelTest.php --testdox
```

Expected: FAIL — `App\Models\CategoryModel` not found.

- [ ] **Step 3: Create `src/Models/CategoryModel.php`**

```php
<?php
namespace App\Models;

class CategoryModel
{
    public static function allWithTranslation(string $lang): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT c.id, c.slug, c.image, c.sort_order,
                   COALESCE(t.name, c.slug) AS name,
                   t.description
            FROM categories c
            LEFT JOIN category_t t ON t.category_id = c.id AND t.lang_code = :lang
            ORDER BY c.sort_order, c.id
        ');
        $stmt->execute(['lang' => $lang]);
        return $stmt->fetchAll();
    }
}
```

- [ ] **Step 4: Create `tests/Unit/Models/ProductModelTest.php`**

```php
<?php
namespace Tests\Unit\Models;

use App\Models\ProductModel;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

class ProductModelTest extends TestCase
{
    private static int $categoryId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO categories (slug) VALUES ('test-products')");
        $row = $pdo->query("SELECT id FROM categories WHERE slug='test-products'")->fetch();
        self::$categoryId = (int) $row['id'];

        $pdo->exec("INSERT IGNORE INTO products (category_id, sku, price) VALUES (" . self::$categoryId . ", 'TEST-SKU-001', 9.99)");
        $id = $pdo->query("SELECT id FROM products WHERE sku='TEST-SKU-001'")->fetch()['id'];
        $pdo->exec("INSERT IGNORE INTO product_t (product_id, lang_code, name) VALUES ({$id}, 'en', 'Test Product')");
    }

    public function test_all_active_returns_array(): void
    {
        $result = ProductModel::allActive('en');
        $this->assertIsArray($result);
    }

    public function test_find_by_sku_returns_product(): void
    {
        $product = ProductModel::findBySku('TEST-SKU-001', 'en');
        $this->assertNotNull($product);
        $this->assertSame('TEST-SKU-001', $product['sku']);
        $this->assertSame('Test Product', $product['name']);
        $this->assertArrayHasKey('images', $product);
    }

    public function test_find_by_sku_returns_null_for_unknown(): void
    {
        $this->assertNull(ProductModel::findBySku('NONEXISTENT', 'en'));
    }

    public function test_filter_by_category(): void
    {
        $result = ProductModel::allActive('en', self::$categoryId);
        $this->assertIsArray($result);
        foreach ($result as $row) {
            $this->assertSame(self::$categoryId, (int) $row['category_id']);
        }
    }
}
```

- [ ] **Step 5: Create `src/Models/ProductModel.php`**

```php
<?php
namespace App\Models;

class ProductModel
{
    public static function allActive(string $lang, ?int $categoryId = null): array
    {
        $pdo  = Database::getConnection();
        $sql  = '
            SELECT p.id, p.category_id, p.sku, p.price, p.stock_type, p.stock_qty,
                   COALESCE(t.name, p.sku) AS name,
                   t.description,
                   i.filename AS primary_image
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
        $sql .= ' ORDER BY p.sort_order, p.id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function findBySku(string $sku, string $lang): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT p.id, p.category_id, p.sku, p.price, p.stock_type, p.stock_qty,
                   COALESCE(t.name, p.sku) AS name,
                   t.description, t.meta_title, t.meta_desc
            FROM products p
            LEFT JOIN product_t t ON t.product_id = p.id AND t.lang_code = :lang
            WHERE p.sku = :sku AND p.is_active = 1
        ');
        $stmt->execute(['sku' => $sku, 'lang' => $lang]);
        $product = $stmt->fetch();
        if (!$product) {
            return null;
        }
        $imgs = $pdo->prepare('SELECT filename FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
        $imgs->execute([$product['id']]);
        $product['images'] = $imgs->fetchAll(\PDO::FETCH_COLUMN);
        return $product;
    }
}
```

- [ ] **Step 6: Run model tests**

```bash
./vendor/bin/phpunit tests/Unit/Models/CategoryModelTest.php tests/Unit/Models/ProductModelTest.php --testdox
```

Expected: All tests pass.

- [ ] **Step 7: Replace `src/Controllers/ShopController.php`**

```php
<?php
namespace App\Controllers;

use App\Models\CategoryModel;
use App\Models\ProductModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class ShopController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang       = $request->getAttribute('lang');
        $params     = $request->getQueryParams();
        $categoryId = isset($params['category']) ? (int) $params['category'] : null;

        return $this->render($request, $response, 'public/shop/index.twig', [
            'categories'  => CategoryModel::allWithTranslation($lang),
            'products'    => ProductModel::allActive($lang, $categoryId),
            'active_cat'  => $categoryId,
        ]);
    }

    public function product(Request $request, Response $response, array $args): Response
    {
        $lang    = $request->getAttribute('lang');
        $product = ProductModel::findBySku($args['slug'], $lang);

        if (!$product) {
            return $response->withStatus(404);
        }

        return $this->render($request, $response, 'public/shop/product.twig', [
            'product' => $product,
        ]);
    }
}
```

- [ ] **Step 8: Create `templates/public/shop/index.twig`**

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('nav.shop') }} — {{ t('site.name') }}{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container">
        <h1>{{ t('nav.shop') }}</h1>
    </div>
</section>

<div class="container shop-layout">
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

    <main class="shop-main">
        {% if products %}
        <div class="product-grid">
            {% for product in products %}
            <a href="/{{ lang }}/shop/{{ product.sku }}" class="product-card">
                <div class="product-img">
                    {% if product.primary_image %}
                        <img src="/assets/uploads/{{ product.primary_image }}" alt="{{ product.name }}">
                    {% else %}
                        <div class="product-img-placeholder"></div>
                    {% endif %}
                </div>
                <div class="product-info">
                    <h3>{{ product.name }}</h3>
                    <p class="product-price">{{ product.price|number_format(2, '.', ' ') }} Kč</p>
                </div>
            </a>
            {% endfor %}
        </div>
        {% else %}
        <p class="empty-state">{{ t('shop.no_products') }}</p>
        {% endif %}
    </main>
</div>
{% endblock %}
```

- [ ] **Step 9: Create `templates/public/shop/product.twig`**

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ product.name }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{{ product.meta_desc ?? product.description|striptags|slice(0, 160) }}{% endblock %}

{% block content %}
<div class="container product-detail">
    <div class="product-gallery">
        {% if product.images %}
            <img class="product-main-img" src="/assets/uploads/{{ product.images[0] }}" alt="{{ product.name }}">
            {% if product.images|length > 1 %}
            <div class="product-thumbs">
                {% for img in product.images %}
                <img src="/assets/uploads/{{ img }}" alt="{{ product.name }}" class="product-thumb">
                {% endfor %}
            </div>
            {% endif %}
        {% else %}
        <div class="product-img-placeholder large"></div>
        {% endif %}
    </div>

    <div class="product-detail-info">
        <h1>{{ product.name }}</h1>
        <p class="product-price-lg">{{ product.price|number_format(2, '.', ' ') }} Kč</p>
        {% if product.description %}
        <div class="product-description">{{ product.description|nl2br }}</div>
        {% endif %}

        <form action="/{{ lang }}/cart/add" method="POST" class="add-to-cart-form">
            <div class="qty-row">
                <label for="qty">{{ t('shop.qty') }}</label>
                <input type="number" id="qty" name="qty" value="1" min="1" class="qty-input">
            </div>
            <input type="hidden" name="sku" value="{{ product.sku }}">
            <button type="submit" class="btn btn-primary btn-lg">{{ t('shop.add_to_cart') }}</button>
        </form>
    </div>
</div>
{% endblock %}
```

- [ ] **Step 10: Add shop translations to all 5 lang files**

Add these keys to `lang/cs.json`:
```json
  "shop.all": "Vše",
  "shop.no_products": "Žádné produkty v této kategorii.",
  "shop.qty": "Množství",
  "shop.add_to_cart": "Přidat do košíku"
```

Add to `lang/en.json`:
```json
  "shop.all": "All",
  "shop.no_products": "No products in this category.",
  "shop.qty": "Quantity",
  "shop.add_to_cart": "Add to cart"
```

Add to `lang/ru.json`:
```json
  "shop.all": "Все",
  "shop.no_products": "Нет товаров в этой категории.",
  "shop.qty": "Количество",
  "shop.add_to_cart": "В корзину"
```

Add to `lang/uk.json`:
```json
  "shop.all": "Усі",
  "shop.no_products": "Немає товарів у цій категорії.",
  "shop.qty": "Кількість",
  "shop.add_to_cart": "До кошика"
```

Add to `lang/sk.json`:
```json
  "shop.all": "Všetko",
  "shop.no_products": "Žiadne produkty v tejto kategórii.",
  "shop.qty": "Množstvo",
  "shop.add_to_cart": "Pridať do košíka"
```

- [ ] **Step 11: Add shop CSS to `www/assets/css/style.css`**

Append at end of file:

```css
/* Page hero */
.page-hero { padding: 3rem 0 2rem; background: #fff; border-bottom: 1px solid var(--border); }
.page-hero h1 { font-size: 2rem; font-weight: normal; }

/* Shop layout */
.shop-layout { display: grid; grid-template-columns: 200px 1fr; gap: 2.5rem; padding: 2.5rem 1.5rem; }
.shop-sidebar { display: flex; flex-direction: column; gap: .5rem; }
.cat-filter { display: block; padding: .5rem .75rem; color: var(--text); text-decoration: none; font-family: var(--ui-font); font-size: .9rem; border-radius: 2px; }
.cat-filter:hover, .cat-filter.active { background: var(--border); color: var(--accent); }

/* Product grid */
.product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1.5rem; }
.product-card { text-decoration: none; color: var(--text); border: 1px solid var(--border); border-radius: 2px; overflow: hidden; transition: box-shadow .2s; }
.product-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); }
.product-img { aspect-ratio: 1; overflow: hidden; background: var(--border); }
.product-img img { width: 100%; height: 100%; object-fit: cover; }
.product-img-placeholder { width: 100%; height: 100%; background: var(--border); }
.product-img-placeholder.large { aspect-ratio: 1; }
.product-info { padding: 1rem; }
.product-info h3 { font-size: 1rem; font-weight: normal; margin-bottom: .25rem; }
.product-price { font-family: var(--ui-font); color: var(--accent); font-size: .95rem; }

/* Product detail */
.product-detail { display: grid; grid-template-columns: 1fr 1fr; gap: 3rem; padding: 3rem 1.5rem; }
.product-gallery img { width: 100%; border-radius: 2px; }
.product-main-img { width: 100%; aspect-ratio: 1; object-fit: cover; }
.product-thumbs { display: flex; gap: .5rem; margin-top: .5rem; }
.product-thumb { width: 70px; height: 70px; object-fit: cover; cursor: pointer; border: 1px solid var(--border); }
.product-detail-info h1 { font-size: 1.8rem; font-weight: normal; margin-bottom: .75rem; }
.product-price-lg { font-size: 1.6rem; color: var(--accent); font-family: var(--ui-font); margin-bottom: 1.5rem; }
.product-description { color: var(--muted); line-height: 1.8; margin-bottom: 2rem; }
.add-to-cart-form .qty-row { display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; }
.qty-input { width: 70px; padding: .5rem; border: 1px solid var(--border); font-size: 1rem; }
.btn-lg { padding: 1rem 2.5rem; font-size: 1rem; }
.empty-state { color: var(--muted); font-family: var(--ui-font); padding: 2rem 0; }
```

- [ ] **Step 12: Commit**

```bash
git add src/Models/CategoryModel.php src/Models/ProductModel.php \
        src/Controllers/ShopController.php \
        templates/public/shop/ lang/ \
        www/assets/css/style.css \
        tests/Unit/Models/CategoryModelTest.php tests/Unit/Models/ProductModelTest.php
git commit -m "feat: shop catalog and product detail pages"
```

---

### Task 3: Services & Gallery Pages

**Files:**
- Create: `src/Models/PageModel.php`
- Create: `src/Models/GalleryModel.php`
- Modify: `src/Controllers/PageController.php` — replace stub
- Modify: `src/Controllers/GalleryController.php` — replace stub
- Create: `templates/public/services.twig`
- Create: `templates/public/gallery/index.twig`
- Create: `templates/public/gallery/album.twig`
- Create: `tests/Unit/Models/PageModelTest.php`
- Create: `tests/Unit/Models/GalleryModelTest.php`

**Interfaces:**
- Produces:
  - `PageModel::find(string $slug, string $lang): ?array` — `['title', 'body', 'meta_title', 'meta_desc']`
  - `GalleryModel::albums(string $lang): array` — `[['id','slug','cover_image','name','description'], ...]`
  - `GalleryModel::album(string $slug, string $lang): ?array` — album row + `'images'` key (array of filenames)

- [ ] **Step 1: Create `tests/Unit/Models/PageModelTest.php`**

```php
<?php
namespace Tests\Unit\Models;

use App\Models\PageModel;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

class PageModelTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO page_t (page_id, lang_code, title, body)
                    SELECT id, 'en', 'Services', '<p>Our services</p>'
                    FROM pages WHERE slug='services'");
    }

    public function test_find_returns_page(): void
    {
        $page = PageModel::find('services', 'en');
        $this->assertNotNull($page);
        $this->assertSame('Services', $page['title']);
    }

    public function test_find_returns_null_for_unknown(): void
    {
        $this->assertNull(PageModel::find('nonexistent-page', 'en'));
    }
}
```

- [ ] **Step 2: Create `tests/Unit/Models/GalleryModelTest.php`**

```php
<?php
namespace Tests\Unit\Models;

use App\Models\GalleryModel;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

class GalleryModelTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO gallery_albums (slug) VALUES ('test-album')");
        $row = $pdo->query("SELECT id FROM gallery_albums WHERE slug='test-album'")->fetch();
        $id  = $row['id'];
        $pdo->exec("INSERT IGNORE INTO gallery_album_t (album_id, lang_code, name)
                    VALUES ({$id}, 'en', 'Test Album')");
    }

    public function test_albums_returns_array(): void
    {
        $this->assertIsArray(GalleryModel::albums('en'));
    }

    public function test_album_returns_data(): void
    {
        $album = GalleryModel::album('test-album', 'en');
        $this->assertNotNull($album);
        $this->assertSame('Test Album', $album['name']);
        $this->assertArrayHasKey('images', $album);
    }

    public function test_album_returns_null_for_unknown(): void
    {
        $this->assertNull(GalleryModel::album('no-such-album', 'en'));
    }
}
```

- [ ] **Step 3: Run tests to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Models/PageModelTest.php tests/Unit/Models/GalleryModelTest.php --testdox
```

Expected: FAIL — classes not found.

- [ ] **Step 4: Create `src/Models/PageModel.php`**

```php
<?php
namespace App\Models;

class PageModel
{
    public static function find(string $slug, string $lang): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT t.title, t.body, t.meta_title, t.meta_desc
            FROM pages p
            JOIN page_t t ON t.page_id = p.id AND t.lang_code = :lang
            WHERE p.slug = :slug
        ');
        $stmt->execute(['slug' => $slug, 'lang' => $lang]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
```

- [ ] **Step 5: Create `src/Models/GalleryModel.php`**

```php
<?php
namespace App\Models;

class GalleryModel
{
    public static function albums(string $lang): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT a.id, a.slug, a.cover_image, a.sort_order,
                   COALESCE(t.name, a.slug) AS name, t.description
            FROM gallery_albums a
            LEFT JOIN gallery_album_t t ON t.album_id = a.id AND t.lang_code = :lang
            ORDER BY a.sort_order, a.id
        ');
        $stmt->execute(['lang' => $lang]);
        return $stmt->fetchAll();
    }

    public static function album(string $slug, string $lang): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT a.id, a.slug, a.cover_image,
                   COALESCE(t.name, a.slug) AS name, t.description
            FROM gallery_albums a
            LEFT JOIN gallery_album_t t ON t.album_id = a.id AND t.lang_code = :lang
            WHERE a.slug = :slug
        ');
        $stmt->execute(['slug' => $slug, 'lang' => $lang]);
        $album = $stmt->fetch();
        if (!$album) {
            return null;
        }
        $imgs = $pdo->prepare('SELECT filename FROM gallery_images WHERE album_id = ? ORDER BY sort_order, id');
        $imgs->execute([$album['id']]);
        $album['images'] = $imgs->fetchAll(\PDO::FETCH_COLUMN);
        return $album;
    }
}
```

- [ ] **Step 6: Run tests to confirm pass**

```bash
./vendor/bin/phpunit tests/Unit/Models/PageModelTest.php tests/Unit/Models/GalleryModelTest.php --testdox
```

Expected: All tests pass.

- [ ] **Step 7: Replace `src/Controllers/PageController.php`**

```php
<?php
namespace App\Controllers;

use App\Models\PageModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class PageController extends BaseController
{
    public function services(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        $page = PageModel::find('services', $lang);
        return $this->render($request, $response, 'public/services.twig', [
            'page' => $page,
        ]);
    }
}
```

- [ ] **Step 8: Replace `src/Controllers/GalleryController.php`**

```php
<?php
namespace App\Controllers;

use App\Models\GalleryModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class GalleryController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        return $this->render($request, $response, 'public/gallery/index.twig', [
            'albums' => GalleryModel::albums($lang),
        ]);
    }

    public function album(Request $request, Response $response, array $args): Response
    {
        $lang  = $request->getAttribute('lang');
        $album = GalleryModel::album($args['slug'], $lang);
        if (!$album) {
            return $response->withStatus(404);
        }
        return $this->render($request, $response, 'public/gallery/album.twig', [
            'album' => $album,
        ]);
    }
}
```

- [ ] **Step 9: Add services/gallery translations to all 5 lang files**

Add to `lang/cs.json`:
```json
  "services.title": "Naše služby",
  "services.no_content": "Popis služeb bude brzy k dispozici.",
  "gallery.title": "Galerie",
  "gallery.no_albums": "Galerie je prázdná.",
  "gallery.back": "← Zpět do galerie"
```

Add to `lang/en.json`:
```json
  "services.title": "Our Services",
  "services.no_content": "Service descriptions coming soon.",
  "gallery.title": "Gallery",
  "gallery.no_albums": "Gallery is empty.",
  "gallery.back": "← Back to gallery"
```

Add to `lang/ru.json`:
```json
  "services.title": "Наши услуги",
  "services.no_content": "Описание услуг скоро появится.",
  "gallery.title": "Галерея",
  "gallery.no_albums": "Галерея пуста.",
  "gallery.back": "← Назад в галерею"
```

Add to `lang/uk.json`:
```json
  "services.title": "Наші послуги",
  "services.no_content": "Опис послуг незабаром з'явиться.",
  "gallery.title": "Галерея",
  "gallery.no_albums": "Галерея порожня.",
  "gallery.back": "← Назад до галереї"
```

Add to `lang/sk.json`:
```json
  "services.title": "Naše služby",
  "services.no_content": "Popis služieb čoskoro.",
  "gallery.title": "Galéria",
  "gallery.no_albums": "Galéria je prázdna.",
  "gallery.back": "← Späť do galérie"
```

- [ ] **Step 10: Create `templates/public/services.twig`**

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('services.title') }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{{ page.meta_desc ?? '' }}{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container">
        <h1>{{ page ? page.title : t('services.title') }}</h1>
    </div>
</section>
<div class="container content-page">
    {% if page and page.body %}
        <div class="rich-content">{{ page.body|raw }}</div>
    {% else %}
        <p class="empty-state">{{ t('services.no_content') }}</p>
    {% endif %}
</div>
{% endblock %}
```

- [ ] **Step 11: Create `templates/public/gallery/index.twig`**

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('gallery.title') }} — {{ t('site.name') }}{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container"><h1>{{ t('gallery.title') }}</h1></div>
</section>
<div class="container" style="padding: 2.5rem 1.5rem;">
    {% if albums %}
    <div class="gallery-grid">
        {% for album in albums %}
        <a href="/{{ lang }}/gallery/{{ album.slug }}" class="gallery-album-card">
            <div class="gallery-cover">
                {% if album.cover_image %}
                    <img src="/assets/uploads/{{ album.cover_image }}" alt="{{ album.name }}">
                {% else %}
                    <div class="gallery-cover-placeholder"></div>
                {% endif %}
            </div>
            <div class="gallery-album-name">{{ album.name }}</div>
        </a>
        {% endfor %}
    </div>
    {% else %}
    <p class="empty-state">{{ t('gallery.no_albums') }}</p>
    {% endif %}
</div>
{% endblock %}
```

- [ ] **Step 12: Create `templates/public/gallery/album.twig`**

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ album.name }} — {{ t('site.name') }}{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container">
        <a href="/{{ lang }}/gallery" class="back-link">{{ t('gallery.back') }}</a>
        <h1>{{ album.name }}</h1>
    </div>
</section>
<div class="container" style="padding: 2.5rem 1.5rem;">
    {% if album.images %}
    <div class="photo-grid">
        {% for img in album.images %}
        <a href="/assets/uploads/{{ img }}" class="photo-item" target="_blank">
            <img src="/assets/uploads/{{ img }}" alt="{{ album.name }}">
        </a>
        {% endfor %}
    </div>
    {% else %}
    <p class="empty-state">{{ t('gallery.no_albums') }}</p>
    {% endif %}
</div>
{% endblock %}
```

- [ ] **Step 13: Append gallery + services CSS to `www/assets/css/style.css`**

```css
/* Services / rich content */
.content-page { padding: 2.5rem 1.5rem; max-width: 800px; }
.rich-content { line-height: 1.9; }
.rich-content h2 { font-size: 1.4rem; font-weight: normal; margin: 2rem 0 .75rem; }
.rich-content p { margin-bottom: 1rem; color: var(--text); }
.back-link { font-family: var(--ui-font); font-size: .85rem; color: var(--muted); text-decoration: none; display: block; margin-bottom: .5rem; }
.back-link:hover { color: var(--accent); }

/* Gallery */
.gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1.5rem; }
.gallery-album-card { text-decoration: none; color: var(--text); }
.gallery-cover { aspect-ratio: 4/3; overflow: hidden; border-radius: 2px; background: var(--border); }
.gallery-cover img { width: 100%; height: 100%; object-fit: cover; transition: transform .3s; }
.gallery-album-card:hover .gallery-cover img { transform: scale(1.04); }
.gallery-cover-placeholder { width: 100%; height: 100%; background: var(--border); }
.gallery-album-name { margin-top: .6rem; font-family: var(--ui-font); font-size: .9rem; }
.photo-grid { columns: 3; gap: 1rem; }
.photo-item { display: block; margin-bottom: 1rem; break-inside: avoid; }
.photo-item img { width: 100%; display: block; border-radius: 2px; }
```

- [ ] **Step 14: Commit**

```bash
git add src/Models/PageModel.php src/Models/GalleryModel.php \
        src/Controllers/PageController.php src/Controllers/GalleryController.php \
        templates/public/services.twig templates/public/gallery/ \
        lang/ www/assets/css/style.css \
        tests/Unit/Models/PageModelTest.php tests/Unit/Models/GalleryModelTest.php
git commit -m "feat: services page and gallery with album detail"
```

---

### Task 4: Blog Pages

**Files:**
- Create: `src/Models/BlogModel.php`
- Modify: `src/Controllers/BlogController.php` — replace stub
- Create: `templates/public/blog/index.twig`
- Create: `templates/public/blog/post.twig`
- Create: `tests/Unit/Models/BlogModelTest.php`

**Interfaces:**
- Produces:
  - `BlogModel::published(string $lang, int $page = 1, int $perPage = 10): array` — `['posts' => [...], 'total' => int, 'pages' => int]`
  - `BlogModel::findBySlug(string $slug, string $lang): ?array` — post row with translation fields

- [ ] **Step 1: Create `tests/Unit/Models/BlogModelTest.php`**

```php
<?php
namespace Tests\Unit\Models;

use App\Models\BlogModel;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

class BlogModelTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO blog_posts (slug, status, published_at)
                    VALUES ('test-post', 'published', NOW())");
        $row = $pdo->query("SELECT id FROM blog_posts WHERE slug='test-post'")->fetch();
        $id  = $row['id'];
        $pdo->exec("INSERT IGNORE INTO blog_post_t (post_id, lang_code, title, body)
                    VALUES ({$id}, 'en', 'Test Post', '<p>Hello</p>')");
    }

    public function test_published_returns_structure(): void
    {
        $result = BlogModel::published('en');
        $this->assertArrayHasKey('posts', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('pages', $result);
    }

    public function test_find_by_slug_returns_post(): void
    {
        $post = BlogModel::findBySlug('test-post', 'en');
        $this->assertNotNull($post);
        $this->assertSame('Test Post', $post['title']);
    }

    public function test_find_by_slug_returns_null_for_draft(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO blog_posts (slug, status) VALUES ('draft-post', 'draft')");
        $this->assertNull(BlogModel::findBySlug('draft-post', 'en'));
    }
}
```

- [ ] **Step 2: Run test to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Models/BlogModelTest.php --testdox
```

Expected: FAIL — `App\Models\BlogModel` not found.

- [ ] **Step 3: Create `src/Models/BlogModel.php`**

```php
<?php
namespace App\Models;

class BlogModel
{
    public static function published(string $lang, int $page = 1, int $perPage = 10): array
    {
        $pdo    = Database::getConnection();
        $offset = ($page - 1) * $perPage;

        $count = $pdo->prepare('SELECT COUNT(*) FROM blog_posts WHERE status = ?');
        $count->execute(['published']);
        $total = (int) $count->fetchColumn();

        $stmt = $pdo->prepare('
            SELECT p.id, p.slug, p.published_at,
                   COALESCE(t.title, p.slug) AS title,
                   t.meta_desc
            FROM blog_posts p
            LEFT JOIN blog_post_t t ON t.post_id = p.id AND t.lang_code = :lang
            WHERE p.status = \'published\'
            ORDER BY p.published_at DESC
            LIMIT :limit OFFSET :offset
        ');
        $stmt->bindValue('lang',   $lang,    \PDO::PARAM_STR);
        $stmt->bindValue('limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'posts' => $stmt->fetchAll(),
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ];
    }

    public static function findBySlug(string $slug, string $lang): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT p.id, p.slug, p.published_at,
                   COALESCE(t.title, p.slug) AS title,
                   t.body, t.meta_title, t.meta_desc
            FROM blog_posts p
            LEFT JOIN blog_post_t t ON t.post_id = p.id AND t.lang_code = :lang
            WHERE p.slug = :slug AND p.status = \'published\'
        ');
        $stmt->execute(['slug' => $slug, 'lang' => $lang]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
```

- [ ] **Step 4: Run tests to confirm pass**

```bash
./vendor/bin/phpunit tests/Unit/Models/BlogModelTest.php --testdox
```

Expected: All tests pass.

- [ ] **Step 5: Replace `src/Controllers/BlogController.php`**

```php
<?php
namespace App\Controllers;

use App\Models\BlogModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class BlogController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang   = $request->getAttribute('lang');
        $params = $request->getQueryParams();
        $page   = max(1, (int) ($params['page'] ?? 1));

        return $this->render($request, $response, 'public/blog/index.twig',
            BlogModel::published($lang, $page)
        );
    }

    public function post(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        $post = BlogModel::findBySlug($args['slug'], $lang);
        if (!$post) {
            return $response->withStatus(404);
        }
        return $this->render($request, $response, 'public/blog/post.twig', [
            'post' => $post,
        ]);
    }
}
```

- [ ] **Step 6: Add blog translations to all 5 lang files**

Add to `lang/cs.json`:
```json
  "blog.title": "Blog",
  "blog.no_posts": "Zatím žádné příspěvky.",
  "blog.read_more": "Číst dále →",
  "blog.back": "← Zpět na blog"
```

Add to `lang/en.json`:
```json
  "blog.title": "Blog",
  "blog.no_posts": "No posts yet.",
  "blog.read_more": "Read more →",
  "blog.back": "← Back to blog"
```

Add to `lang/ru.json`:
```json
  "blog.title": "Блог",
  "blog.no_posts": "Пока нет публикаций.",
  "blog.read_more": "Читать далее →",
  "blog.back": "← Назад в блог"
```

Add to `lang/uk.json`:
```json
  "blog.title": "Блог",
  "blog.no_posts": "Поки що немає публікацій.",
  "blog.read_more": "Читати далі →",
  "blog.back": "← Назад до блогу"
```

Add to `lang/sk.json`:
```json
  "blog.title": "Blog",
  "blog.no_posts": "Zatiaľ žiadne príspevky.",
  "blog.read_more": "Čítať viac →",
  "blog.back": "← Späť na blog"
```

- [ ] **Step 7: Create `templates/public/blog/index.twig`**

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('blog.title') }} — {{ t('site.name') }}{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container"><h1>{{ t('blog.title') }}</h1></div>
</section>
<div class="container blog-list">
    {% if posts %}
        {% for post in posts %}
        <article class="blog-card">
            <time class="blog-date">{{ post.published_at|date('d. m. Y') }}</time>
            <h2><a href="/{{ lang }}/blog/{{ post.slug }}">{{ post.title }}</a></h2>
            {% if post.meta_desc %}<p class="blog-excerpt">{{ post.meta_desc }}</p>{% endif %}
            <a href="/{{ lang }}/blog/{{ post.slug }}" class="blog-read-more">{{ t('blog.read_more') }}</a>
        </article>
        {% endfor %}

        {% if pages > 1 %}
        <nav class="pagination">
            {% for p in 1..pages %}
            <a href="/{{ lang }}/blog?page={{ p }}" class="{{ p == page ? 'active' : '' }}">{{ p }}</a>
            {% endfor %}
        </nav>
        {% endif %}
    {% else %}
    <p class="empty-state">{{ t('blog.no_posts') }}</p>
    {% endif %}
</div>
{% endblock %}
```

- [ ] **Step 8: Create `templates/public/blog/post.twig`**

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ post.title }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{{ post.meta_desc ?? '' }}{% endblock %}

{% block content %}
<article class="container blog-post-full">
    <a href="/{{ lang }}/blog" class="back-link">{{ t('blog.back') }}</a>
    <time class="blog-date">{{ post.published_at|date('d. m. Y') }}</time>
    <h1>{{ post.title }}</h1>
    <div class="rich-content">{{ post.body|raw }}</div>
</article>
{% endblock %}
```

- [ ] **Step 9: Append blog CSS to `www/assets/css/style.css`**

```css
/* Blog */
.blog-list { padding: 2.5rem 1.5rem; max-width: 800px; }
.blog-card { border-bottom: 1px solid var(--border); padding: 2rem 0; }
.blog-card:last-of-type { border-bottom: none; }
.blog-date { font-family: var(--ui-font); font-size: .8rem; color: var(--muted); display: block; margin-bottom: .5rem; }
.blog-card h2 { font-size: 1.3rem; font-weight: normal; margin-bottom: .5rem; }
.blog-card h2 a { color: var(--text); text-decoration: none; }
.blog-card h2 a:hover { color: var(--accent); }
.blog-excerpt { color: var(--muted); margin-bottom: .75rem; }
.blog-read-more { font-family: var(--ui-font); font-size: .85rem; color: var(--accent); text-decoration: none; }
.blog-post-full { padding: 2.5rem 1.5rem; max-width: 800px; }
.blog-post-full h1 { font-size: 2rem; font-weight: normal; margin: .5rem 0 2rem; }
.pagination { display: flex; gap: .5rem; margin-top: 2rem; font-family: var(--ui-font); }
.pagination a { padding: .4rem .8rem; border: 1px solid var(--border); text-decoration: none; color: var(--text); font-size: .85rem; }
.pagination a.active { background: var(--accent); color: #fff; border-color: var(--accent); }
```

- [ ] **Step 10: Commit**

```bash
git add src/Models/BlogModel.php src/Controllers/BlogController.php \
        templates/public/blog/ lang/ www/assets/css/style.css \
        tests/Unit/Models/BlogModelTest.php
git commit -m "feat: blog post list and single post pages"
```

---

### Task 5: Contact Page

**Files:**
- Create: `src/Services/Mailer.php`
- Modify: `src/Controllers/ContactController.php` — replace stub
- Create: `templates/public/contact.twig`

**Interfaces:**
- Produces: `Mailer::send(string $to, string $subject, string $body, string $replyTo = ''): bool`
  - If `smtp_from` setting is empty, writes to `tmp/mail.log` instead of sending — safe for local dev
  - Returns `true` on success or log write

- [ ] **Step 1: Create `src/Services/Mailer.php`**

```php
<?php
namespace App\Services;

use App\Models\Database;

class Mailer
{
    public static function send(
        string $to,
        string $subject,
        string $body,
        string $replyTo = ''
    ): bool {
        $pdo   = Database::getConnection();
        $stmt  = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN
                              ('smtp_from','site_name')");
        $cfg   = [];
        foreach ($stmt->fetchAll() as $row) {
            $cfg[$row['key']] = $row['value'];
        }

        $from     = $cfg['smtp_from'] ?? '';
        $siteName = $cfg['site_name'] ?? 'BalonkyDecor';

        if (empty($from)) {
            // Local dev fallback — log to file
            $log = __DIR__ . '/../../tmp/mail.log';
            $entry = date('[Y-m-d H:i:s]') . " TO:{$to} SUBJECT:{$subject}\n{$body}\n\n";
            file_put_contents($log, $entry, FILE_APPEND);
            return true;
        }

        $headers  = "From: {$siteName} <{$from}>\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        if ($replyTo) {
            $headers .= "Reply-To: {$replyTo}\r\n";
        }

        return mail($to, $subject, $body, $headers);
    }
}
```

- [ ] **Step 2: Replace `src/Controllers/ContactController.php`**

```php
<?php
namespace App\Controllers;

use App\Models\Database;
use App\Services\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class ContactController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        return $this->render($request, $response, 'public/contact.twig', [
            'success' => false,
            'error'   => false,
        ]);
    }

    public function send(Request $request, Response $response, array $args): Response
    {
        $lang   = $request->getAttribute('lang');
        $body   = (array) $request->getParsedBody();
        $name    = trim($body['name']    ?? '');
        $email   = trim($body['email']   ?? '');
        $message = trim($body['message'] ?? '');

        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$message) {
            return $this->render($request, $response, 'public/contact.twig', [
                'success' => false,
                'error'   => true,
                'values'  => ['name' => $name, 'email' => $email, 'message' => $message],
            ]);
        }

        $pdo      = Database::getConnection();
        $setting  = $pdo->query("SELECT value FROM settings WHERE `key`='contact_email'")->fetchColumn();
        $adminTo  = $setting ?: 'admin@balonkydecor.cz';

        $html = "<p><strong>Name:</strong> " . htmlspecialchars($name) . "</p>"
              . "<p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>"
              . "<p><strong>Message:</strong></p>"
              . "<p>" . nl2br(htmlspecialchars($message)) . "</p>";

        Mailer::send($adminTo, "Contact form: {$name}", $html, $email);

        return $this->render($request, $response, 'public/contact.twig', [
            'success' => true,
            'error'   => false,
        ]);
    }
}
```

- [ ] **Step 3: Add contact translations to all 5 lang files**

Add to `lang/cs.json`:
```json
  "contact.title": "Kontakt",
  "contact.name": "Jméno",
  "contact.email": "E-mail",
  "contact.message": "Zpráva",
  "contact.send": "Odeslat",
  "contact.success": "Děkujeme! Vaše zpráva byla odeslána.",
  "contact.error": "Prosím vyplňte všechna pole správně."
```

Add to `lang/en.json`:
```json
  "contact.title": "Contact",
  "contact.name": "Name",
  "contact.email": "Email",
  "contact.message": "Message",
  "contact.send": "Send",
  "contact.success": "Thank you! Your message has been sent.",
  "contact.error": "Please fill in all fields correctly."
```

Add to `lang/ru.json`:
```json
  "contact.title": "Контакты",
  "contact.name": "Имя",
  "contact.email": "E-mail",
  "contact.message": "Сообщение",
  "contact.send": "Отправить",
  "contact.success": "Спасибо! Ваше сообщение отправлено.",
  "contact.error": "Пожалуйста, заполните все поля правильно."
```

Add to `lang/uk.json`:
```json
  "contact.title": "Контакти",
  "contact.name": "Ім'я",
  "contact.email": "E-mail",
  "contact.message": "Повідомлення",
  "contact.send": "Надіслати",
  "contact.success": "Дякуємо! Ваше повідомлення надіслано.",
  "contact.error": "Будь ласка, заповніть усі поля правильно."
```

Add to `lang/sk.json`:
```json
  "contact.title": "Kontakt",
  "contact.name": "Meno",
  "contact.email": "E-mail",
  "contact.message": "Správa",
  "contact.send": "Odoslať",
  "contact.success": "Ďakujeme! Vaša správa bola odoslaná.",
  "contact.error": "Prosím vyplňte všetky polia správne."
```

- [ ] **Step 4: Create `templates/public/contact.twig`**

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('contact.title') }} — {{ t('site.name') }}{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container"><h1>{{ t('contact.title') }}</h1></div>
</section>
<div class="container contact-layout">
    <div class="contact-form-wrap">
        {% if success %}
        <p class="form-success">{{ t('contact.success') }}</p>
        {% else %}
            {% if error %}
            <p class="form-error">{{ t('contact.error') }}</p>
            {% endif %}
            <form action="/{{ lang }}/contact" method="POST" class="contact-form">
                <div class="form-group">
                    <label for="name">{{ t('contact.name') }}</label>
                    <input type="text" id="name" name="name" required
                           value="{{ values.name ?? '' }}">
                </div>
                <div class="form-group">
                    <label for="email">{{ t('contact.email') }}</label>
                    <input type="email" id="email" name="email" required
                           value="{{ values.email ?? '' }}">
                </div>
                <div class="form-group">
                    <label for="message">{{ t('contact.message') }}</label>
                    <textarea id="message" name="message" rows="6" required>{{ values.message ?? '' }}</textarea>
                </div>
                <button type="submit" class="btn btn-primary">{{ t('contact.send') }}</button>
            </form>
        {% endif %}
    </div>
</div>
{% endblock %}
```

- [ ] **Step 5: Append contact CSS to `www/assets/css/style.css`**

```css
/* Contact */
.contact-layout { padding: 2.5rem 1.5rem; max-width: 600px; }
.contact-form .form-group { margin-bottom: 1.25rem; }
.contact-form label { display: block; font-family: var(--ui-font); font-size: .85rem; margin-bottom: .4rem; color: var(--muted); }
.contact-form input, .contact-form textarea {
    width: 100%; padding: .65rem .75rem;
    border: 1px solid var(--border); background: #fff;
    font-family: var(--font); font-size: 1rem; color: var(--text);
    border-radius: 2px; outline: none;
}
.contact-form input:focus, .contact-form textarea:focus { border-color: var(--accent); }
.contact-form textarea { resize: vertical; }
.form-success { background: #eaf5ea; border: 1px solid #b5d9b5; padding: 1rem; border-radius: 2px; font-family: var(--ui-font); margin-bottom: 1.5rem; }
.form-error { background: #fdf0f0; border: 1px solid #e8b4b4; padding: 1rem; border-radius: 2px; font-family: var(--ui-font); margin-bottom: 1.5rem; }
```

- [ ] **Step 6: Run full test suite**

```bash
./vendor/bin/phpunit --testdox
```

Expected: All tests pass (13 tests).

- [ ] **Step 7: Smoke-test all pages**

```bash
php -S localhost:8080 -t www &
sleep 1
curl -s -o /dev/null -w "%{http_code} /en/shop\n"      http://localhost:8080/en/shop
curl -s -o /dev/null -w "%{http_code} /en/services\n"  http://localhost:8080/en/services
curl -s -o /dev/null -w "%{http_code} /en/gallery\n"   http://localhost:8080/en/gallery
curl -s -o /dev/null -w "%{http_code} /en/blog\n"      http://localhost:8080/en/blog
curl -s -o /dev/null -w "%{http_code} /en/contact\n"   http://localhost:8080/en/contact
kill %1
```

Expected: all return `200`.

- [ ] **Step 8: Commit**

```bash
git add src/Services/Mailer.php src/Controllers/ContactController.php \
        templates/public/contact.twig lang/ www/assets/css/style.css
git commit -m "feat: contact form with Mailer service"
```

---

## Self-Review

**Spec coverage:**
- ✅ Shop / Catalog — filterable by category, product detail with add-to-cart form
- ✅ Services page — DB-backed rich content from `page_t`
- ✅ Gallery — album list + photo grid
- ✅ Blog — paginated post list + single post
- ✅ Contact — form with validation, admin email notification, local log fallback
- ✅ Language switcher `current_path` — computed in BaseController
- ✅ Elegant & clean design — all new components use CSS variables from style.css
- ✅ Empty states — every page handles no-data gracefully
- ✅ 404 responses — product detail, gallery album, blog post all return 404 when not found

**What this plan does NOT cover (separate plans):**
- Plan 3: Cart, checkout, GoPay payment
- Plan 4: Admin panel
