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
  cs.json en.json ru.json uk.json sk.json   # Translation key/value files
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

**Admin URLs:** `/admin/*` — no lang prefix. `AuthMiddleware` protects the group; checks `$_SESSION['admin_user']`. First-time setup at `/admin/setup` (only works when `users` table is empty).

## Controllers

### Public — `BaseController`
`$this->render($request, $response, 'template.twig', $data)` — registers `I18nExtension` if not already present, injects `lang` and `current_path` into every template. The `t('key')` Twig function is available on all public pages.

### Admin — `AdminBaseController`
`$this->renderAdmin($request, $response, 'admin/x.twig', $data)` — no I18n, reads `$_SESSION['flash']` and clears it, passes `flash` to template. `$this->flash('success'|'error', 'message')` sets the next flash. `$this->redirect($response, '/url')` returns a 302 response.

## Models

All static methods. `Database::getConnection()` returns a PDO singleton (`FETCH_ASSOC` mode).

**Translation tables** always use column `lang_code` (not `lang`). ON DUPLICATE KEY UPDATE pattern for upserts.

| Model | Public methods | Admin extras |
|-------|---------------|-------------|
| `ProductModel` | `allActive(lang, ?catId)`, `findBySku(sku, lang)` | `all()`, `findById()`, `create()`, `update()`, `delete()`, `getTranslations()`, `setTranslations()`, `addImage()`, `deleteImage()` |
| `CategoryModel` | `allWithTranslation(lang)` | `all()`, `findById()`, `create()`, `update()`, `delete()`, `getTranslations()`, `setTranslations()` |
| `OrderModel` | `create(customer, cartItems, total)`, `findByNumber()`, `updateStatus()`, `findByGopayId()` | `adminList(page, perPage, status)` |
| `BlogModel` | `published(lang, page, perPage)`, `findBySlug(slug, lang)` | `adminList()`, `findById()`, `create()`, `update()`, `delete()`, `getTranslations()`, `setTranslations()` |
| `GalleryModel` | `albums(lang)`, `album(slug, lang)` | `allAlbums()`, `findAlbumById()`, `createAlbum()`, `updateAlbum()`, `deleteAlbum()`, `getAlbumTranslations()`, `setAlbumTranslations()`, `addImage()`, `deleteImage()` |
| `PageModel` | `find(slug, lang)` | `allSlugs()`, `allTranslations(slug)`, `upsert(slug, lang, title, body)` |
| `AdminUserModel` | — | wraps `users` table: `findByEmail()`, `findById()`, `count()`, `create()`, `all()`, `updatePassword()`, `delete()` |

**`users` table columns:** `id`, `email`, `password_hash`, `role` enum(`admin`,`editor`), `created_at`. No `name` column.

**`blog_posts.status`** is `enum('draft','published')` — not a boolean. Use `status = 'published'` in queries.

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

## Database Schema (17 tables)

`languages`, `categories` + `category_t`, `products` + `product_t` + `product_images`, `orders` + `order_items`, `gallery_albums` + `gallery_album_t` + `gallery_images`, `blog_posts` + `blog_post_t`, `pages` + `page_t`, `users`, `settings`

`settings` table: key/value pairs read by `GoPay::fromSettings()`, `Mailer`, and `SettingsController`. Keys: `site_name`, `contact_email`, `contact_phone`, `smtp_host`, `smtp_port`, `smtp_user`, `smtp_pass`, `smtp_from`, `gopay_go_id`, `gopay_client_id`, `gopay_client_secret`, `gopay_test_mode`.

## Translations

Files: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json` — all must have identical keys (65 keys total). Use `t('key')` in public Twig templates. Key groups: `nav.*`, `site.*`, `home.*`, `cart.*`, `checkout.*`, `order.*`, `order.status.*`, `shop.*`, `services.*`, `gallery.*`, `blog.*`, `contact.*`.

Admin templates are hard-coded Czech — they do not use the `t()` function.

## Testing

PHPUnit 11, tests in `tests/Unit/`. All model tests use a real MySQL DB (Docker). `OrderModelTest` uses `uniqid()` for gopay IDs to avoid collisions across runs. Currently 37 tests, 59 assertions.

## Deployment

No CI/CD. FTP/SFTP files to WEDOS. `www/` is the Apache web root. `session/` and `tmp/` are outside the web root. Before deploying: set `displayErrorDetails => false` in `config/settings.php`.
