# SEO / Search Indexing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the 5-language public catalog crawlable and properly indexed by Google — dynamic `robots.txt`/`sitemap.xml`, canonical/hreflang tags, working `meta_title`/`meta_desc` admin editing for products/blog/gallery-albums/pages, JSON-LD structured data, and an HTTP→HTTPS redirect.

**Architecture:** Two new stateless services (`App\Services\Seo` for URL/canonical/hreflang/JSON-LD helpers, `App\Services\Sitemap` for the page list) sit underneath a thin new `SeoController` and get consumed by `BaseController::render()` so every public page automatically gets canonical + hreflang + Organization JSON-LD. Per-content-type `meta_title`/`meta_desc` wiring follows the existing per-model `getTranslations()`/`setTranslations()` pattern already used for `name`/`description`.

**Tech Stack:** PHP 8 / Slim 4 / Twig 3 / PDO-MySQL, PHPUnit 11 against a real Docker MySQL instance — no new dependencies.

## Global Constraints

- Canonical scheme/host: `https://balonkydecor.cz` (no `www`) — spec §"Overview".
- Indexed languages: `cs, sk, en, uk, ru`, in that order; `cs` is `x-default` — spec §"Overview".
- No caching layer for sitemap/robots — spec §1.
- `category_t` gets **no** meta columns (categories have no dedicated URL) — spec §3.
- Follow existing code conventions: static Model classes, `Database::getConnection()` singleton, flat-key admin `lang/admin/*.json` files (all 5 must stay identical), per-controller `LANGS` constants for admin forms.

Every task's requirements implicitly include the above.

---

### Task 1: `Seo` service — canonical URLs, hreflang alternates, Organization JSON-LD

**Files:**
- Create: `src/Services/Seo.php`
- Test: `tests/Unit/Services/SeoTest.php`

**Interfaces:**
- Produces: `Seo::BASE_URL` (string constant `'https://balonkydecor.cz'`), `Seo::LANGUAGES` (array constant `['cs','sk','en','uk','ru']`), `Seo::DEFAULT_LANG` (string constant `'cs'`), `Seo::canonicalUrl(string $lang, string $path): string`, `Seo::alternateUrls(string $path): array` (returns list of `['lang' => string, 'url' => string]`, 5 languages + one `'x-default'` entry), `Seo::organizationJsonLd(string $siteName, string $phone, string $email): string` (JSON string; omits `telephone`/`email` keys when the corresponding argument is `''`).
- `$path` is always in the "prefix-stripped" shape already produced by `BaseController::render()` — e.g. `/`, `/shop`, `/shop/some-sku` — never including a `/{lang}` segment.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Unit\Services;

use App\Services\Seo;
use PHPUnit\Framework\TestCase;

class SeoTest extends TestCase
{
    public function test_canonical_url_builds_full_url(): void
    {
        $this->assertSame('https://balonkydecor.cz/cs/shop', Seo::canonicalUrl('cs', '/shop'));
    }

    public function test_canonical_url_for_home(): void
    {
        $this->assertSame('https://balonkydecor.cz/cs/', Seo::canonicalUrl('cs', '/'));
    }

    public function test_alternate_urls_includes_all_languages_plus_x_default(): void
    {
        $alts = Seo::alternateUrls('/shop');
        $this->assertSame(['cs', 'sk', 'en', 'uk', 'ru', 'x-default'], array_column($alts, 'lang'));
    }

    public function test_alternate_urls_x_default_points_to_default_lang(): void
    {
        $alts     = Seo::alternateUrls('/shop');
        $xDefault = array_values(array_filter($alts, fn($a) => $a['lang'] === 'x-default'))[0];
        $this->assertSame('https://balonkydecor.cz/cs/shop', $xDefault['url']);
    }

    public function test_organization_json_ld_includes_name_and_contact(): void
    {
        $data = json_decode(Seo::organizationJsonLd('BalonkyDecor', '+420123456789', 'info@balonkydecor.cz'), true);
        $this->assertSame('Organization', $data['@type']);
        $this->assertSame('BalonkyDecor', $data['name']);
        $this->assertSame('https://balonkydecor.cz', $data['url']);
        $this->assertSame('+420123456789', $data['telephone']);
        $this->assertSame('info@balonkydecor.cz', $data['email']);
    }

    public function test_organization_json_ld_omits_empty_contact_fields(): void
    {
        $data = json_decode(Seo::organizationJsonLd('BalonkyDecor', '', ''), true);
        $this->assertArrayNotHasKey('telephone', $data);
        $this->assertArrayNotHasKey('email', $data);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/Unit/Services/SeoTest.php`
Expected: FAIL — `Class "App\Services\Seo" not found`

- [ ] **Step 3: Write the implementation**

```php
<?php
namespace App\Services;

class Seo
{
    public const BASE_URL     = 'https://balonkydecor.cz';
    public const LANGUAGES    = ['cs', 'sk', 'en', 'uk', 'ru'];
    public const DEFAULT_LANG = 'cs';

    public static function canonicalUrl(string $lang, string $path): string
    {
        return self::BASE_URL . '/' . $lang . $path;
    }

    public static function alternateUrls(string $path): array
    {
        $urls = [];
        foreach (self::LANGUAGES as $lang) {
            $urls[] = ['lang' => $lang, 'url' => self::canonicalUrl($lang, $path)];
        }
        $urls[] = ['lang' => 'x-default', 'url' => self::canonicalUrl(self::DEFAULT_LANG, $path)];
        return $urls;
    }

    public static function organizationJsonLd(string $siteName, string $phone, string $email): string
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => $siteName,
            'url'      => self::BASE_URL,
        ];
        if ($phone !== '') {
            $data['telephone'] = $phone;
        }
        if ($email !== '') {
            $data['email'] = $email;
        }
        // Deliberately no JSON_UNESCAPED_SLASHES: this string is emitted with |raw inside a
        // <script> tag (see Task 2), so "/" must stay escaped as "\/" to prevent a "</script>"
        // value (e.g. a malicious contact_phone/contact_email setting) from breaking out of it.
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/Unit/Services/SeoTest.php`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Services/Seo.php tests/Unit/Services/SeoTest.php
git commit -m "feat: add Seo service for canonical URLs, hreflang alternates, Organization JSON-LD"
```

---

### Task 2: Wire canonical + hreflang + Organization JSON-LD into every public page

**Files:**
- Modify: `src/Controllers/BaseController.php`
- Modify: `templates/layout/base.twig`

**Interfaces:**
- Consumes: `Seo::BASE_URL`, `Seo::canonicalUrl()`, `Seo::alternateUrls()`, `Seo::organizationJsonLd()` from Task 1; `Database::getConnection()` (existing).
- Produces: every template rendered via `BaseController::render()` now receives `base_url`, `canonical_url`, `alternate_urls`, `organization_json_ld` in addition to the existing `lang`/`current_path`. Later tasks (11, 12, 13) rely on `base_url` and `canonical_url` being available in public templates.

No PSR-7-request-mocking test harness exists in this codebase (all existing tests are Model/Service unit tests against a real DB — no Controller tests). Verification for this task is manual, against the local dev server.

- [ ] **Step 1: Update `BaseController::render()`**

Replace the full contents of `src/Controllers/BaseController.php`:

```php
<?php
namespace App\Controllers;

use App\Models\Database;
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
        $settingsStmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('contact_phone','contact_email')");
        $settingsStmt->execute();
        $settingsMap = array_column($settingsStmt->fetchAll(), 'value', 'key');

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
        ], $data));
    }
}
```

- [ ] **Step 2: Update `templates/layout/base.twig`**

In `templates/layout/base.twig`, replace:

```twig
    <meta name="description" content="{% block meta_desc %}{% endblock %}">
    <link rel="stylesheet" href="/assets/css/style.css">
    {% block head %}{% endblock %}
```

with:

```twig
    <meta name="description" content="{% block meta_desc %}{% endblock %}">
    <link rel="canonical" href="{{ canonical_url }}">
    {% for alt in alternate_urls %}
    <link rel="alternate" hreflang="{{ alt.lang }}" href="{{ alt.url }}">
    {% endfor %}
    <script type="application/ld+json">{{ organization_json_ld|raw }}</script>
    <link rel="stylesheet" href="/assets/css/style.css">
    {% block head %}{% endblock %}
```

- [ ] **Step 3: Manually verify against the local dev server**

Run: `docker compose up -d && php -S localhost:8080 -t www`
Then: `curl -s http://localhost:8080/cs/ | grep -E 'rel="canonical"|hreflang|application/ld\+json'`
Expected: one `rel="canonical"` line pointing at `https://balonkydecor.cz/cs/`, six `hreflang` lines (`cs, sk, en, uk, ru, x-default`), one `application/ld+json` script tag containing `"@type":"Organization"`.

- [ ] **Step 4: Run the full test suite to confirm nothing broke**

Run: `php vendor/bin/phpunit --testdox`
Expected: all existing tests still PASS (this task only adds new template variables; no existing behavior changes).

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/BaseController.php templates/layout/base.twig
git commit -m "feat: inject canonical URL, hreflang alternates, and Organization JSON-LD into every public page"
```

---

### Task 3: `noindex` meta on session-specific pages

**Files:**
- Modify: `templates/public/cart.twig`
- Modify: `templates/public/checkout/index.twig`
- Modify: `templates/public/checkout/confirm.twig`
- Modify: `templates/public/order/status.twig`

**Interfaces:**
- Consumes: the existing `{% block head %}{% endblock %}` extension point already defined in `templates/layout/base.twig` — no dependency on Task 1/2.

- [ ] **Step 1: Add the `head` block override to all four templates**

In `templates/public/cart.twig`, after:
```twig
{% block title %}{{ t('cart.title') }} — {{ t('site.name') }}{% endblock %}
```
add:
```twig
{% block head %}<meta name="robots" content="noindex,nofollow">{% endblock %}
```

In `templates/public/checkout/index.twig`, after:
```twig
{% block title %}{{ t('checkout.title') }} — {{ t('site.name') }}{% endblock %}
```
add:
```twig
{% block head %}<meta name="robots" content="noindex,nofollow">{% endblock %}
```

In `templates/public/checkout/confirm.twig`, after:
```twig
{% block title %}{{ t('checkout.confirm_title') }} — {{ t('site.name') }}{% endblock %}
```
add:
```twig
{% block head %}<meta name="robots" content="noindex,nofollow">{% endblock %}
```

In `templates/public/order/status.twig`, after:
```twig
{% block title %}{{ t('order.title') }} {{ order.order_number }} — {{ t('site.name') }}{% endblock %}
```
add:
```twig
{% block head %}<meta name="robots" content="noindex,nofollow">{% endblock %}
```

(`PaymentController` never renders a template — it only redirects — so there is no `payment/*.twig` to touch; those routes are already covered by the `Disallow: /*/payment/` rule added in Task 5.)

- [ ] **Step 2: Manually verify**

Run: `curl -s http://localhost:8080/cs/cart | grep 'noindex'`
Expected: one line — `<meta name="robots" content="noindex,nofollow">`

- [ ] **Step 3: Commit**

```bash
git add templates/public/cart.twig templates/public/checkout/index.twig templates/public/checkout/confirm.twig templates/public/order/status.twig
git commit -m "feat: add noindex meta tag to session-specific pages"
```

---

### Task 4: `Sitemap` service — enumerate indexable pages

**Files:**
- Create: `src/Services/Sitemap.php`
- Test: `tests/Unit/Services/SitemapTest.php`

**Interfaces:**
- Consumes: `Seo::LANGUAGES`, `Seo::DEFAULT_LANG`, `Seo::canonicalUrl()`, `Seo::alternateUrls()` (Task 1); `ProductModel::allActive()`, `GalleryModel::albums()`, `BlogModel::published()` (existing, unchanged).
- Produces: `Sitemap::paths(): array` (flat list of path strings, e.g. `/shop/SKU-1`), `Sitemap::entries(): array` (list of `['loc' => string, 'alternates' => array]`, one row per path × language).

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Unit\Services;

use App\Models\Database;
use App\Services\Sitemap;
use PHPUnit\Framework\TestCase;

class SitemapTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO categories (slug) VALUES ('test-sitemap-cat')");
        $catId = $pdo->query("SELECT id FROM categories WHERE slug='test-sitemap-cat'")->fetch()['id'];
        $pdo->exec("INSERT IGNORE INTO products (category_id, sku, price, is_active) VALUES ({$catId}, 'SITEMAP-SKU-001', 9.99, 1)");
        $pdo->exec("INSERT IGNORE INTO products (category_id, sku, price, is_active) VALUES ({$catId}, 'SITEMAP-SKU-INACTIVE', 9.99, 0)");

        $pdo->exec("INSERT IGNORE INTO gallery_albums (slug) VALUES ('sitemap-test-album')");

        $pdo->exec("INSERT IGNORE INTO blog_posts (slug, status, published_at) VALUES ('sitemap-test-post', 'published', NOW())");
        $pdo->exec("INSERT IGNORE INTO blog_posts (slug, status) VALUES ('sitemap-test-draft', 'draft')");
    }

    public function test_paths_includes_static_pages(): void
    {
        $paths = Sitemap::paths();
        foreach (['/', '/shop', '/services', '/gallery', '/blog', '/contact'] as $expected) {
            $this->assertContains($expected, $paths);
        }
    }

    public function test_paths_includes_active_product(): void
    {
        $this->assertContains('/shop/SITEMAP-SKU-001', Sitemap::paths());
    }

    public function test_paths_excludes_inactive_product(): void
    {
        $this->assertNotContains('/shop/SITEMAP-SKU-INACTIVE', Sitemap::paths());
    }

    public function test_paths_includes_gallery_album(): void
    {
        $this->assertContains('/gallery/sitemap-test-album', Sitemap::paths());
    }

    public function test_paths_includes_published_blog_post(): void
    {
        $this->assertContains('/blog/sitemap-test-post', Sitemap::paths());
    }

    public function test_paths_excludes_draft_blog_post(): void
    {
        $this->assertNotContains('/blog/sitemap-test-draft', Sitemap::paths());
    }

    public function test_entries_produces_one_row_per_path_per_language(): void
    {
        $this->assertCount(count(Sitemap::paths()) * 5, Sitemap::entries());
    }

    public function test_entries_include_all_language_alternates(): void
    {
        $entries = Sitemap::entries();
        $this->assertCount(6, $entries[0]['alternates']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/Unit/Services/SitemapTest.php`
Expected: FAIL — `Class "App\Services\Sitemap" not found`

- [ ] **Step 3: Write the implementation**

```php
<?php
namespace App\Services;

use App\Models\BlogModel;
use App\Models\GalleryModel;
use App\Models\ProductModel;

class Sitemap
{
    public static function paths(): array
    {
        $paths = ['/', '/shop', '/services', '/gallery', '/blog', '/contact'];

        foreach (ProductModel::allActive(Seo::DEFAULT_LANG) as $product) {
            $paths[] = '/shop/' . $product['sku'];
        }
        foreach (GalleryModel::albums(Seo::DEFAULT_LANG) as $album) {
            $paths[] = '/gallery/' . $album['slug'];
        }
        $blog = BlogModel::published(Seo::DEFAULT_LANG, 1, 1000);
        foreach ($blog['posts'] as $post) {
            $paths[] = '/blog/' . $post['slug'];
        }

        return $paths;
    }

    public static function entries(): array
    {
        $entries = [];
        foreach (self::paths() as $path) {
            foreach (Seo::LANGUAGES as $lang) {
                $entries[] = [
                    'loc'        => Seo::canonicalUrl($lang, $path),
                    'alternates' => Seo::alternateUrls($path),
                ];
            }
        }
        return $entries;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/Unit/Services/SitemapTest.php`
Expected: PASS (8 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Services/Sitemap.php tests/Unit/Services/SitemapTest.php
git commit -m "feat: add Sitemap service to enumerate indexable public pages"
```

---

### Task 5: `SeoController` + `/robots.txt` + `/sitemap.xml` routes

**Files:**
- Create: `src/Controllers/SeoController.php`
- Modify: `src/routes.php`

**Interfaces:**
- Consumes: `Seo::BASE_URL` (Task 1), `Sitemap::entries()` (Task 4).
- Produces: `GET /robots.txt` (text/plain), `GET /sitemap.xml` (application/xml). No PHP symbols consumed by later tasks.

No controller test harness exists in this codebase. Verification is manual `curl` against the local dev server, matching the CLAUDE.md-documented local serve command.

- [ ] **Step 1: Create `SeoController`**

```php
<?php
namespace App\Controllers;

use App\Services\Seo;
use App\Services\Sitemap;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SeoController extends BaseController
{
    public function robots(Request $request, Response $response, array $args): Response
    {
        $body = "User-agent: *\n"
              . "Disallow: /admin/\n"
              . "Disallow: /*/cart\n"
              . "Disallow: /*/checkout\n"
              . "Disallow: /*/order/\n"
              . "Disallow: /*/payment/\n"
              . "\n"
              . "Sitemap: " . Seo::BASE_URL . "/sitemap.xml\n";
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/plain');
    }

    public function sitemap(Request $request, Response $response, array $args): Response
    {
        $xml = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
            . 'xmlns:xhtml="http://www.w3.org/1999/xhtml"/>'
        );
        foreach (Sitemap::entries() as $entry) {
            $url = $xml->addChild('url');
            $url->addChild('loc', htmlspecialchars($entry['loc']));
            foreach ($entry['alternates'] as $alt) {
                $link = $url->addChild('xhtml:link', null, 'http://www.w3.org/1999/xhtml');
                $link->addAttribute('rel', 'alternate');
                $link->addAttribute('hreflang', $alt['lang']);
                $link->addAttribute('href', $alt['url']);
            }
        }
        $response->getBody()->write($xml->asXML());
        return $response->withHeader('Content-Type', 'application/xml');
    }
}
```

- [ ] **Step 2: Register the routes before `/{lang}/*`**

In `src/routes.php`, add the import alphabetically among the other `use App\Controllers\...` lines (between `PaymentController` and `ShopController`):

```php
use App\Controllers\SeoController;
```

Then, replace:

```php
// Redirect bare root to default language
$app->get('/', function ($req, $res) {
    return $res->withHeader('Location', '/cs/')->withStatus(302);
});

// Public
```

with:

```php
// Redirect bare root to default language
$app->get('/', function ($req, $res) {
    return $res->withHeader('Location', '/cs/')->withStatus(302);
});

// SEO — static routes — must come before /{lang}/* variable routes
$app->get('/robots.txt',  SeoController::class . ':robots');
$app->get('/sitemap.xml', SeoController::class . ':sitemap');

// Public
```

- [ ] **Step 3: Manually verify**

Run: `curl -s http://localhost:8080/robots.txt`
Expected:
```
User-agent: *
Disallow: /admin/
Disallow: /*/cart
Disallow: /*/checkout
Disallow: /*/order/
Disallow: /*/payment/

Sitemap: https://balonkydecor.cz/sitemap.xml
```

Run: `curl -s http://localhost:8080/sitemap.xml | head -20`
Expected: valid XML starting with `<?xml version="1.0" encoding="UTF-8"?>`, a `<urlset>` root with `xmlns:xhtml`, and `<url>` entries each containing a `<loc>` and five `<xhtml:link rel="alternate" hreflang="...">` children.

Run: `curl -s http://localhost:8080/sitemap.xml | python3 -c "import sys,xml.dom.minidom as m; m.parseString(sys.stdin.read())" && echo "valid XML"`
Expected: `valid XML` (no parse error)

- [ ] **Step 4: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests PASS

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/SeoController.php src/routes.php
git commit -m "feat: add dynamic /robots.txt and /sitemap.xml routes"
```

---

### Task 6: Migration `V007__gallery_meta.sql` — add meta columns to `gallery_album_t`

**Files:**
- Create: `database/migrations/V007__gallery_meta.sql`

**Interfaces:**
- Produces: `gallery_album_t.meta_title VARCHAR(255)`, `gallery_album_t.meta_desc VARCHAR(500)`. Task 7 depends on these columns existing.

- [ ] **Step 1: Write the migration**

```sql
ALTER TABLE gallery_album_t
  ADD COLUMN meta_title VARCHAR(255) DEFAULT NULL,
  ADD COLUMN meta_desc  VARCHAR(500) DEFAULT NULL;
```

- [ ] **Step 2: Apply it against the local Docker MySQL**

Run: `docker compose up -d` (if not already running), then `php -S localhost:8080 -t www` in one terminal, and in another:
```bash
TOKEN=$(php -r "echo (require 'config/settings.php')['migrate_token'];")
curl -s "http://localhost:8080/migrate.php?token=${TOKEN}"
```
Expected: `{"applied":["V007__gallery_meta"],"count":1}` (or `{"applied":[],"count":0}` if it was already applied by a previous run of this step — either is fine, but the column check in Step 3 must pass).

- [ ] **Step 3: Verify the columns exist**

Run: `docker compose exec db mysql -ubalonky -pbalonky balonkydecor -e "DESCRIBE gallery_album_t;"`
Expected: output includes rows for `meta_title` (`varchar(255)`, nullable) and `meta_desc` (`varchar(500)`, nullable).

- [ ] **Step 4: Commit**

```bash
git add database/migrations/V007__gallery_meta.sql
git commit -m "feat: add meta_title/meta_desc columns to gallery_album_t"
```

---

### Task 7: `GalleryModel` meta wiring + admin form + public album title/description

**Files:**
- Modify: `src/Models/GalleryModel.php`
- Modify: `templates/admin/gallery/form.twig`
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`
- Modify: `templates/public/gallery/album.twig`
- Test: `tests/Unit/Models/GalleryModelTest.php`

**Interfaces:**
- Consumes: `gallery_album_t.meta_title`/`meta_desc` columns (Task 6). No controller changes needed — `Admin\GalleryController` already forwards `$body['t'] ?? []` verbatim to `setAlbumTranslations()`, and public `GalleryController::album()` already forwards the full `GalleryModel::album()` row to the template.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Models/GalleryModelTest.php` (inside the `GalleryModelTest` class, after `test_album_returns_null_for_unknown`):

```php
    public function test_set_album_translations_stores_meta_fields(): void
    {
        $pdo = Database::getConnection();
        $id  = $pdo->query("SELECT id FROM gallery_albums WHERE slug='test-album'")->fetch()['id'];
        GalleryModel::setAlbumTranslations($id, [
            'en' => ['name' => 'Test Album', 'meta_title' => 'Our Test Album', 'meta_desc' => 'Photos from our test album.'],
        ]);
        $translations = GalleryModel::getAlbumTranslations($id);
        $this->assertSame('Our Test Album', $translations['en']['meta_title']);
        $this->assertSame('Photos from our test album.', $translations['en']['meta_desc']);
    }

    public function test_album_read_includes_meta_fields(): void
    {
        $pdo = Database::getConnection();
        $id  = $pdo->query("SELECT id FROM gallery_albums WHERE slug='test-album'")->fetch()['id'];
        GalleryModel::setAlbumTranslations($id, [
            'en' => ['name' => 'Test Album', 'meta_title' => 'Our Test Album', 'meta_desc' => 'Photos from our test album.'],
        ]);
        $album = GalleryModel::album('test-album', 'en');
        $this->assertSame('Our Test Album', $album['meta_title']);
        $this->assertSame('Photos from our test album.', $album['meta_desc']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Models/GalleryModelTest.php`
Expected: FAIL — undefined array key `meta_title` (columns not yet selected/written)

- [ ] **Step 3: Update `GalleryModel`**

In `src/Models/GalleryModel.php`, replace the `album()` query:

```php
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
```

with:

```php
    public static function album(string $slug, string $lang): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT a.id, a.slug, a.cover_image,
                   COALESCE(t.name, a.slug) AS name, t.description, t.meta_title, t.meta_desc
            FROM gallery_albums a
            LEFT JOIN gallery_album_t t ON t.album_id = a.id AND t.lang_code = :lang
            WHERE a.slug = :slug
        ');
```

Replace `getAlbumTranslations()` and `setAlbumTranslations()`:

```php
    public static function getAlbumTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, name, description FROM gallery_album_t WHERE album_id = ?');
        $stmt->execute([$id]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['lang_code']] = $row;
        }
        return $result;
    }

    public static function setAlbumTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO gallery_album_t (album_id, lang_code, name, description)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)'
        );
        foreach ($translations as $lang => $t) {
            if (empty($t['name'])) continue;
            $stmt->execute([$id, $lang, $t['name'], $t['description'] ?? '']);
        }
    }
```

with:

```php
    public static function getAlbumTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, name, description, meta_title, meta_desc FROM gallery_album_t WHERE album_id = ?');
        $stmt->execute([$id]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['lang_code']] = $row;
        }
        return $result;
    }

    public static function setAlbumTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO gallery_album_t (album_id, lang_code, name, description, meta_title, meta_desc)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
                                     meta_title = VALUES(meta_title), meta_desc = VALUES(meta_desc)'
        );
        foreach ($translations as $lang => $t) {
            if (empty($t['name'])) continue;
            $stmt->execute([$id, $lang, $t['name'], $t['description'] ?? '', $t['meta_title'] ?? null, $t['meta_desc'] ?? null]);
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Models/GalleryModelTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Add admin form fields**

In `templates/admin/gallery/form.twig`, replace:

```twig
    {% for lang in langs %}
    <div class="lang-panel {% if loop.first %}active{% endif %}" id="panel-{{ lang }}">
        <div class="form-group">
            <label>{{ t('gallery.form.name_label') }} ({{ lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][name]" value="{{ translations[lang].name ?? '' }}">
        </div>
        <div class="form-group">
            <label>{{ t('gallery.form.desc_label') }} ({{ lang|upper }})</label>
            <textarea name="t[{{ lang }}][description]">{{ translations[lang].description ?? '' }}</textarea>
        </div>
    </div>
    {% endfor %}
```

with:

```twig
    {% for lang in langs %}
    <div class="lang-panel {% if loop.first %}active{% endif %}" id="panel-{{ lang }}">
        <div class="form-group">
            <label>{{ t('gallery.form.name_label') }} ({{ lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][name]" value="{{ translations[lang].name ?? '' }}">
        </div>
        <div class="form-group">
            <label>{{ t('gallery.form.desc_label') }} ({{ lang|upper }})</label>
            <textarea name="t[{{ lang }}][description]">{{ translations[lang].description ?? '' }}</textarea>
        </div>
        <div class="form-group">
            <label>{{ t('gallery.form.meta_title_label') }} ({{ lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][meta_title]" value="{{ translations[lang].meta_title ?? '' }}" maxlength="255">
        </div>
        <div class="form-group">
            <label>{{ t('gallery.form.meta_desc_label') }} ({{ lang|upper }})</label>
            <textarea name="t[{{ lang }}][meta_desc]" maxlength="500">{{ translations[lang].meta_desc ?? '' }}</textarea>
        </div>
    </div>
    {% endfor %}
```

- [ ] **Step 6: Add admin translation keys to all 5 `lang/admin/*.json` files**

In `lang/admin/cs.json`, replace:
```json
  "gallery.form.existing_photos": "Fotky v albu",
  "gallery.form.name_label": "Název alba",
```
with:
```json
  "gallery.form.existing_photos": "Fotky v albu",
  "gallery.form.meta_desc_label": "SEO popis (meta description)",
  "gallery.form.meta_title_label": "SEO titulek",
  "gallery.form.name_label": "Název alba",
```

In `lang/admin/en.json`, replace:
```json
  "gallery.form.existing_photos": "Album photos",
  "gallery.form.name_label": "Album name",
```
with:
```json
  "gallery.form.existing_photos": "Album photos",
  "gallery.form.meta_desc_label": "SEO description (meta description)",
  "gallery.form.meta_title_label": "SEO title",
  "gallery.form.name_label": "Album name",
```

In `lang/admin/ru.json`, replace:
```json
  "gallery.form.existing_photos": "Фото в альбоме",
  "gallery.form.name_label": "Название альбома",
```
with:
```json
  "gallery.form.existing_photos": "Фото в альбоме",
  "gallery.form.meta_desc_label": "SEO-описание (meta description)",
  "gallery.form.meta_title_label": "SEO-заголовок",
  "gallery.form.name_label": "Название альбома",
```

In `lang/admin/uk.json`, replace:
```json
  "gallery.form.existing_photos": "Фото в альбомі",
  "gallery.form.name_label": "Назва альбому",
```
with:
```json
  "gallery.form.existing_photos": "Фото в альбомі",
  "gallery.form.meta_desc_label": "SEO-опис (meta description)",
  "gallery.form.meta_title_label": "SEO-заголовок",
  "gallery.form.name_label": "Назва альбому",
```

In `lang/admin/sk.json`, replace:
```json
  "gallery.form.existing_photos": "Fotky v albume",
  "gallery.form.name_label": "Názov albumu",
```
with:
```json
  "gallery.form.existing_photos": "Fotky v albume",
  "gallery.form.meta_desc_label": "SEO popis (meta description)",
  "gallery.form.meta_title_label": "SEO titulok",
  "gallery.form.name_label": "Názov albumu",
```

- [ ] **Step 7: Fix the public album title/description blocks**

In `templates/public/gallery/album.twig`, replace:

```twig
{% block title %}{{ album.name }} — {{ t('site.name') }}{% endblock %}

{% block content %}
```

with:

```twig
{% block title %}{{ album.meta_title ?? album.name }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{{ album.meta_desc ?? '' }}{% endblock %}

{% block content %}
```

- [ ] **Step 8: Manually verify the admin form and public page**

Run: `php -S localhost:8080 -t www`, log into `/admin/gallery`, edit any album, fill in "SEO title"/"SEO description" (EN tab), save, then:
`curl -s http://localhost:8080/en/gallery/<slug> | grep -E '<title>|meta name="description"'`
Expected: the `<title>` and `<meta name="description">` reflect the values just entered.

- [ ] **Step 9: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests PASS

- [ ] **Step 10: Commit**

```bash
git add src/Models/GalleryModel.php templates/admin/gallery/form.twig lang/admin/*.json templates/public/gallery/album.twig tests/Unit/Models/GalleryModelTest.php
git commit -m "feat: wire gallery album meta_title/meta_desc through admin form and public page"
```

---

### Task 8: `ProductModel` meta wiring + admin form + public product title

**Files:**
- Modify: `src/Models/ProductModel.php`
- Modify: `templates/admin/products/form.twig`
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`
- Modify: `templates/public/shop/product.twig`
- Test: `tests/Unit/Models/ProductModelTest.php`

**Interfaces:**
- Consumes: nothing new — `product_t.meta_title`/`meta_desc` columns already exist (`V001`). No controller changes needed — `Admin\ProductController` already forwards `$body['t'] ?? []` to `setTranslations()`, and public `ShopController::product()` already forwards the full `ProductModel::findBySku()` row (which already selects `meta_title`/`meta_desc`) to the template.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Models/ProductModelTest.php` (inside the class, after `test_filter_by_category`):

```php
    public function test_set_translations_stores_meta_fields(): void
    {
        $pdo = Database::getConnection();
        $id  = $pdo->query("SELECT id FROM products WHERE sku='TEST-SKU-001'")->fetch()['id'];
        ProductModel::setTranslations($id, [
            'en' => ['name' => 'Test Product', 'meta_title' => 'Buy Test Product', 'meta_desc' => 'Best test product in town.'],
        ]);
        $translations = ProductModel::getTranslations($id);
        $this->assertSame('Buy Test Product', $translations['en']['meta_title']);
        $this->assertSame('Best test product in town.', $translations['en']['meta_desc']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php`
Expected: FAIL — undefined array key `meta_title`

- [ ] **Step 3: Update `ProductModel`**

In `src/Models/ProductModel.php`, replace `getTranslations()` and `setTranslations()`:

```php
    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, name, description FROM product_t WHERE product_id = ?');
        $stmt->execute([$id]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['lang_code']] = $row;
        }
        return $result;
    }

    public static function setTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO product_t (product_id, lang_code, name, description)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)'
        );
        foreach ($translations as $lang => $t) {
            if (empty($t['name'])) continue;
            $stmt->execute([$id, $lang, $t['name'], $t['description'] ?? '']);
        }
    }
```

with:

```php
    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, name, description, meta_title, meta_desc FROM product_t WHERE product_id = ?');
        $stmt->execute([$id]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['lang_code']] = $row;
        }
        return $result;
    }

    public static function setTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO product_t (product_id, lang_code, name, description, meta_title, meta_desc)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
                                     meta_title = VALUES(meta_title), meta_desc = VALUES(meta_desc)'
        );
        foreach ($translations as $lang => $t) {
            if (empty($t['name'])) continue;
            $stmt->execute([$id, $lang, $t['name'], $t['description'] ?? '', $t['meta_title'] ?? null, $t['meta_desc'] ?? null]);
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Add admin form fields**

In `templates/admin/products/form.twig`, replace:

```twig
    {% for lang in langs %}
    <div class="lang-panel {% if loop.first %}active{% endif %}" id="panel-{{ lang }}">
        <div class="form-group">
            <label>{{ t('products.form.name_label') }} ({{ lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][name]" value="{{ translations[lang].name ?? '' }}">
        </div>
        <div class="form-group">
            <label>{{ t('products.form.desc_label') }} ({{ lang|upper }})</label>
            <textarea name="t[{{ lang }}][description]">{{ translations[lang].description ?? '' }}</textarea>
        </div>
    </div>
    {% endfor %}
```

with:

```twig
    {% for lang in langs %}
    <div class="lang-panel {% if loop.first %}active{% endif %}" id="panel-{{ lang }}">
        <div class="form-group">
            <label>{{ t('products.form.name_label') }} ({{ lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][name]" value="{{ translations[lang].name ?? '' }}">
        </div>
        <div class="form-group">
            <label>{{ t('products.form.desc_label') }} ({{ lang|upper }})</label>
            <textarea name="t[{{ lang }}][description]">{{ translations[lang].description ?? '' }}</textarea>
        </div>
        <div class="form-group">
            <label>{{ t('products.form.meta_title_label') }} ({{ lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][meta_title]" value="{{ translations[lang].meta_title ?? '' }}" maxlength="255">
        </div>
        <div class="form-group">
            <label>{{ t('products.form.meta_desc_label') }} ({{ lang|upper }})</label>
            <textarea name="t[{{ lang }}][meta_desc]" maxlength="500">{{ translations[lang].meta_desc ?? '' }}</textarea>
        </div>
    </div>
    {% endfor %}
```

- [ ] **Step 6: Add admin translation keys to all 5 `lang/admin/*.json` files**

In `lang/admin/cs.json`, replace:
```json
  "products.form.existing_images": "Stávající obrázky",
  "products.form.name_label": "Název",
```
with:
```json
  "products.form.existing_images": "Stávající obrázky",
  "products.form.meta_desc_label": "SEO popis (meta description)",
  "products.form.meta_title_label": "SEO titulek",
  "products.form.name_label": "Název",
```

In `lang/admin/en.json`, replace:
```json
  "products.form.existing_images": "Existing images",
  "products.form.name_label": "Name",
```
with:
```json
  "products.form.existing_images": "Existing images",
  "products.form.meta_desc_label": "SEO description (meta description)",
  "products.form.meta_title_label": "SEO title",
  "products.form.name_label": "Name",
```

In `lang/admin/ru.json`, replace:
```json
  "products.form.existing_images": "Существующие изображения",
  "products.form.name_label": "Название",
```
with:
```json
  "products.form.existing_images": "Существующие изображения",
  "products.form.meta_desc_label": "SEO-описание (meta description)",
  "products.form.meta_title_label": "SEO-заголовок",
  "products.form.name_label": "Название",
```

In `lang/admin/uk.json`, replace:
```json
  "products.form.existing_images": "Наявні зображення",
  "products.form.name_label": "Назва",
```
with:
```json
  "products.form.existing_images": "Наявні зображення",
  "products.form.meta_desc_label": "SEO-опис (meta description)",
  "products.form.meta_title_label": "SEO-заголовок",
  "products.form.name_label": "Назва",
```

In `lang/admin/sk.json`, replace:
```json
  "products.form.existing_images": "Existujúce obrázky",
  "products.form.name_label": "Názov",
```
with:
```json
  "products.form.existing_images": "Existujúce obrázky",
  "products.form.meta_desc_label": "SEO popis (meta description)",
  "products.form.meta_title_label": "SEO titulok",
  "products.form.name_label": "Názov",
```

- [ ] **Step 7: Fix the public product title block**

In `templates/public/shop/product.twig`, replace:

```twig
{% block title %}{{ product.name }} — {{ t('site.name') }}{% endblock %}
```

with:

```twig
{% block title %}{{ product.meta_title ?? product.name }} — {{ t('site.name') }}{% endblock %}
```

(`{% block meta_desc %}` on this template already reads `product.meta_desc` — no change needed there.)

- [ ] **Step 8: Manually verify**

Run: edit a product in `/admin/products`, fill in "SEO title"/"SEO description" (EN tab), save, then:
`curl -s http://localhost:8080/en/shop/<sku> | grep -E '<title>|meta name="description"'`
Expected: reflects the values just entered.

- [ ] **Step 9: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests PASS

- [ ] **Step 10: Commit**

```bash
git add src/Models/ProductModel.php templates/admin/products/form.twig lang/admin/*.json templates/public/shop/product.twig tests/Unit/Models/ProductModelTest.php
git commit -m "feat: wire product meta_title/meta_desc through admin form and public page"
```

---

### Task 9: `BlogModel` meta wiring + admin form + public post title

**Files:**
- Modify: `src/Models/BlogModel.php`
- Modify: `templates/admin/blog/form.twig`
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`
- Modify: `templates/public/blog/post.twig`
- Test: `tests/Unit/Models/BlogModelTest.php`

**Interfaces:**
- Consumes: nothing new — `blog_post_t.meta_title`/`meta_desc` columns already exist (`V001`). No controller changes needed — `Admin\BlogController` already forwards `$body['t'] ?? []` to `setTranslations()`, and public `BlogController::post()` already forwards the full `BlogModel::findBySlug()` row (already selects `meta_title`/`meta_desc`) to the template.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Models/BlogModelTest.php` (inside the class, after `test_find_by_slug_returns_null_for_draft`):

```php
    public function test_set_translations_stores_meta_fields(): void
    {
        $pdo = Database::getConnection();
        $id  = $pdo->query("SELECT id FROM blog_posts WHERE slug='test-post'")->fetch()['id'];
        BlogModel::setTranslations($id, [
            'en' => ['title' => 'Test Post', 'meta_title' => 'Test Post Title', 'meta_desc' => 'A short excerpt.'],
        ]);
        $translations = BlogModel::getTranslations($id);
        $this->assertSame('Test Post Title', $translations['en']['meta_title']);
        $this->assertSame('A short excerpt.', $translations['en']['meta_desc']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/Unit/Models/BlogModelTest.php`
Expected: FAIL — undefined array key `meta_title`

- [ ] **Step 3: Update `BlogModel`**

In `src/Models/BlogModel.php`, replace `getTranslations()` and `setTranslations()`:

```php
    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, title, body FROM blog_post_t WHERE post_id = ?');
        $stmt->execute([$id]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['lang_code']] = $row;
        }
        return $result;
    }

    public static function setTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO blog_post_t (post_id, lang_code, title, body)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body)'
        );
        foreach ($translations as $lang => $t) {
            if (empty($t['title'])) continue;
            $stmt->execute([$id, $lang, $t['title'], $t['body'] ?? '']);
        }
    }
```

with:

```php
    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, title, body, meta_title, meta_desc FROM blog_post_t WHERE post_id = ?');
        $stmt->execute([$id]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['lang_code']] = $row;
        }
        return $result;
    }

    public static function setTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO blog_post_t (post_id, lang_code, title, body, meta_title, meta_desc)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body),
                                     meta_title = VALUES(meta_title), meta_desc = VALUES(meta_desc)'
        );
        foreach ($translations as $lang => $t) {
            if (empty($t['title'])) continue;
            $stmt->execute([$id, $lang, $t['title'], $t['body'] ?? '', $t['meta_title'] ?? null, $t['meta_desc'] ?? null]);
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/Unit/Models/BlogModelTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Add admin form fields**

In `templates/admin/blog/form.twig`, replace:

```twig
    {% for lang in langs %}
    <div class="lang-panel {% if loop.first %}active{% endif %}" id="panel-{{ lang }}">
        <div class="form-group">
            <label>{{ t('blog.form.title_label') }} ({{ lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][title]" value="{{ translations[lang].title ?? '' }}">
        </div>
        <div class="form-group">
            <label>{{ t('blog.form.body_label') }} ({{ lang|upper }})</label>
            <textarea name="t[{{ lang }}][body]" style="min-height:300px;">{{ translations[lang].body ?? '' }}</textarea>
        </div>
    </div>
    {% endfor %}
```

with:

```twig
    {% for lang in langs %}
    <div class="lang-panel {% if loop.first %}active{% endif %}" id="panel-{{ lang }}">
        <div class="form-group">
            <label>{{ t('blog.form.title_label') }} ({{ lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][title]" value="{{ translations[lang].title ?? '' }}">
        </div>
        <div class="form-group">
            <label>{{ t('blog.form.body_label') }} ({{ lang|upper }})</label>
            <textarea name="t[{{ lang }}][body]" style="min-height:300px;">{{ translations[lang].body ?? '' }}</textarea>
        </div>
        <div class="form-group">
            <label>{{ t('blog.form.meta_title_label') }} ({{ lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][meta_title]" value="{{ translations[lang].meta_title ?? '' }}" maxlength="255">
        </div>
        <div class="form-group">
            <label>{{ t('blog.form.meta_desc_label') }} ({{ lang|upper }})</label>
            <textarea name="t[{{ lang }}][meta_desc]" maxlength="500">{{ translations[lang].meta_desc ?? '' }}</textarea>
        </div>
    </div>
    {% endfor %}
```

- [ ] **Step 6: Add admin translation keys to all 5 `lang/admin/*.json` files**

In `lang/admin/cs.json`, replace:
```json
  "blog.form.cancel": "Zrušit",
  "blog.form.published_at": "Datum publikace",
```
with:
```json
  "blog.form.cancel": "Zrušit",
  "blog.form.meta_desc_label": "SEO popis (meta description)",
  "blog.form.meta_title_label": "SEO titulek",
  "blog.form.published_at": "Datum publikace",
```

In `lang/admin/en.json`, replace:
```json
  "blog.form.cancel": "Cancel",
  "blog.form.published_at": "Publication date",
```
with:
```json
  "blog.form.cancel": "Cancel",
  "blog.form.meta_desc_label": "SEO description (meta description)",
  "blog.form.meta_title_label": "SEO title",
  "blog.form.published_at": "Publication date",
```

In `lang/admin/ru.json`, replace:
```json
  "blog.form.cancel": "Отмена",
  "blog.form.published_at": "Дата публикации",
```
with:
```json
  "blog.form.cancel": "Отмена",
  "blog.form.meta_desc_label": "SEO-описание (meta description)",
  "blog.form.meta_title_label": "SEO-заголовок",
  "blog.form.published_at": "Дата публикации",
```

In `lang/admin/uk.json`, replace:
```json
  "blog.form.cancel": "Скасувати",
  "blog.form.published_at": "Дата публікації",
```
with:
```json
  "blog.form.cancel": "Скасувати",
  "blog.form.meta_desc_label": "SEO-опис (meta description)",
  "blog.form.meta_title_label": "SEO-заголовок",
  "blog.form.published_at": "Дата публікації",
```

In `lang/admin/sk.json`, replace:
```json
  "blog.form.cancel": "Zrušiť",
  "blog.form.published_at": "Dátum publikácie",
```
with:
```json
  "blog.form.cancel": "Zrušiť",
  "blog.form.meta_desc_label": "SEO popis (meta description)",
  "blog.form.meta_title_label": "SEO titulok",
  "blog.form.published_at": "Dátum publikácie",
```

- [ ] **Step 7: Fix the public post title block**

In `templates/public/blog/post.twig`, replace:

```twig
{% block title %}{{ post.title }} — {{ t('site.name') }}{% endblock %}
```

with:

```twig
{% block title %}{{ post.meta_title ?? post.title }} — {{ t('site.name') }}{% endblock %}
```

(`{% block meta_desc %}` on this template already reads `post.meta_desc` — no change needed there.)

- [ ] **Step 8: Manually verify**

Run: edit a blog post in `/admin/blog`, fill in "SEO title"/"SEO description" (EN tab), save, then:
`curl -s http://localhost:8080/en/blog/<slug> | grep -E '<title>|meta name="description"'`
Expected: reflects the values just entered.

- [ ] **Step 9: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests PASS

- [ ] **Step 10: Commit**

```bash
git add src/Models/BlogModel.php templates/admin/blog/form.twig lang/admin/*.json templates/public/blog/post.twig tests/Unit/Models/BlogModelTest.php
git commit -m "feat: wire blog post meta_title/meta_desc through admin form and public page"
```

---

### Task 10: `PageModel` meta wiring + admin form

**Files:**
- Modify: `src/Models/PageModel.php`
- Modify: `src/Controllers/Admin/PageController.php`
- Modify: `templates/admin/pages/form.twig`
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`
- Test: `tests/Unit/Models/PageModelTest.php`

**Interfaces:**
- Produces: `PageModel::upsert(string $slug, string $lang, string $title, string $body, ?string $metaTitle = null, ?string $metaDesc = null): void` (signature change — 2 new optional trailing params), `PageModel::allTranslations(string $slug): array` now includes `meta_title`/`meta_desc` per row. Task 11 consumes `PageModel::find()`, which is unchanged in signature but was already selecting these columns (they were just never written until this task).

This is the one content type where, unlike Tasks 7–9, the admin *controller* also needs a change (`PageController::editSubmit()` calls `upsert()` positionally rather than forwarding a translations array).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Models/PageModelTest.php` (inside the class, after `test_find_returns_null_for_unknown`):

```php
    public function test_upsert_stores_meta_fields(): void
    {
        PageModel::upsert('services', 'en', 'Services', '<p>Our services</p>', 'Our Balloon Services', 'Book balloon decoration services.');
        $page = PageModel::find('services', 'en');
        $this->assertSame('Our Balloon Services', $page['meta_title']);
        $this->assertSame('Book balloon decoration services.', $page['meta_desc']);
    }

    public function test_all_translations_includes_meta_fields(): void
    {
        PageModel::upsert('services', 'en', 'Services', '<p>Our services</p>', 'Our Balloon Services', 'Book balloon decoration services.');
        $translations = PageModel::allTranslations('services');
        $this->assertSame('Our Balloon Services', $translations['en']['meta_title']);
        $this->assertSame('Book balloon decoration services.', $translations['en']['meta_desc']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Models/PageModelTest.php`
Expected: FAIL — `Too many arguments` (current `upsert()` only takes 4 params) / undefined array key `meta_title`

- [ ] **Step 3: Update `PageModel`**

Replace the full contents of `src/Models/PageModel.php`:

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

    public static function allSlugs(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query('SELECT slug FROM pages ORDER BY slug')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public static function allTranslations(string $slug): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT pt.lang_code, pt.title, pt.body, pt.meta_title, pt.meta_desc
             FROM pages p
             LEFT JOIN page_t pt ON pt.page_id = p.id
             WHERE p.slug = ?'
        );
        $stmt->execute([$slug]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['lang_code']) {
                $result[$row['lang_code']] = $row;
            }
        }
        return $result;
    }

    public static function upsert(string $slug, string $lang, string $title, string $body, ?string $metaTitle = null, ?string $metaDesc = null): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id FROM pages WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $page = $stmt->fetch();
        if (!$page) {
            $pdo->prepare('INSERT INTO pages (slug) VALUES (?)')->execute([$slug]);
            $pageId = (int) $pdo->lastInsertId();
        } else {
            $pageId = (int) $page['id'];
        }
        $pdo->prepare(
            'INSERT INTO page_t (page_id, lang_code, title, body, meta_title, meta_desc) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body),
                                     meta_title = VALUES(meta_title), meta_desc = VALUES(meta_desc)'
        )->execute([$pageId, $lang, $title, $body, $metaTitle, $metaDesc]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Models/PageModelTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Update `PageController::editSubmit()`**

In `src/Controllers/Admin/PageController.php`, replace:

```php
        foreach (self::LANGS as $lang) {
            $t = $body['t'][$lang] ?? [];
            PageModel::upsert($slug, $lang, $t['title'] ?? '', $t['body'] ?? '');
        }
```

with:

```php
        foreach (self::LANGS as $lang) {
            $t = $body['t'][$lang] ?? [];
            PageModel::upsert($slug, $lang, $t['title'] ?? '', $t['body'] ?? '', $t['meta_title'] ?? null, $t['meta_desc'] ?? null);
        }
```

- [ ] **Step 6: Add admin form fields**

In `templates/admin/pages/form.twig`, replace:

```twig
    {% for lang in langs %}
    <div class="lang-panel {% if loop.first %}active{% endif %}" id="panel-{{ lang }}">
        <div class="form-group">
            <label>{{ t('pages.form.title_label') }} ({{ lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][title]" value="{{ translations[lang].title ?? '' }}">
        </div>
        <div class="form-group">
            <label>{{ t('pages.form.body_label') }} ({{ lang|upper }})</label>
            <textarea name="t[{{ lang }}][body]" style="min-height:300px;">{{ translations[lang].body ?? '' }}</textarea>
        </div>
    </div>
    {% endfor %}
```

with:

```twig
    {% for lang in langs %}
    <div class="lang-panel {% if loop.first %}active{% endif %}" id="panel-{{ lang }}">
        <div class="form-group">
            <label>{{ t('pages.form.title_label') }} ({{ lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][title]" value="{{ translations[lang].title ?? '' }}">
        </div>
        <div class="form-group">
            <label>{{ t('pages.form.body_label') }} ({{ lang|upper }})</label>
            <textarea name="t[{{ lang }}][body]" style="min-height:300px;">{{ translations[lang].body ?? '' }}</textarea>
        </div>
        <div class="form-group">
            <label>{{ t('pages.form.meta_title_label') }} ({{ lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][meta_title]" value="{{ translations[lang].meta_title ?? '' }}" maxlength="255">
        </div>
        <div class="form-group">
            <label>{{ t('pages.form.meta_desc_label') }} ({{ lang|upper }})</label>
            <textarea name="t[{{ lang }}][meta_desc]" maxlength="500">{{ translations[lang].meta_desc ?? '' }}</textarea>
        </div>
    </div>
    {% endfor %}
```

- [ ] **Step 7: Add admin translation keys to all 5 `lang/admin/*.json` files**

In `lang/admin/cs.json`, replace:
```json
  "pages.form.cancel": "Zrušit",
  "pages.form.save": "Uložit",
```
with:
```json
  "pages.form.cancel": "Zrušit",
  "pages.form.meta_desc_label": "SEO popis (meta description)",
  "pages.form.meta_title_label": "SEO titulek",
  "pages.form.save": "Uložit",
```

In `lang/admin/en.json`, replace:
```json
  "pages.form.cancel": "Cancel",
  "pages.form.save": "Save",
```
with:
```json
  "pages.form.cancel": "Cancel",
  "pages.form.meta_desc_label": "SEO description (meta description)",
  "pages.form.meta_title_label": "SEO title",
  "pages.form.save": "Save",
```

In `lang/admin/ru.json`, replace:
```json
  "pages.form.cancel": "Отмена",
  "pages.form.save": "Сохранить",
```
with:
```json
  "pages.form.cancel": "Отмена",
  "pages.form.meta_desc_label": "SEO-описание (meta description)",
  "pages.form.meta_title_label": "SEO-заголовок",
  "pages.form.save": "Сохранить",
```

In `lang/admin/uk.json`, replace:
```json
  "pages.form.cancel": "Скасувати",
  "pages.form.save": "Зберегти",
```
with:
```json
  "pages.form.cancel": "Скасувати",
  "pages.form.meta_desc_label": "SEO-опис (meta description)",
  "pages.form.meta_title_label": "SEO-заголовок",
  "pages.form.save": "Зберегти",
```

In `lang/admin/sk.json`, replace:
```json
  "pages.form.cancel": "Zrušiť",
  "pages.form.save": "Uložiť",
```
with:
```json
  "pages.form.cancel": "Zrušiť",
  "pages.form.meta_desc_label": "SEO popis (meta description)",
  "pages.form.meta_title_label": "SEO titulok",
  "pages.form.save": "Uložiť",
```

- [ ] **Step 8: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests PASS

- [ ] **Step 9: Commit**

```bash
git add src/Models/PageModel.php src/Controllers/Admin/PageController.php templates/admin/pages/form.twig lang/admin/*.json tests/Unit/Models/PageModelTest.php
git commit -m "feat: wire page meta_title/meta_desc through admin form"
```

---

### Task 11: Wire page meta into public home/services/contact templates

**Files:**
- Modify: `src/Controllers/HomeController.php`
- Modify: `src/Controllers/ContactController.php`
- Modify: `templates/public/home.twig`
- Modify: `templates/public/services.twig` (template only — `src/Controllers/PageController.php` already passes `page`, no controller change needed)
- Modify: `templates/public/contact.twig`

**Interfaces:**
- Consumes: `PageModel::find(string $slug, string $lang): ?array` (Task 10's admin wiring makes this return real data instead of always-null `meta_title`/`meta_desc`).

Only `PageController::services()` currently fetches `PageModel::find()`. `HomeController::index()` renders `home.twig` with **no data at all**, and `ContactController` never fetches page data either — so even after Task 10, an admin editing the "home" or "contact" page's SEO fields would see no effect. This task closes that gap.

- [ ] **Step 1: Update `HomeController`**

Replace the full contents of `src/Controllers/HomeController.php`:

```php
<?php
namespace App\Controllers;

use App\Models\PageModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HomeController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        return $this->render($request, $response, 'public/home.twig', [
            'page' => PageModel::find('home', $lang),
        ]);
    }
}
```

- [ ] **Step 2: Update `templates/public/home.twig`**

Replace:

```twig
{% block title %}{{ t('home.hero_title') }} — {{ t('site.name') }}{% endblock %}
```

with:

```twig
{% block title %}{{ page.meta_title ?? t('home.hero_title') }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{{ page.meta_desc ?? '' }}{% endblock %}
```

- [ ] **Step 3: Update `templates/public/services.twig`**

Replace:

```twig
{% block title %}{{ t('services.title') }} — {{ t('site.name') }}{% endblock %}
```

with:

```twig
{% block title %}{{ page.meta_title ?? t('services.title') }} — {{ t('site.name') }}{% endblock %}
```

(`{% block meta_desc %}` on this template already reads `page.meta_desc` — no change needed there. `PageController::services()` already passes `page` — no controller change needed.)

- [ ] **Step 4: Update `ContactController`**

Replace the full contents of `src/Controllers/ContactController.php`:

```php
<?php
namespace App\Controllers;

use App\Models\Database;
use App\Models\PageModel;
use App\Services\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ContactController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        return $this->render($request, $response, 'public/contact.twig', [
            'success' => false,
            'error'   => false,
            'page'    => PageModel::find('contact', $lang),
        ]);
    }

    public function send(Request $request, Response $response, array $args): Response
    {
        $lang    = $request->getAttribute('lang');
        $body    = (array) $request->getParsedBody();
        $name    = trim($body['name']    ?? '');
        $email   = trim($body['email']   ?? '');
        $message = trim($body['message'] ?? '');
        $page    = PageModel::find('contact', $lang);

        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$message) {
            return $this->render($request, $response, 'public/contact.twig', [
                'success' => false,
                'error'   => true,
                'values'  => ['name' => $name, 'email' => $email, 'message' => $message],
                'page'    => $page,
            ]);
        }

        $pdo     = Database::getConnection();
        $setting = $pdo->query("SELECT value FROM settings WHERE `key`='contact_email'")->fetchColumn();
        $adminTo = $setting ?: 'admin@balonkydecor.cz';

        $html = "<p><strong>Name:</strong> " . htmlspecialchars($name) . "</p>"
              . "<p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>"
              . "<p><strong>Message:</strong></p>"
              . "<p>" . nl2br(htmlspecialchars($message)) . "</p>";

        Mailer::send($adminTo, "Contact form: {$name}", $html, $email);

        return $this->render($request, $response, 'public/contact.twig', [
            'success' => true,
            'error'   => false,
            'page'    => $page,
        ]);
    }
}
```

- [ ] **Step 5: Update `templates/public/contact.twig`**

Replace:

```twig
{% block title %}{{ t('contact.title') }} — {{ t('site.name') }}{% endblock %}
```

with:

```twig
{% block title %}{{ page.meta_title ?? t('contact.title') }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{{ page.meta_desc ?? '' }}{% endblock %}
```

- [ ] **Step 6: Manually verify**

Run: log into `/admin/pages`, edit "home", fill in "SEO title"/"SEO description" (EN tab), save, then:
`curl -s http://localhost:8080/en/ | grep -E '<title>|meta name="description"'`
Expected: reflects the values just entered. Repeat for "contact" against `http://localhost:8080/en/contact`.

- [ ] **Step 7: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests PASS (this task touches no Model logic, only controllers/templates)

- [ ] **Step 8: Commit**

```bash
git add src/Controllers/HomeController.php src/Controllers/ContactController.php templates/public/home.twig templates/public/services.twig templates/public/contact.twig
git commit -m "feat: wire home/services/contact page meta_title/meta_desc into public templates"
```

---

### Task 12: Product JSON-LD (`Product` + `BreadcrumbList`) on the product detail page

**Files:**
- Modify: `templates/public/shop/product.twig`

**Interfaces:**
- Consumes: `base_url`, `canonical_url`, `lang` (Task 2, already available in every public template); `product.name`, `product.sku`, `product.price`, `product.stock_type`, `product.stock_qty`, `product.description`, `product.images` (all already selected by `ProductModel::findBySku()` — no Model change needed).

- [ ] **Step 1: Add the JSON-LD block**

In `templates/public/shop/product.twig`, replace:

```twig
{% block meta_desc %}{{ product.meta_desc ?? product.description|striptags|slice(0, 160) }}{% endblock %}

{% block content %}
```

with:

```twig
{% block meta_desc %}{{ product.meta_desc ?? product.description|striptags|slice(0, 160) }}{% endblock %}

{% block head %}
{% set product_schema = {
    '@context': 'https://schema.org',
    '@type': 'Product',
    'name': product.name,
    'description': product.description ? product.description|striptags : '',
    'sku': product.sku,
    'offers': {
        '@type': 'Offer',
        'price': product.price,
        'priceCurrency': 'CZK',
        'availability': (product.stock_type == 'limited' and product.stock_qty <= 0) ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
        'url': canonical_url
    }
} %}
{% if product.images %}
    {% set product_schema = product_schema|merge({'image': base_url ~ '/assets/uploads/' ~ product.images[0]}) %}
{% endif %}
<script type="application/ld+json">{{ product_schema|json_encode|raw }}</script>
<script type="application/ld+json">{{ {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    'itemListElement': [
        {'@type': 'ListItem', 'position': 1, 'name': t('nav.home'), 'item': base_url ~ '/' ~ lang ~ '/'},
        {'@type': 'ListItem', 'position': 2, 'name': t('nav.shop'), 'item': base_url ~ '/' ~ lang ~ '/shop'},
        {'@type': 'ListItem', 'position': 3, 'name': product.name, 'item': canonical_url}
    ]
}|json_encode|raw }}</script>
{% endblock %}

{% block content %}
```

- [ ] **Step 2: Manually verify**

Run: `curl -s http://localhost:8080/en/shop/<sku> | grep -A5 'application/ld+json'`
Expected: two `<script type="application/ld+json">` blocks — one with `"@type":"Product"` containing an `offers` object with `price`/`priceCurrency`/`availability`, one with `"@type":"BreadcrumbList"` containing 3 `ListItem` entries.

Paste the raw JSON from each script tag into Google's Rich Results Test (https://search.google.com/test/rich-results) and confirm no errors.

- [ ] **Step 3: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests PASS (template-only change, no PHP logic touched)

- [ ] **Step 4: Commit**

```bash
git add templates/public/shop/product.twig
git commit -m "feat: add Product and BreadcrumbList JSON-LD to product detail page"
```

---

### Task 13: Breadcrumb JSON-LD on blog post and gallery album pages

**Files:**
- Modify: `templates/public/blog/post.twig`
- Modify: `templates/public/gallery/album.twig`

**Interfaces:**
- Consumes: `base_url`, `canonical_url`, `lang` (Task 2); `post.title` / `album.name` (already available).

- [ ] **Step 1: Add breadcrumb JSON-LD to the blog post template**

In `templates/public/blog/post.twig`, replace:

```twig
{% block meta_desc %}{{ post.meta_desc ?? '' }}{% endblock %}

{% block content %}
```

with:

```twig
{% block meta_desc %}{{ post.meta_desc ?? '' }}{% endblock %}

{% block head %}
<script type="application/ld+json">{{ {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    'itemListElement': [
        {'@type': 'ListItem', 'position': 1, 'name': t('nav.home'), 'item': base_url ~ '/' ~ lang ~ '/'},
        {'@type': 'ListItem', 'position': 2, 'name': t('nav.blog'), 'item': base_url ~ '/' ~ lang ~ '/blog'},
        {'@type': 'ListItem', 'position': 3, 'name': post.title, 'item': canonical_url}
    ]
}|json_encode|raw }}</script>
{% endblock %}

{% block content %}
```

- [ ] **Step 2: Add breadcrumb JSON-LD to the gallery album template**

In `templates/public/gallery/album.twig`, replace:

```twig
{% block meta_desc %}{{ album.meta_desc ?? '' }}{% endblock %}

{% block content %}
```

with:

```twig
{% block meta_desc %}{{ album.meta_desc ?? '' }}{% endblock %}

{% block head %}
<script type="application/ld+json">{{ {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    'itemListElement': [
        {'@type': 'ListItem', 'position': 1, 'name': t('nav.home'), 'item': base_url ~ '/' ~ lang ~ '/'},
        {'@type': 'ListItem', 'position': 2, 'name': t('nav.gallery'), 'item': base_url ~ '/' ~ lang ~ '/gallery'},
        {'@type': 'ListItem', 'position': 3, 'name': album.name, 'item': canonical_url}
    ]
}|json_encode|raw }}</script>
{% endblock %}

{% block content %}
```

(This depends on Task 7's `album.twig` change, which added the `{% block meta_desc %}` line this step anchors on — do Task 7 first if working out of order.)

- [ ] **Step 3: Manually verify**

Run: `curl -s http://localhost:8080/en/blog/<slug> | grep -A8 'application/ld+json'`
Expected: one `BreadcrumbList` script with 3 `ListItem` entries (Home → Blog → post title).

Run: `curl -s http://localhost:8080/en/gallery/<slug> | grep -A8 'application/ld+json'`
Expected: one `BreadcrumbList` script with 3 `ListItem` entries (Home → Gallery → album name).

- [ ] **Step 4: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests PASS

- [ ] **Step 5: Commit**

```bash
git add templates/public/blog/post.twig templates/public/gallery/album.twig
git commit -m "feat: add BreadcrumbList JSON-LD to blog post and gallery album pages"
```

---

### Task 14: HTTP → HTTPS redirect

**Files:**
- Modify: `www/.htaccess`

**Interfaces:**
- None — this is a standalone Apache rewrite rule, independent of every other task. Can be done at any point, but listed last since it can only be verified against the real WEDOS deployment (HTTPS isn't part of the local `php -S` dev server).

- [ ] **Step 1: Add the redirect rule**

In `www/.htaccess`, replace:

```apache
# Slim — route all non-file, non-directory requests to index.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

with:

```apache
# Force HTTPS
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Slim — route all non-file, non-directory requests to index.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

- [ ] **Step 2: Commit**

```bash
git add www/.htaccess
git commit -m "feat: redirect HTTP to HTTPS"
```

- [ ] **Step 3: Verify after next deploy (not locally — `php -S` has no HTTPS/Apache layer)**

After running `/deploy`: `curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" http://balonkydecor.cz/cs/`
Expected: `301 https://balonkydecor.cz/cs/`

---

## Final Verification

After all 14 tasks are complete:

- [ ] Run: `php vendor/bin/phpunit --testdox` — expect all tests PASS (baseline 37 tests + 20 new: 6 Seo + 8 Sitemap + 2 Gallery + 1 Product + 1 Blog + 2 Page = 57 tests total)
- [ ] Start local server, spot-check `curl http://localhost:8080/sitemap.xml` validates as XML and contains every active product/published post/gallery album across all 5 languages.
- [ ] Spot-check `curl http://localhost:8080/robots.txt` matches the expected block.
- [ ] Deploy (`/deploy`), then verify HTTPS redirect and re-run `/verify`.
- [ ] Submit `https://balonkydecor.cz/sitemap.xml` to Google Search Console (manual, outside this repo's scope per spec's "Out of Scope").
