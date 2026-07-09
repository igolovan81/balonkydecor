# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

**BalonkyDecor** — a multilingual e-commerce website for a Czech helium balloon decoration business. Built with Slim 4 (PHP micro-framework), Twig 3 templating, PDO/MySQL 8, and no build step. Files deploy directly to WEDOS shared hosting via FTP/SFTP.

## Commands

```bash
# Run all tests (requires Docker MySQL running)
php vendor/bin/phpunit --testdox

# Run a single test class
php vendor/bin/phpunit tests/Unit/Models/OrderModelTest.php

# Start local MySQL
docker compose up -d          # MySQL on 127.0.0.1:3306, db=balonkydecor, user=balonky, pass=balonky

# Serve locally (point web root at www/)
php -S localhost:8080 -t www
```

Schema: `database/migrations/V001__schema.sql`. Config: `config/settings.php`.

**Area-specific conventions live in `.claude/rules/` — read the relevant one before working in that area:**
`frontend.md` (Twig, translations, JS, a11y) · `backend.md` (routing, controllers, services) · `css-styling.md` (tokens, breakpoints) · `unit-testing.md` (TDD, shared-DB fixtures) · `database.md` (migrations, schema patterns) · `seo.md` (meta blocks, sitemap, hreflang).

## Directory Structure

```
src/
  app.php                    # Bootstrap: DI container, middleware stack, loads routes.php
  routes.php                 # All route definitions (see routing rules below)
  Controllers/               # Public controllers, extend BaseController
    Admin/                   # Admin controllers, extend AdminBaseController
  Middleware/
    LangMiddleware.php        # Extracts /{lang}/ prefix, loads I18n, attaches to request
    AuthMiddleware.php        # Redirects /admin/* to /admin/login unless session active
    AdminLangMiddleware.php   # Reads $_SESSION['admin_lang'], attaches admin_i18n + admin_lang to request
  Models/                    # Static model classes (PDO singleton)
  Services/
    Cart.php                 # Session-backed cart
    GoPay.php                # GoPay REST API client
    Mailer.php               # SMTP mailer, dev fallback logs to tmp/mail.log
    ImageUploader.php        # GD resize (1600px max / 400px thumb), UUID filenames
  Twig/
    I18nExtension.php        # Registers t() function in Twig
templates/
  layout/
    base.twig                # Public layout (nav, lang switcher, flash)
    admin-base.twig          # Admin layout (sidebar, flash messages)
  public/                    # Public page templates
  admin/                     # Admin panel templates
lang/
  cs.json en.json ru.json uk.json sk.json   # Public translation key/value files
  admin/
    cs.json en.json ru.json uk.json sk.json # Admin UI translations (loaded by AdminLangMiddleware)
www/                         # Apache web root
  assets/css/style.css       # Public CSS
  assets/css/admin.css       # Admin CSS
  assets/uploads/products/   # Product images (created on first upload)
  assets/uploads/gallery/    # Gallery images (created on first upload)
database/migrations/         # Versioned SQL migrations (V001–V00N)
config/settings.php          # DB creds, language list, upload settings
session/                     # PHP session storage (outside web root)
tmp/                         # Twig cache, mail.log (outside web root)
```

## Routing

**Critical rule:** Admin static routes (`/admin/*`) must be registered **before** any `/{lang}/*` variable routes in `routes.php`. FastRoute throws `BadRouteException` otherwise.

Current order in `routes.php`:
1. `/admin/login`, `/admin/logout`, `/admin/setup` (public auth routes)
2. `$app->group('/admin', ...)` protected admin routes with `AuthMiddleware`
3. `$app->get('/', ...)` root redirect
4. `/{lang}/*` all public routes

**Multilingual URLs:** `/{lang}/{path}` where lang ∈ `{cs, ru, en, uk, sk}`. `LangMiddleware` runs on every request, extracts lang from the first path segment, loads `lang/{lang}.json`, and attaches `I18n` + `lang` attributes to the PSR-7 request. Unknown/missing segments default to `cs`.

**Admin URLs:** `/admin/*` — no lang prefix. `AuthMiddleware` protects the group; checks `$_SESSION['admin_user']`. First-time setup at `/admin/setup` (only works when `users` table is empty). `AdminLangMiddleware` runs on the protected group and injects `admin_i18n` (an `I18n` instance reading from `lang/admin/`) and `admin_lang` into the request. Language preference is stored in `$_SESSION['admin_lang']` and persisted to `users.lang` via `GET /admin/set-lang?l={lang}`.

## Controllers

### Public — `BaseController`
`$this->render($request, $response, 'template.twig', $data)` — registers `I18nExtension` if not already present, injects `lang` and `current_path` into every template. The `t('key')` Twig function is available on all public pages.

### Admin — `AdminBaseController`
`$this->renderAdmin($request, $response, 'admin/x.twig', $data)` — reads `admin_i18n` and `admin_lang` from the request (set by `AdminLangMiddleware`), reads `$_SESSION['flash']` and clears it, passes `flash`, `admin_lang`, and the `t()` Twig function to every admin template. `$this->flash('success'|'error', 'message')` sets the next flash. `$this->redirect($response, '/url')` returns a 302 response.

## Models

All static methods. `Database::getConnection()` returns a PDO singleton (`FETCH_ASSOC` mode).

**Translation tables** always use column `lang_code` (not `lang`). ON DUPLICATE KEY UPDATE pattern for upserts.

| Model | Public methods | Admin extras |
|-------|---------------|-------------|
| `ProductModel` | `allActive(lang, ?catId)`, `findBySku(sku, lang)` | `all()`, `findById()`, `create()`, `update()`, `delete()`, `getTranslations()`, `setTranslations()`, `addImage()`, `deleteImage()` |
| `CategoryModel` | `allWithTranslation(lang)` | `all()`, `findById()`, `create()`, `update()`, `delete()`, `getTranslations()`, `setTranslations()` |
| `OrderModel` | `create(customer, cartItems, total)`, `findByNumber()`, `updateStatus()`, `findByGopayId()` | `adminList(page, perPage, status)` |
| `GalleryModel` | `albums(lang)`, `album(slug, lang)` | `allAlbums()`, `findAlbumById()`, `createAlbum()`, `updateAlbum()`, `deleteAlbum()`, `getAlbumTranslations()`, `setAlbumTranslations()`, `addImage()`, `deleteImage()` |
| `PageModel` | `find(slug, lang)` | `allSlugs()`, `allTranslations(slug)`, `upsert(slug, lang, title, body)` |
| `AdminUserModel` | — | wraps `users` table: `findByEmail()`, `findById()`, `count()`, `create()`, `all()`, `updatePassword()`, `delete()`, `setLang(id, lang)` |

**`users` table columns:** `id`, `email`, `password_hash`, `role` enum(`admin`,`editor`), `lang` VARCHAR(5) DEFAULT `cs`, `created_at`. No `name` column.

**`products.category_id`** is NOT NULL — always supply a valid category ID (default to 1 if none selected).

## Key Flows

### Order flow
```
POST /{lang}/checkout → validate → OrderModel::create() → $_SESSION['pending_order'] → Cart::clear()
  → redirect /{lang}/checkout/confirm
  → POST /{lang}/payment/gopay → GoPay::fromSettings()
      → null (dev bypass): updateStatus('paid') → redirect /{lang}/order/{number}
      → object: createPayment() → redirect to gw_url
  → GET /{lang}/payment/return?id=X → getStatus() → if PAID: updateStatus('paid')
  → POST /payment/notify (IPN webhook, lang-less)
```

### Cart
`Cart::boot()` starts the session. `Cart::add($sku, $qty, $name, $price)` accumulates qty if SKU already present. `Cart::items()` appends `subtotal` to each row. Backed by `$_SESSION['cart']`.

### GoPay dev bypass
`GoPay::fromSettings()` returns `null` when `gopay_go_id` setting is empty. `PaymentController::initiate()` checks for null and immediately marks the order `paid`, redirecting to the order status page. No GoPay credentials needed for local dev.

### Image uploads
`ImageUploader::upload(['tmp_name' => ..., 'error' => ...], $destDir)` — resizes to 1600px, saves `{uuid}.ext`; also saves `thumb_{uuid}.ext` at 400px. Returns the base filename (without `thumb_` prefix). Directories are created automatically.

## Database Schema (15 tables)

`languages`, `categories` + `category_t`, `products` + `product_t` + `product_images`, `orders` + `order_items`, `gallery_albums` + `gallery_album_t` + `gallery_images`, `pages` + `page_t`, `users`, `settings`

`settings` table: key/value pairs read by `GoPay::fromSettings()`, `Mailer`, and `SettingsController`. Keys: `site_name`, `contact_email`, `contact_phone`, `smtp_host`, `smtp_port`, `smtp_user`, `smtp_pass`, `smtp_from`, `gopay_go_id`, `gopay_client_id`, `gopay_client_secret`, `gopay_test_mode`.

## Translations

Files: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json` — all must have identical keys (68 keys total). Use `t('key')` in public Twig templates. Key groups: `nav.*`, `site.*`, `home.*`, `cart.*`, `checkout.*`, `order.*`, `order.status.*`, `shop.*`, `services.*`, `gallery.*`, `contact.*`.

Admin templates use `t('key')` via the `admin_i18n` instance injected by `AdminLangMiddleware`. Admin translation files live in `lang/admin/` — all 5 files must have identical keys.

## Testing

PHPUnit 11, tests in `tests/Unit/`. All model tests use a real MySQL DB (Docker). `OrderModelTest` uses `uniqid()` for gopay IDs to avoid collisions across runs. Currently 37 tests, 59 assertions.

## Deployment

No CI/CD. FTP/SFTP files to WEDOS. `www/` is the Apache web root. `session/` and `tmp/` are outside the web root. Before deploying: set `displayErrorDetails => false` in `config/settings.php`.

Use the `/deploy` Claude command (auto-runs migrations) and `/verify` to confirm health after deploy.

`config/settings.prod.php` lives only on the server (gitignored). It must include both `db` (web user, limited privileges) and `db_admin` (admin user, DDL privileges) keys. `migrate.php` prefers `db_admin` so that `ALTER TABLE` / `CREATE TABLE` migrations work without manual intervention.
