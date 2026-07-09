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

## Coding Standards (`.claude/rules/`)

Detailed coding conventions are in `.claude/rules/` — autoloaded by Claude Code when editing matching file paths (see each file's `globs` frontmatter):

- `.claude/rules/backend.md` — Slim 4 route registration order, controller render/flash/redirect patterns, static PDO models, services with dev fallbacks, sessions, config handling
- `.claude/rules/frontend.md` — Twig layout inheritance, `t()` translations across all 5 language files, lang-prefixed links, no-build vanilla JS, accessibility requirements
- `.claude/rules/css-styling.md` — Design tokens in `:root`, 768px/480px breakpoints, flat kebab-case naming with `--modifier` variants, focus/keyboard accessibility, inline SVG assets
- `.claude/rules/unit-testing.md` — TDD, real Docker MySQL instead of mocks, `uniqid()`/`INSERT IGNORE` fixture patterns for the shared dev DB, test naming and assertion style
- `.claude/rules/database.md` — `V0NN__` migration workflow (never edit applied ones), `*_t`/`lang_code` translation tables, idempotent seeds, prepared statements, WEDOS privilege caveats
- `.claude/rules/seo.md` — `title`/`meta_desc` template blocks with DB overrides, canonical/hreflang via `Seo` service, sitemap registration for new routes, 404 rules, JSON-LD escaping

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
    I18n.php                 # Loads lang/{lang}.json, provides t()
    ImageUploader.php        # GD resize (1600px max / 400px thumb), UUID filenames
    VideoUploader.php        # Gallery video upload, UUID filenames
    Mailer.php               # SMTP mailer, dev fallback logs to tmp/mail.log
    Migrator.php             # Applies database/migrations/, tracks schema_migrations
    Seo.php                  # BASE_URL, canonical/hreflang URLs, Organization JSON-LD
    Sitemap.php              # Sitemap paths: static pages + products + gallery albums
    Translator.php           # Admin auto-translate via MyMemory API (POST /admin/translate)
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
  migrate.php                # Token-protected migration runner (used by /deploy)
  assets/css/style.css       # Public CSS
  assets/css/admin.css       # Admin CSS
  assets/js/nav.js           # Mobile nav toggle (vanilla JS, no build step)
  assets/uploads/products/   # Product images (created on first upload)
  assets/uploads/gallery/    # Gallery images & videos (created on first upload)
database/migrations/         # Versioned SQL migrations (V001–V00N)
config/settings.php          # DB creds, language list, upload settings
session/                     # PHP session storage (outside web root)
tmp/                         # Twig cache, mail.log (outside web root)
```

## Routing

**Critical rule:** Admin static routes (`/admin/*`) must be registered **before** any `/{lang}/*` variable routes in `routes.php`. FastRoute throws `BadRouteException` otherwise.

Current order in `routes.php`:
1. `/admin/login`, `/admin/logout`, `/admin/setup` (public auth routes)
2. `$app->group('/admin', ...)` protected admin routes with `AuthMiddleware` (includes `POST /admin/translate` auto-translate endpoint)
3. `$app->get('/', ...)` root redirect
4. Lang-less endpoints: `POST /payment/notify` (GoPay IPN), `/robots.txt`, `/sitemap.xml` (`SeoController`)
5. `/{lang}/*` all public routes

**Multilingual URLs:** `/{lang}/{path}` where lang ∈ `{cs, ru, en, uk, sk}`. `LangMiddleware` runs on every request, extracts lang from the first path segment, loads `lang/{lang}.json`, and attaches `I18n` + `lang` attributes to the PSR-7 request. Unknown/missing segments default to `cs`.

**Admin URLs:** `/admin/*` — no lang prefix. `AuthMiddleware` protects the group; checks `$_SESSION['admin_user']`. First-time setup at `/admin/setup` (only works when `users` table is empty). `AdminLangMiddleware` runs on the protected group and injects `admin_i18n` (an `I18n` instance reading from `lang/admin/`) and `admin_lang` into the request. Language preference is stored in `$_SESSION['admin_lang']` and persisted to `users.lang` via `GET /admin/set-lang?l={lang}`.

## Controllers

### Public — `BaseController`
`$this->render($request, $response, 'template.twig', $data)` — registers `I18nExtension` if not already present, injects `lang`, `current_path`, SEO variables (`canonical_url`, `alternate_urls`, `organization_json_ld`), and `facebook_url`/`instagram_url` settings into every template. The `t('key')` Twig function is available on all public pages. An `asset_v(path)` Twig function (registered in `app.php`, filemtime-based) cache-busts CSS/JS URLs.

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
| `GalleryModel` | `albums(lang)`, `album(slug, lang)` | `allAlbums()`, `findAlbumById()`, `createAlbum()`, `updateAlbum()`, `deleteAlbum()`, `getAlbumTranslations()`, `setAlbumTranslations()`, `addImage(albumId, filename, mediaType)`, `deleteImage()` |
| `PageModel` | `find(slug, lang)` | `allSlugs()`, `allTranslations(slug)`, `upsert(slug, lang, title, body)` |
| `AdminUserModel` | — | wraps `users` table: `findByEmail()`, `findById()`, `count()`, `create()`, `all()`, `updatePassword()`, `delete()`, `setLang(id, lang)` |

**`users` table columns:** `id`, `email`, `password_hash`, `role` enum(`admin`,`editor`), `lang` VARCHAR(5) DEFAULT `cs`, `created_at`. No `name` column.

**`products.category_id`** is NOT NULL — always supply a valid category ID (default to 1 if none selected).

**`gallery_images.media_type`** is ENUM(`image`,`video`). `GalleryModel::albums()` also returns computed `cover_file`/`cover_is_video`: the explicit `cover_image` if set, otherwise the album's first item (photos preferred over videos); templates render video covers as `<video preload="metadata">`.

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
`ImageUploader::upload(['tmp_name' => ..., 'error' => ...], $destDir)` — resizes to 1600px, saves `{uuid}.ext`; also saves `thumb_{uuid}.ext` at 400px. Returns the base filename (without `thumb_` prefix). Directories are created automatically. Gallery videos go through `VideoUploader::upload()` and are stored with `media_type = 'video'`.

### Admin auto-translate
Admin edit forms can call `POST /admin/translate` (route closure in `routes.php`), which uses `Translator::translate()` (MyMemory API) to fill the other languages from the admin's current language.

## Database Schema (15 tables)

`languages`, `categories` + `category_t`, `products` + `product_t` + `product_images`, `orders` + `order_items`, `gallery_albums` + `gallery_album_t` + `gallery_images`, `pages` + `page_t`, `users`, `settings`

`settings` table: key/value pairs read by `GoPay::fromSettings()`, `Mailer`, `BaseController`, and `SettingsController`. Admin-editable keys are whitelisted in `SettingsController::KEYS`: `site_name`, `contact_email`, `contact_phone`, `shipping_address`, `shipping_map_url`, `facebook_url`, `instagram_url`, `smtp_host`, `smtp_port`, `smtp_user`, `smtp_pass`, `smtp_from`, `gopay_go_id`, `gopay_client_id`, `gopay_client_secret`, `gopay_test_mode`. New keys need a seed migration **and** an entry in that whitelist.

## Translations

Files: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json` — all five must have identical key sets. Use `t('key')` in public Twig templates. Key groups: `nav.*`, `site.*`, `home.*`, `cart.*`, `checkout.*`, `order.*`, `order.status.*`, `shop.*`, `services.*`, `gallery.*`, `contact.*`, `shipping.*`, `footer.*`.

Admin templates use `t('key')` via the `admin_i18n` instance injected by `AdminLangMiddleware`. Admin translation files live in `lang/admin/` — all 5 files must have identical keys.

## Testing

PHPUnit 11, tests in `tests/Unit/{Models,Services,Middleware}/`. Model tests (and some service tests) use the real Docker MySQL DB — no mocks; fixtures use `uniqid()` slugs/IDs or `INSERT IGNORE` to survive the shared, persistent dev DB. Full conventions in `.claude/rules/unit-testing.md`. Run the whole suite (`php vendor/bin/phpunit`) before committing.

## Deployment

No CI/CD. FTP/SFTP files to WEDOS. `www/` is the Apache web root. `session/` and `tmp/` are outside the web root. Before deploying: set `displayErrorDetails => false` in `config/settings.php`.

Use the `/deploy` Claude command (auto-runs migrations) and `/verify` to confirm health after deploy.

`config/settings.prod.php` lives only on the server (gitignored). It must include both `db` (web user, limited privileges) and `db_admin` (admin user, DDL privileges) keys. `migrate.php` prefers `db_admin` so that `ALTER TABLE` / `CREATE TABLE` migrations work without manual intervention.
