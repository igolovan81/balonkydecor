# BalonkyDecor

Multilingual e-commerce website for a Czech helium balloon decoration business. Customers can browse products, place orders, and pay online via GoPay. Content is managed through a built-in admin panel.

## Tech Stack

- **PHP 8.1+** — Slim 4 micro-framework, PHP-DI 7
- **Twig 3** — templating (slim/twig-view)
- **MySQL 8** — PDO, no ORM
- **No build step** — plain CSS/JS, no npm/webpack

## Features

**Public site**
- 5 languages: Czech, Russian, English, Ukrainian, Slovak (`/{lang}/` URL prefix)
- Homepage hero image carousel (admin-managed slides)
- Product shop with categories, subtypes/specs, and image galleries
- Shopping cart, wishlist, and product comparison (all session-based)
- Checkout with customer details and pickup date
- Online payment via GoPay (with dev bypass when no credentials configured)
- Gallery, structured Services listing, Contact form

**Admin panel** (`/admin`)
- Session-based authentication with bcrypt passwords
- Language switcher — admin UI available in all 5 languages (preference stored per user)
- Dashboard with order statistics
- Products — CRUD, multilingual name/description/specs, subtypes, image upload (auto-resize), bulk actions, clone/split (split moves one image + its subtypes/specs off into a new product)
- Categories — CRUD with translations
- Hero slides — CRUD for the homepage carousel (image, CTA, translations)
- Services — structured CRUD with translations
- Orders — list with status filter, detail view, status updates
- Gallery — album and photo/video management
- Pages — edit content for Services, Home, Contact in all 5 languages
- Notifications — in-app history of admin actions
- Page view analytics — traffic summary, top pages, device/browser breakdown
- Settings — GoPay credentials, SMTP, site info
- User management

## Local Development

**Requirements:** PHP 8.1+, Composer, Docker (for MySQL)

```bash
# 1. Clone and install dependencies
git clone <repo>
cd balonkydecor
composer install

# 2. Start MySQL
docker compose up -d

# DB: balonkydecor  User: balonky  Pass: balonky  Port: 3306
# Run migrations: GET http://localhost:8080/migrate.php?token=... (see settings.php)

# 3. Serve the app (web root is www/)
php -S localhost:8080 -t www

# 4. Open in browser
open http://localhost:8080/cs/
```

**First run:** Go to `http://localhost:8080/admin/setup` to create the first admin account.

## Admin Panel

| URL | Description |
|-----|-------------|
| `/admin/setup` | Create the first admin user (only works when no users exist) |
| `/admin/login` | Login |
| `/admin` | Dashboard |
| `/admin/products` | Product management |
| `/admin/categories` | Category management |
| `/admin/hero-slides` | Homepage hero carousel slides |
| `/admin/services` | Services listing management |
| `/admin/gallery` | Gallery album and photo/video management |
| `/admin/orders` | Order management |
| `/admin/pages` | Static page content (Services intro, Home, Contact) |
| `/admin/notifications` | Admin action history |
| `/admin/page-views` | Traffic analytics |
| `/admin/users` | User management |
| `/admin/settings` | GoPay + SMTP configuration |

## Configuration

All runtime settings live in the `settings` database table and are editable via `/admin/settings`. The only file-based config is `config/settings.php` (DB credentials, language list).

**GoPay dev bypass:** Leave the GoID field empty in Settings. Orders will be immediately marked as paid without contacting GoPay — useful for local development.

**Email dev bypass:** Leave the SMTP From field empty. Emails are written to `tmp/mail.log` instead of being sent.

## Logging

Application errors/warnings go to `tmp/app-YYYY-MM-DD.log` (one file per day, pruned by the `log_retention` setting) via a small PSR-3 logger, `AppLogger`. Every DB query is also timed transparently — anything slower than 0.5s is logged with a severity (`MINOR`/`MEDIUM`/`MAJOR`/`CRITICAL` for ≥0.5s/≥1s/≥3s/≥6s respectively) via `SlowQueryLogger`/`TimedStatement`. View logs locally with `lnav` (`./scripts/logs.sh`, or the `/logs` Claude command); see `docs/logging.md` for the full format and retention details.

## Testing

**Unit tests** use a real MySQL database (Docker must be running).

```bash
php vendor/bin/phpunit --testdox
```

298 tests covering models, services (cart, wishlist, compare, translator, slow query logging, etc.), I18n, and middleware.

**End-to-end tests** use [Playwright](https://playwright.dev) against a real browser and the PHP built-in server. Requires Docker MySQL running and Node.js installed locally (dev tooling only — not part of the deployed site or the "no build step" public JS).

```bash
npm install               # first time only
npx playwright install chromium   # first time only, downloads the browser
npm run test:e2e          # full suite, local
npm run test:e2e:prod     # @smoke-tagged subset only, against the live site
```

Playwright's `webServer` config starts `php -S localhost:8080 -t www` automatically (or reuses one already running). 18 tests in `tests/e2e/` cover the public golden path (homepage/nav, language switching, 404s, add-to-cart, full checkout via the GoPay dev bypass) plus admin flows (editor-driven order status changes, product clone/split). Specs are driven through page objects (`tests/e2e/pages/`) and shared login/assertion flows (`tests/e2e/workflows/`); throwaway admin/product fixtures are inserted directly into Docker MySQL (`tests/e2e/helpers/`) rather than through the admin UI, to avoid side effects like real translation-API calls. Only `@smoke`-tagged tests (read-only) are safe to run against production — see `.claude/rules/e2e-testing.md`.

## Deployment

No CI/CD. Deploy by copying files to WEDOS shared hosting via FTP/SFTP. The local repository mirrors the hosting account root — `www/` is the Apache web root.

Use the `/deploy` Claude command to deploy, or run `./scripts/deploy.sh` directly. After deploying, use `/verify` to confirm all pages and migrations are healthy.

Before deploying to production:
- Set `'displayErrorDetails' => false` in `config/settings.php`
- Configure GoPay credentials and SMTP via `/admin/settings`
- Ensure `session/` and `tmp/` are writable (outside the web root)

`config/settings.prod.php` lives only on the server (gitignored). It must include a `db_admin` key with the MariaDB admin user credentials so that `migrate.php` can run DDL migrations (ALTER TABLE etc.) that the web user lacks privileges for.

## Project Structure

```
src/               Application code (Slim 4, PSR-4 autoloaded as App\)
  Controllers/     Public page controllers
  Controllers/Admin/  Admin panel controllers
  Middleware/      LangMiddleware, AuthMiddleware, AdminLangMiddleware, PageViewMiddleware
  Models/          Static model classes (PDO)
  Services/        Cart, Wishlist, Compare, GoPay, Mailer, ImageUploader, Notifier, SlowQueryLogger/TimedStatement
  Twig/            I18n Twig extension
templates/         Twig templates
  layout/          base.twig (public), admin-base.twig (admin)
  public/          Public page templates
  admin/           Admin panel templates
lang/              Public translation files: cs.json en.json ru.json uk.json sk.json
  admin/           Admin UI translations (same 5 languages)
www/               Apache web root
  assets/css/      style.css (public), admin.css (admin)
  assets/uploads/  Product and gallery images (created on first upload)
database/
  migrations/      Versioned SQL migrations (V001 schema, V002 demo data, …)
config/
  settings.php     DB credentials, language list, upload settings
```

## Image Uploads

Uploaded images are automatically resized using the GD extension:
- Full size: max 1600px wide, saved as `{uuid}.ext`
- Thumbnail: max 400px wide, saved as `thumb_{uuid}.ext`

Supported formats: JPEG, PNG, WebP, GIF.
