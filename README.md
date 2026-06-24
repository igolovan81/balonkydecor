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
- Product shop with categories and image galleries
- Shopping cart (session-based)
- Checkout with customer details and pickup date
- Online payment via GoPay (with dev bypass when no credentials configured)
- Gallery, Blog, Services page, Contact form

**Admin panel** (`/admin`)
- Session-based authentication with bcrypt passwords
- Dashboard with order statistics
- Products — CRUD, multilingual name/description, image upload (auto-resize)
- Categories — CRUD with translations
- Orders — list with status filter, detail view, status updates
- Gallery — album and photo management
- Blog — posts with draft/published workflow
- Pages — edit content for Services, Home, Contact in all 5 languages
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

# The schema is loaded automatically from database/schema.sql.
# DB: balonkydecor  User: balonky  Pass: balonky  Port: 3306

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
| `/admin/orders` | Order management |
| `/admin/settings` | GoPay + SMTP configuration |

## Configuration

All runtime settings live in the `settings` database table and are editable via `/admin/settings`. The only file-based config is `config/settings.php` (DB credentials, language list).

**GoPay dev bypass:** Leave the GoID field empty in Settings. Orders will be immediately marked as paid without contacting GoPay — useful for local development.

**Email dev bypass:** Leave the SMTP From field empty. Emails are written to `tmp/mail.log` instead of being sent.

## Testing

Tests use a real MySQL database (Docker must be running).

```bash
php vendor/bin/phpunit --testdox
```

37 tests covering models, cart service, I18n, and middleware.

## Deployment

No CI/CD. Deploy by copying files to WEDOS shared hosting via FTP/SFTP. The local repository mirrors the hosting account root — `www/` is the Apache web root.

Before deploying to production:
- Set `'displayErrorDetails' => false` in `config/settings.php`
- Configure GoPay credentials and SMTP via `/admin/settings`
- Ensure `session/` and `tmp/` are writable (outside the web root)

## Project Structure

```
src/               Application code (Slim 4, PSR-4 autoloaded as App\)
  Controllers/     Public page controllers
  Controllers/Admin/  Admin panel controllers
  Middleware/      LangMiddleware, AuthMiddleware
  Models/          Static model classes (PDO)
  Services/        Cart, GoPay, Mailer, ImageUploader
  Twig/            I18n Twig extension
templates/         Twig templates
  layout/          base.twig (public), admin-base.twig (admin)
  public/          Public page templates
  admin/           Admin panel templates
lang/              Translation files: cs.json en.json ru.json uk.json sk.json
www/               Apache web root
  assets/css/      style.css (public), admin.css (admin)
  assets/uploads/  Product and gallery images (created on first upload)
database/
  schema.sql       Full MySQL schema (17 tables, seed data)
config/
  settings.php     DB credentials, language list, upload settings
```

## Image Uploads

Uploaded images are automatically resized using the GD extension:
- Full size: max 1600px wide, saved as `{uuid}.ext`
- Thumbnail: max 400px wide, saved as `thumb_{uuid}.ext`

Supported formats: JPEG, PNG, WebP, GIF.
