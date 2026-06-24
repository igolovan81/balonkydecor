# BalonkyDecor — Site Design Spec

**Date:** 2026-06-24  
**Stack:** Pure PHP (Slim 4) + MySQL + Twig + GoPay  
**Hosting:** WEDOS shared hosting

---

## Business Context

E-commerce site for a balloon decoration business selling:
- Helium balloons (various styles and sets)
- Helium tanks (various volumes)
- Event decoration services

**Target audiences:** private customers (birthdays, weddings), event organizers, corporate clients.

---

## Languages

Five languages, all equal first-class citizens:

| Code | Language   |
|------|------------|
| `cs` | Czech (primary) |
| `ru` | Russian |
| `en` | English |
| `uk` | Ukrainian |
| `sk` | Slovak |

Language is determined by the URL prefix (e.g. `/cs/`, `/en/`). The admin panel is Czech-only internally.

---

## Architecture

**Framework:** Slim 4 (routing, middleware, DI container)  
**Templating:** Twig  
**Database:** MySQL via PDO  
**Payment:** GoPay  
**Image processing:** PHP GD library  
**Dependencies managed via Composer**

### Directory Structure

Source code lives **outside the web root** (`www/`) for security. Only `index.php`, `.htaccess`, and static assets are publicly accessible.

```
/ (repo root)
├── composer.json
├── vendor/
├── src/
│   ├── Controllers/
│   ├── Models/
│   ├── Middleware/        # AuthMiddleware, LangMiddleware, CsrfMiddleware
│   ├── Services/          # GoPay, ImageUpload, Mailer, I18n
│   └── routes.php
├── templates/
│   ├── layout/            # base.twig, admin-base.twig
│   ├── public/
│   └── admin/
├── lang/                  # cs.json, ru.json, en.json, uk.json, sk.json
├── session/               # PHP session storage (already exists)
├── tmp/                   # Temp files (already exists)
└── www/                   # Web root — publicly accessible
    ├── index.php           # Front controller
    ├── .htaccess           # Routes all requests to index.php
    └── assets/
        ├── css/
        ├── js/
        └── uploads/        # Product & gallery images (UUID filenames)
```

---

## Database Schema

### Content tables (language-neutral)

```sql
languages        id, code, name, is_active

categories       id, slug, image, sort_order
category_t       id, category_id, lang_code, name, description

products         id, category_id, sku, price, stock_type (unlimited|limited),
                 stock_qty, is_active, sort_order, created_at
product_t        id, product_id, lang_code, name, description, meta_title, meta_desc
product_images   id, product_id, filename, is_primary, sort_order

orders           id, order_number, status (pending|paid|ready|completed|cancelled),
                 customer_name, customer_email, customer_phone,
                 pickup_date, total_amount, gopay_payment_id,
                 notes, created_at
order_items      id, order_id, product_id, quantity, unit_price, product_name_snapshot

gallery_albums   id, slug, cover_image, sort_order, created_at
gallery_album_t  id, album_id, lang_code, name, description
gallery_images   id, album_id, filename, sort_order, created_at

blog_posts       id, slug, author_id, status (draft|published), published_at, created_at
blog_post_t      id, post_id, lang_code, title, body, meta_title, meta_desc

pages            id, slug
page_t           id, page_id, lang_code, title, body, meta_title, meta_desc

users            id, email, password_hash, role (admin|editor), created_at

settings         key, value
```

All translatable text lives in `_t` tables joined by `lang_code`. Adding a 6th language requires only new rows, no schema changes.

---

## Public Site

### Routes

```
/{lang}/                    Home
/{lang}/shop                Catalog (filterable by category)
/{lang}/shop/{slug}         Product detail
/{lang}/services            Event decoration services
/{lang}/gallery             Gallery album list
/{lang}/gallery/{slug}      Album photo grid
/{lang}/blog                Blog post list (paginated)
/{lang}/blog/{slug}         Single blog post
/{lang}/contact             Contact form + phone + map
/{lang}/cart                Cart (session-based)
/{lang}/checkout            Customer details + pickup date
/{lang}/checkout/confirm    Order summary
/{lang}/payment/gopay       Redirect to GoPay
/{lang}/payment/return      GoPay return URL (customer-facing result)
/payment/notify             GoPay IPN webhook — no lang prefix (server-to-server call)
/{lang}/order/{number}      Order status page
```

### Cart & Checkout Flow

1. Customer adds products to cart (PHP session)
2. Fills name, email, phone, preferred pickup date
3. Reviews order summary
4. Redirected to GoPay payment gateway
5. GoPay calls `/payment/notify` (server-side webhook) → order status set to `paid`
6. Customer redirected to `/payment/return` → sees confirmation
7. Confirmation email sent to customer and admin (SMTP via Mailer service)

### Delivery

**Local pickup only.** No shipping calculation at checkout.

### Design Direction

Elegant & clean: white/pastel palette, refined typography, generous whitespace. Targets wedding and corporate clients as well as private customers.

### Language Switching

Header language selector swaps the `/{lang}/` prefix while keeping the user on the same page (e.g. `/cs/shop` ↔ `/en/shop`).

---

## Admin Panel

All admin routes protected by `AuthMiddleware`. Sessions expire after 2 hours of inactivity. Passwords stored as bcrypt hashes.

### Routes

```
/admin                      Dashboard (today's orders, low stock alerts, quick stats)
/admin/login
/admin/products             List, filter by category
/admin/products/new         Create + upload images + all 5 language fields
/admin/products/{id}        Edit
/admin/categories           List + create/edit
/admin/orders               List, filter by status/date
/admin/orders/{id}          Detail, change status, add notes
/admin/gallery              Album list
/admin/gallery/{id}         Image manager (drag & drop upload, reorder, delete)
/admin/blog                 Post list
/admin/blog/new             Editor + all 5 language fields
/admin/blog/{id}            Edit
/admin/pages                Edit static page content per language
/admin/translations         UI string key-value editor per language
/admin/users                Manage admin accounts
/admin/settings             Site name, contact info, GoPay credentials, SMTP config
```

### Image Uploads

- Stored in `www/assets/uploads/` with UUID filenames
- Resized to max 1600px wide (GD)
- Thumbnails generated at 400px

### Auth Notes

- No "forgot password" in v1 — reset via phpMyAdmin
- Role `admin` has full access; role `editor` has access to products, blog, gallery only

---

## Payment — GoPay

- GoPay credentials stored in `settings` table (configurable via admin)
- Payment flow: create GoPay payment → redirect customer → receive IPN webhook → verify via GoPay API → update order status
- `gopay_payment_id` stored on order for reconciliation
- All GoPay logic encapsulated in `src/Services/GoPay.php`

---

## Out of Scope (v1)

- Forgot password / email-based password reset
- Customer accounts / order history login
- Discount codes / coupons
- Multiple delivery options
- Stripe or other payment providers
- Automated stock replenishment notifications
