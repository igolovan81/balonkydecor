# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

**BalonkyDecor** — a multilingual e-commerce website for a Czech helium balloon decoration business. Built with Slim 4 (PHP micro-framework), Twig 3 templating, PDO/MySQL 8, and no build step. Files deploy directly to WEDOS shared hosting via FTP/SFTP.

## Commands

```bash
# Run all tests
php vendor/bin/phpunit

# Run a single test class
php vendor/bin/phpunit tests/Unit/Models/OrderModelTest.php

# Run tests with output
php vendor/bin/phpunit --testdox

# Start local dev (requires Docker for MySQL)
docker compose up -d          # starts MySQL on 127.0.0.1:3306
# Serve with php -S or point nginx/apache at www/
```

**MySQL dev credentials:** host `127.0.0.1`, db `balonkydecor`, user `balonky`, pass `balonky`.  
Schema lives at `database/schema.sql`.

## Architecture

### Request flow
```
www/index.php → src/app.php → Slim4 routing (src/routes.php)
    → LangMiddleware (extract /{lang}/ prefix, load lang JSON, attach I18n to request)
    → [AuthMiddleware for /admin/* routes]
    → Controller → Model → Twig template
```

### Key directories
```
src/Controllers/        # Public controllers (extend BaseController)
src/Controllers/Admin/  # Admin controllers (extend AdminBaseController)
src/Middleware/         # LangMiddleware, AuthMiddleware
src/Models/             # Static model classes using PDO singleton
src/Services/           # Cart, GoPay, Mailer, ImageUploader
src/Twig/               # I18nExtension (registers t() function)
templates/layout/       # base.twig (public), admin-base.twig (admin)
templates/public/       # Public page templates
templates/admin/        # Admin panel templates
lang/                   # cs.json, en.json, ru.json, uk.json, sk.json
www/assets/             # CSS, JS, uploads/
database/schema.sql     # Full MySQL schema
config/settings.php     # DB creds, language list, upload settings
```

### Multilingual routing
URL prefix `/{lang}/` — supported langs: `cs`, `ru`, `en`, `uk`, `sk`. `LangMiddleware` extracts the lang, loads `lang/{lang}.json`, attaches an `I18n` instance to the request. Admin routes (`/admin/*`) have no lang prefix and default to `cs`.

### Public controllers
Extend `BaseController`. Call `$this->render($request, $response, 'template.twig', $data)` — this registers `I18nExtension` (making `t('key')` available in Twig), injects `lang` and `current_path` into every template.

### Admin controllers
Extend `AdminBaseController` in `src/Controllers/Admin/`. Call `$this->renderAdmin(...)` — bypasses I18n entirely, reads/clears flash messages from `$_SESSION['flash']`. Admin session key: `$_SESSION['admin_user']`.

### Models
All static classes. Database singleton: `Database::getConnection()` returns a PDO connection (FETCH_ASSOC mode). Translation tables use `lang_code` column (not `lang`).

Key models:
- `OrderModel` — `create()`, `findByNumber()`, `updateStatus()`, `findByGopayId()`, `adminList()`
- `ProductModel` — `allActive()`, `findBySku()`, plus admin CRUD + `getTranslations()`/`setTranslations()`
- `CategoryModel`, `GalleryModel`, `BlogModel`, `PageModel` — same pattern
- `AdminUserModel` — wraps `users` table (bcrypt passwords, `role` enum `admin|editor`)

### Cart
`Cart` service backed by `$_SESSION['cart']`. Call `Cart::boot()` before use. `Cart::add($sku, $qty, $name, $price)` accumulates qty if SKU already present.

### GoPay
`GoPay::fromSettings()` reads credentials from the `settings` DB table; returns `null` if `gopay_go_id` is empty → `PaymentController` then marks order paid immediately (dev bypass). Amount sent to GoPay in halíře (× 100).

### Mailer
`Mailer::send()` reads `smtp_from` from settings; if empty → logs to `tmp/mail.log` (dev mode).

### Image uploads
`ImageUploader::upload($file, $dir)` — GD resize to 1600px max width, generates `thumb_` prefixed copy at 400px, UUID filenames. Products: `www/assets/uploads/products/`, Gallery: `www/assets/uploads/gallery/`.

## Translation keys
Keys in `lang/*.json` follow the pattern `section.subsection`. All 5 language files must have identical keys. Important groups: `nav.*`, `site.*`, `cart.*`, `checkout.*`, `order.*`, `shop.*`, `order.status.*`.

## Testing
PHPUnit 11. All model tests hit a real MySQL DB (Docker). Tests in `tests/Unit/`. The `OrderModelTest` uses `uniqid()` for gopay IDs to avoid collisions across runs.

## Deployment
No CI/CD. FTP/SFTP to WEDOS. The local repo mirrors the server's hosting account root. `www/` is the web root. `session/` and `tmp/` are outside the web root.
