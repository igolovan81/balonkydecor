# Nav Dropdowns (Services submenu + Info menu) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restructure the public header nav so Services and a new Info item become hover/tap dropdowns, add a static "Shipping and payment" page, and move Contact under Info.

**Architecture:** Reuse the existing `.nav-item-dropdown` / `.nav-dropdown-menu` CSS pattern already used for Services→gallery in `templates/layout/base.twig`. Add one new static public route/controller/template for `/{lang}/shipping-payment` (no DB, plain `t()` translation keys, following the `services.twig` layout shape but skipping `PageModel`). No JavaScript changes — the existing hamburger-menu JS and mobile CSS already expand `.nav-dropdown-menu` unconditionally on small screens.

**Tech Stack:** Slim 4 routes, Twig 3 templates, plain CSS (no build step), JSON translation files (`lang/*.json`).

## Global Constraints

- All 5 `lang/*.json` files (`cs`, `en`, `ru`, `uk`, `sk`) must keep identical key sets — every new key added to one must be added to all five, in alphabetical position (existing file convention).
- `nav.services` must keep its current value/usage — it's also read by `templates/public/gallery/album.twig` for breadcrumb JSON-LD; do not repurpose it.
- Admin static routes / `/{lang}/*` variable route ordering rule in `src/routes.php` must be preserved: the new `/{lang}/shipping-payment` route goes in the existing public routes block (already after all admin routes).
- No DB/`PageModel` involvement for the new shipping-payment page — static `t()` keys only, per approved design.
- No changes to `www/assets/js/nav.js`.

---

### Task 1: Add new translation keys to all 5 language files

**Files:**
- Modify: `lang/cs.json`
- Modify: `lang/en.json`
- Modify: `lang/sk.json`
- Modify: `lang/ru.json`
- Modify: `lang/uk.json`

**Interfaces:**
- Produces: translation keys `nav.services_our`, `nav.info`, `nav.shipping`, `shipping.title`, `shipping.body` — consumed by Task 2 (shipping page) and Task 3 (nav markup).

- [ ] **Step 1: Add the five new keys to `lang/cs.json`**

Insert `"nav.info": "Info",` and `"nav.shipping": "Doprava a platba",` alphabetically among the `nav.*` keys, `"nav.services_our": "Naše služby",` alphabetically (after `nav.services`), and `"shipping.body"` / `"shipping.title"` alphabetically after `"services.title"` and before `"shop.add_to_cart"`.

Resulting `nav.*` block (lines 44-50 currently) becomes:
```json
  "nav.blog": "Blog",
  "nav.cart": "Košík",
  "nav.contact": "Kontakt",
  "nav.gallery": "Naše realizace",
  "nav.home": "Domů",
  "nav.info": "Info",
  "nav.services": "Služby",
  "nav.services_our": "Naše služby",
  "nav.shipping": "Doprava a platba",
  "nav.shop": "Obchod",
```

And after `"services.title": "Naše služby",` (currently line 64), insert before `"shop.add_to_cart"`:
```json
  "services.title": "Naše služby",
  "shipping.body": "Podrobnosti o dopravě a platbě budou brzy k dispozici.",
  "shipping.title": "Doprava a platba",
  "shop.add_to_cart": "Přidat do košíku",
```

- [ ] **Step 2: Add the five new keys to `lang/en.json`**

```json
  "nav.blog": "Blog",
  "nav.cart": "Cart",
  "nav.contact": "Contact",
  "nav.gallery": "Completed Projects",
  "nav.home": "Home",
  "nav.info": "Info",
  "nav.services": "Services",
  "nav.services_our": "Our Services",
  "nav.shipping": "Shipping and payment",
  "nav.shop": "Shop",
```
```json
  "services.title": "Our Services",
  "shipping.body": "Details about shipping and payment will be available soon.",
  "shipping.title": "Shipping and payment",
  "shop.add_to_cart": "Add to cart",
```

- [ ] **Step 3: Add the five new keys to `lang/sk.json`**

```json
  "nav.blog": "Blog",
  "nav.cart": "Košík",
  "nav.contact": "Kontakt",
  "nav.gallery": "Naše realizácie",
  "nav.home": "Domov",
  "nav.info": "Info",
  "nav.services": "Služby",
  "nav.services_our": "Naše služby",
  "nav.shipping": "Doprava a platba",
  "nav.shop": "Obchod",
```
```json
  "services.title": "Naše služby",
  "shipping.body": "Podrobnosti o doprave a platbe budú čoskoro k dispozícii.",
  "shipping.title": "Doprava a platba",
  "shop.add_to_cart": "Pridať do košíka",
```

- [ ] **Step 4: Add the five new keys to `lang/ru.json`**

```json
  "nav.blog": "Блог",
  "nav.cart": "Корзина",
  "nav.contact": "Контакты",
  "nav.gallery": "Архив оказанных услуг",
  "nav.home": "Главная",
  "nav.info": "Инфо",
  "nav.services": "Услуги",
  "nav.services_our": "Наши услуги",
  "nav.shipping": "Доставка и оплата",
  "nav.shop": "Магазин",
```
```json
  "services.title": "Наши услуги",
  "shipping.body": "Информация о доставке и оплате скоро появится.",
  "shipping.title": "Доставка и оплата",
  "shop.add_to_cart": "В корзину",
```

- [ ] **Step 5: Add the five new keys to `lang/uk.json`**

```json
  "nav.blog": "Блог",
  "nav.cart": "Кошик",
  "nav.contact": "Контакти",
  "nav.gallery": "Архів наданих послуг",
  "nav.home": "Головна",
  "nav.info": "Інфо",
  "nav.services": "Послуги",
  "nav.services_our": "Наші послуги",
  "nav.shipping": "Доставка та оплата",
  "nav.shop": "Магазин",
```
```json
  "services.title": "Наші послуги",
  "shipping.body": "Інформація про доставку та оплату незабаром з'явиться.",
  "shipping.title": "Доставка та оплата",
  "shop.add_to_cart": "До кошика",
```

- [ ] **Step 6: Verify all 5 files stay valid JSON with identical key sets**

Run:
```bash
for f in lang/cs.json lang/en.json lang/sk.json lang/ru.json lang/uk.json; do php -r "json_decode(file_get_contents('$f'), true) !== null || exit(1);" || echo "INVALID: $f"; done
php -r '
$files = ["cs","en","sk","ru","uk"];
$base = array_keys(json_decode(file_get_contents("lang/cs.json"), true));
sort($base);
foreach ($files as $f) {
    $keys = array_keys(json_decode(file_get_contents("lang/$f.json"), true));
    sort($keys);
    if ($keys !== $base) { echo "MISMATCH in $f\n"; }
}
echo "done\n";
'
```
Expected: no `INVALID` or `MISMATCH` lines printed, ends with `done`.

- [ ] **Step 7: Commit**

```bash
git add lang/cs.json lang/en.json lang/sk.json lang/ru.json lang/uk.json
git commit -m "feat: add translation keys for Info/shipping nav items"
```

---

### Task 2: Add the static `/shipping-payment` page (route, controller, template)

**Files:**
- Modify: `src/routes.php:137` (add new route after the `services` route)
- Modify: `src/Controllers/PageController.php` (add `shippingPayment()` method)
- Create: `templates/public/shipping.twig`

**Interfaces:**
- Consumes: `nav.shipping` label is not used here (that's Task 3); this task only consumes `shipping.title` / `shipping.body` from Task 1.
- Produces: route `GET /{lang}/shipping-payment` → `PageController::shippingPayment`, rendering `public/shipping.twig`. Task 4 (sitemap) depends on this URL path existing.

- [ ] **Step 1: Add the route**

In `src/routes.php`, after line 137 (`$app->get('/{lang}/services', ...)`), add:
```php
$app->get('/{lang}/services',                 PageController::class    . ':services');
$app->get('/{lang}/shipping-payment',         PageController::class    . ':shippingPayment');
$app->get('/{lang}/services/archive',         GalleryController::class . ':index');
```
(i.e. insert the new line between the existing `services` and `services/archive` lines.)

- [ ] **Step 2: Add the controller method**

In `src/Controllers/PageController.php`, add a second method (no `PageModel` lookup — static content only):
```php
<?php
namespace App\Controllers;

use App\Models\PageModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

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

    public function shippingPayment(Request $request, Response $response, array $args): Response
    {
        return $this->render($request, $response, 'public/shipping.twig');
    }
}
```

- [ ] **Step 3: Create the template**

Create `templates/public/shipping.twig`:
```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('shipping.title') }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{{ t('shipping.body') }}{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container">
        <h1>{{ t('shipping.title') }}</h1>
    </div>
</section>
<div class="container content-page">
    <p>{{ t('shipping.body') }}</p>
</div>
{% endblock %}
```

- [ ] **Step 4: Manually verify the page renders**

Start the DB and local server:
```bash
docker compose up -d
php -S localhost:8080 -t www &
sleep 1
curl -s http://localhost:8080/cs/shipping-payment | grep -o '<h1>[^<]*</h1>'
curl -s http://localhost:8080/en/shipping-payment | grep -o '<h1>[^<]*</h1>'
kill %1
```
Expected: `<h1>Doprava a platba</h1>` for `/cs/...` and `<h1>Shipping and payment</h1>` for `/en/...`. (If `kill %1` doesn't match the backgrounded server, use `pkill -f "php -S localhost:8080"` instead.)

- [ ] **Step 5: Commit**

```bash
git add src/routes.php src/Controllers/PageController.php templates/public/shipping.twig
git commit -m "feat: add static /shipping-payment page"
```

---

### Task 3: Restructure the nav into Services/Info dropdowns

**Files:**
- Modify: `templates/layout/base.twig:20-30`
- Modify: `www/assets/css/style.css:21-27` (desktop trigger styling)
- Modify: `www/assets/css/style.css:48-51` (mobile trigger styling)

**Interfaces:**
- Consumes: `nav.services_our`, `nav.info`, `nav.shipping` from Task 1; route `/{lang}/shipping-payment` from Task 2.
- Produces: none (leaf UI change).

- [ ] **Step 1: Replace the nav markup**

In `templates/layout/base.twig`, replace lines 20-30:
```twig
            <nav class="main-nav">
                <a href="/{{ lang }}/">{{ t('nav.home') }}</a>
                <a href="/{{ lang }}/shop">{{ t('nav.shop') }}</a>
                <div class="nav-item-dropdown">
                    <a href="/{{ lang }}/services">{{ t('nav.services') }}</a>
                    <div class="nav-dropdown-menu">
                        <a href="/{{ lang }}/services/archive">{{ t('nav.gallery') }}</a>
                    </div>
                </div>
                <a href="/{{ lang }}/blog">{{ t('nav.blog') }}</a>
                <a href="/{{ lang }}/contact">{{ t('nav.contact') }}</a>
            </nav>
```
with:
```twig
            <nav class="main-nav">
                <a href="/{{ lang }}/">{{ t('nav.home') }}</a>
                <a href="/{{ lang }}/shop">{{ t('nav.shop') }}</a>
                <div class="nav-item-dropdown">
                    <span class="nav-dropdown-trigger">{{ t('nav.services') }}</span>
                    <div class="nav-dropdown-menu">
                        <a href="/{{ lang }}/services">{{ t('nav.services_our') }}</a>
                        <a href="/{{ lang }}/services/archive">{{ t('nav.gallery') }}</a>
                    </div>
                </div>
                <a href="/{{ lang }}/blog">{{ t('nav.blog') }}</a>
                <div class="nav-item-dropdown">
                    <span class="nav-dropdown-trigger">{{ t('nav.info') }}</span>
                    <div class="nav-dropdown-menu">
                        <a href="/{{ lang }}/contact">{{ t('nav.contact') }}</a>
                        <a href="/{{ lang }}/shipping-payment">{{ t('nav.shipping') }}</a>
                    </div>
                </div>
            </nav>
```

- [ ] **Step 2: Add desktop trigger styling**

In `www/assets/css/style.css`, the block currently reads (lines 21-27):
```css
.main-nav { display: flex; gap: 1.5rem; flex: 1; order: 2; }
.main-nav a { color: var(--text); text-decoration: none; font-family: var(--ui-font); font-size: .9rem; letter-spacing: .03em; }
.main-nav a:hover { color: var(--accent); }
.nav-item-dropdown { position: relative; }
.nav-dropdown-menu { display: none; position: absolute; top: 100%; left: 0; background: #fff; border: 1px solid var(--border); min-width: 200px; z-index: 10; padding: .5rem 0; }
.nav-dropdown-menu a { display: block; padding: .5rem 1rem; }
.nav-item-dropdown:hover .nav-dropdown-menu { display: block; }
```
Replace with:
```css
.main-nav { display: flex; gap: 1.5rem; flex: 1; order: 2; }
.main-nav a { color: var(--text); text-decoration: none; font-family: var(--ui-font); font-size: .9rem; letter-spacing: .03em; }
.main-nav a:hover { color: var(--accent); }
.nav-item-dropdown { position: relative; }
.nav-dropdown-trigger { display: inline-block; color: var(--text); font-family: var(--ui-font); font-size: .9rem; letter-spacing: .03em; cursor: default; }
.nav-dropdown-trigger::after { content: '▾'; margin-left: .3rem; font-size: .7em; }
.nav-item-dropdown:hover .nav-dropdown-trigger { color: var(--accent); }
.nav-dropdown-menu { display: none; position: absolute; top: 100%; left: 0; background: #fff; border: 1px solid var(--border); min-width: 200px; z-index: 10; padding: .5rem 0; }
.nav-dropdown-menu a { display: block; padding: .5rem 1rem; }
.nav-item-dropdown:hover .nav-dropdown-menu { display: block; }
```

- [ ] **Step 3: Add mobile trigger styling**

In `www/assets/css/style.css`, the mobile block currently reads (lines 48-51):
```css
    .main-nav, .lang-switcher { display: none; flex-basis: 100%; }
    .main-nav { order: 4; flex-direction: column; gap: 0; }
    .main-nav a { padding: .85rem 0; border-top: 1px solid var(--border); font-size: 1rem; }
    .nav-dropdown-menu { position: static; display: block; border: none; padding-left: 1rem; }
```
Replace with:
```css
    .main-nav, .lang-switcher { display: none; flex-basis: 100%; }
    .main-nav { order: 4; flex-direction: column; gap: 0; }
    .main-nav a { padding: .85rem 0; border-top: 1px solid var(--border); font-size: 1rem; }
    .nav-dropdown-trigger { display: block; padding: .85rem 0; border-top: 1px solid var(--border); font-size: 1rem; }
    .nav-dropdown-menu { position: static; display: block; border: none; padding-left: 1rem; }
```

- [ ] **Step 4: Manually verify in a browser**

```bash
docker compose up -d
php -S localhost:8080 -t www &
sleep 1
```
Open `http://localhost:8080/cs/` in a browser:
- Desktop width (>768px): hovering "Služby" shows a dropdown with "Naše služby" (→ `/cs/services`) and "Naše realizace" (→ `/cs/services/archive`); hovering "Info" shows "Kontakt" (→ `/cs/contact`) and "Doprava a platba" (→ `/cs/shipping-payment`). Neither "Služby" nor "Info" navigate when clicked directly.
- Resize below 768px (or use device toolbar) and open the hamburger menu: both dropdowns should already be expanded (no separate tap-to-open needed), with all four submenu links visible and tappable.
- Confirm "Kontakt" no longer appears as its own top-level nav item.

Then stop the server: `pkill -f "php -S localhost:8080"`.

- [ ] **Step 5: Commit**

```bash
git add templates/layout/base.twig www/assets/css/style.css
git commit -m "feat: restructure nav into Services and Info dropdowns"
```

---

### Task 4: Add the new page to the sitemap

**Files:**
- Modify: `src/Services/Sitemap.php:12`
- Modify: `tests/Unit/Services/SitemapTest.php:27`

**Interfaces:**
- Consumes: `/shipping-payment` path convention from Task 2.
- Produces: none.

- [ ] **Step 1: Write the failing test**

In `tests/Unit/Services/SitemapTest.php`, update `test_paths_includes_static_pages` (currently line 24-30):
```php
    public function test_paths_includes_static_pages(): void
    {
        $paths = Sitemap::paths();
        foreach (['/', '/shop', '/services', '/services/archive', '/blog', '/contact', '/shipping-payment'] as $expected) {
            $this->assertContains($expected, $paths);
        }
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose up -d && php vendor/bin/phpunit tests/Unit/Services/SitemapTest.php --testdox`
Expected: `test_paths_includes_static_pages` FAILs (`/shipping-payment` not found in paths).

- [ ] **Step 3: Add the path to the sitemap source**

In `src/Services/Sitemap.php`, line 12 currently reads:
```php
        $paths = ['/', '/shop', '/services', '/services/archive', '/blog', '/contact'];
```
Replace with:
```php
        $paths = ['/', '/shop', '/services', '/services/archive', '/blog', '/contact', '/shipping-payment'];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/Unit/Services/SitemapTest.php --testdox`
Expected: all tests PASS, including `test_entries_produces_one_row_per_path_per_language` (it derives its expected count from `count(Sitemap::paths())`, so it stays correct automatically).

- [ ] **Step 5: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests pass (was 37 tests / 59 assertions before this plan; no test count regression).

- [ ] **Step 6: Commit**

```bash
git add src/Services/Sitemap.php tests/Unit/Services/SitemapTest.php
git commit -m "feat: include /shipping-payment in the sitemap"
```

---

## Final Verification

- [ ] **Step 1: Run the full test suite one more time**

Run: `docker compose up -d && php vendor/bin/phpunit --testdox`
Expected: all tests pass.

- [ ] **Step 2: Smoke-test all 5 languages for the new page**

```bash
php -S localhost:8080 -t www &
sleep 1
for l in cs en sk ru uk; do
  echo "== $l =="
  curl -s "http://localhost:8080/$l/shipping-payment" | grep -o '<h1>[^<]*</h1>'
done
pkill -f "php -S localhost:8080"
```
Expected: each language prints its own translated `<h1>` (no `shipping.title` raw key falling through, no empty output/500 error).
