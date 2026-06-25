# Admin Panel Internationalization — Design Spec

**Date:** 2026-06-25  
**Status:** Approved

## Overview

Add full i18n support to the BalonkyDecor admin panel. All 18 admin templates are currently hardcoded Czech. This spec covers translating both Twig templates and PHP controller flash messages, with a session-stored language switcher in the sidebar.

**Languages:** cs, en, sk, ru, uk (all 5 supported by the public site)  
**Approach:** Separate admin lang files + `AdminLangMiddleware` (mirrors the public `LangMiddleware` pattern)

---

## 1. Translation Files

**Location:** `lang/admin/cs.json`, `en.json`, `sk.json`, `ru.json`, `uk.json`

All five files must have identical keys. Estimated ~120–140 keys per file.

### Key namespace structure

```
admin.nav.dashboard
admin.nav.products
admin.nav.categories
admin.nav.orders
admin.nav.gallery
admin.nav.blog
admin.nav.pages
admin.nav.settings
admin.nav.users
admin.nav.logout

admin.common.save
admin.common.cancel
admin.common.delete
admin.common.edit
admin.common.new
admin.common.yes
admin.common.no
admin.common.actions
admin.common.back

admin.flash.product_created
admin.flash.product_saved
admin.flash.product_deleted
admin.flash.image_deleted
admin.flash.category_created
admin.flash.category_saved
admin.flash.category_deleted
admin.flash.order_status_changed
admin.flash.gallery_album_created
admin.flash.gallery_album_saved
admin.flash.gallery_album_deleted
admin.flash.gallery_image_deleted
admin.flash.blog_post_created
admin.flash.blog_post_saved
admin.flash.blog_post_deleted
admin.flash.page_saved
admin.flash.settings_saved
admin.flash.user_created
admin.flash.user_password_changed
admin.flash.user_deleted
admin.flash.error_user_fields_required
admin.flash.error_password_too_short
admin.flash.error_cannot_delete_self

admin.dashboard.title
admin.dashboard.orders_today
admin.dashboard.orders_pending
admin.dashboard.orders_total
admin.dashboard.products_active
admin.dashboard.recent_orders
admin.dashboard.no_orders
admin.dashboard.col_number
admin.dashboard.col_customer
admin.dashboard.col_total
admin.dashboard.col_status
admin.dashboard.col_created

admin.products.title
admin.products.new
admin.products.edit
admin.products.col_name
admin.products.col_sku
admin.products.col_price
admin.products.col_category
admin.products.col_active
admin.products.col_actions
admin.products.field_name
admin.products.field_sku
admin.products.field_price
admin.products.field_category
admin.products.field_description
admin.products.field_active
admin.products.field_images

admin.categories.title
admin.categories.new
admin.categories.edit
admin.categories.field_name
admin.categories.field_slug
admin.categories.col_name
admin.categories.col_slug
admin.categories.col_actions

admin.orders.title
admin.orders.detail
admin.orders.col_number
admin.orders.col_customer
admin.orders.col_total
admin.orders.col_status
admin.orders.col_created
admin.orders.col_actions
admin.orders.field_status
admin.orders.update_status
admin.orders.items
admin.orders.col_product
admin.orders.col_qty
admin.orders.col_price
admin.orders.col_subtotal
admin.orders.customer_info
admin.orders.filter_all
admin.orders.filter_pending
admin.orders.filter_paid
admin.orders.filter_cancelled

admin.gallery.title
admin.gallery.new_album
admin.gallery.edit_album
admin.gallery.field_name
admin.gallery.field_slug
admin.gallery.field_description
admin.gallery.upload_images
admin.gallery.col_name
admin.gallery.col_images
admin.gallery.col_actions

admin.blog.title
admin.blog.new_post
admin.blog.edit_post
admin.blog.field_title
admin.blog.field_slug
admin.blog.field_content
admin.blog.field_status
admin.blog.field_published_at
admin.blog.col_title
admin.blog.col_status
admin.blog.col_date
admin.blog.col_actions

admin.pages.title
admin.pages.edit
admin.pages.field_title
admin.pages.field_content
admin.pages.col_slug
admin.pages.col_actions

admin.settings.title
admin.settings.section_site
admin.settings.section_smtp
admin.settings.section_gopay
admin.settings.field_site_name
admin.settings.field_contact_email
admin.settings.field_contact_phone
admin.settings.field_smtp_host
admin.settings.field_smtp_port
admin.settings.field_smtp_user
admin.settings.field_smtp_pass
admin.settings.field_smtp_from
admin.settings.field_gopay_go_id
admin.settings.field_gopay_client_id
admin.settings.field_gopay_client_secret
admin.settings.field_gopay_test_mode

admin.users.title
admin.users.new
admin.users.col_email
admin.users.col_role
admin.users.col_created
admin.users.col_actions
admin.users.field_email
admin.users.field_password
admin.users.field_role
admin.users.change_password
admin.users.field_new_password

admin.auth.login_title
admin.auth.field_email
admin.auth.field_password
admin.auth.submit_login
admin.auth.setup_title
admin.auth.submit_setup
```

Czech values are extracted verbatim from existing hardcoded strings. All other languages are new translations.

---

## 2. AdminLangMiddleware

**File:** `src/Middleware/AdminLangMiddleware.php`

Reads `$_SESSION['admin_lang']` (defaults to `'cs'`, validated against supported list). Creates an `I18n` instance pointing at `lang/admin/`. Attaches `lang` and `i18n` attributes to the PSR-7 request.

```php
class AdminLangMiddleware implements MiddlewareInterface
{
    public function __construct(
        private array  $supported,
        private string $default,
        private string $langDir
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $lang = $_SESSION['admin_lang'] ?? $this->default;
        if (!in_array($lang, $this->supported, true)) $lang = $this->default;

        return $handler->handle(
            $request
                ->withAttribute('lang', $lang)
                ->withAttribute('i18n', new I18n($lang, $this->langDir . '/admin'))
        );
    }
}
```

Applied in `routes.php` on the admin group alongside `AuthMiddleware`:

```php
$app->group('/admin', function(...) { ... })
    ->add(new AuthMiddleware())
    ->add(new AdminLangMiddleware($settings['languages'], 'cs', __DIR__ . '/../lang'));
```

**Note:** Slim applies middleware in reverse order — `AdminLangMiddleware` wraps `AuthMiddleware`, so lang is set before auth checks run.

---

## 3. AdminBaseController Changes

`renderAdmin()` pulls `I18n` from the request attribute, registers `I18nExtension` into the Twig environment (guarded against double-registration), and injects `admin_lang` into every template.

Controllers translate flash messages by calling `$request->getAttribute('i18n')->t('admin.flash.product_created')` instead of hardcoded strings. No constructor or method signature changes in any controller.

The login and setup templates are outside the protected group; `AuthController` performs the same session lang lookup directly before calling `$this->twig->render()`.

---

## 4. Language Switcher

A row of five language buttons added to `templates/layout/admin-base.twig` sidebar, below the logout link. Submits `POST /admin/set-lang` with `lang` and `redirect` fields.

The `set-lang` handler:
- Validates `lang` is in the supported list
- Sets `$_SESSION['admin_lang']`
- Validates `redirect` starts with `/admin` (prevents open redirect)
- Issues 302 to `redirect`

Two CSS classes (`lang-btn`, `lang-btn-active`) added to `www/assets/css/admin.css`.

The handler is a new method on `AuthController` (or a minimal `LangController`) registered as `POST /admin/set-lang` inside the protected group.

---

## 5. Template Migration

All 18 admin templates have hardcoded Czech strings replaced with `{{ t('admin.*') }}` calls. No structural changes.

| Template | Notes |
|---|---|
| `layout/admin-base.twig` | Nav labels, logout, lang switcher |
| `dashboard.twig` | Stat labels, table headings |
| `login.twig`, `setup.twig` | Auth strings; I18n injected by AuthController |
| `products/index.twig`, `products/form.twig` | Product labels |
| `categories/index.twig`, `categories/form.twig` | Category labels |
| `orders/index.twig`, `orders/detail.twig` | Order labels and status filter |
| `gallery/index.twig`, `gallery/form.twig` | Gallery labels |
| `blog/index.twig`, `blog/form.twig` | Blog labels |
| `pages/index.twig`, `pages/form.twig` | Page labels |
| `settings/index.twig` | Settings field labels |
| `users/index.twig`, `users/form.twig` | User labels |

---

## 6. Flash Message Migration

8 admin controllers have hardcoded Czech flash strings (22 strings total). Each is replaced with an `$i18n->t('admin.flash.*')` call where `$i18n = $request->getAttribute('i18n')`.

| Controller | Flash calls |
|---|---|
| `ProductController` | 4 |
| `CategoryController` | 3 |
| `GalleryController` | 4 |
| `BlogController` | 3 |
| `OrderController` | 1 |
| `PageController` | 1 |
| `SettingsController` | 1 |
| `UserController` | 5 (incl. 2 errors) |

---

## 7. Files Changed / Created

| Action | Path |
|---|---|
| Create (×5) | `lang/admin/cs.json`, `en.json`, `sk.json`, `ru.json`, `uk.json` |
| Create | `src/Middleware/AdminLangMiddleware.php` |
| Modify | `src/Controllers/Admin/AdminBaseController.php` |
| Modify | `src/Controllers/Admin/AuthController.php` (set-lang handler + login I18n) |
| Modify | `src/routes.php` (add middleware + set-lang route) |
| Modify | `templates/layout/admin-base.twig` |
| Modify (×18) | All templates under `templates/admin/` |
| Modify (×8) | Admin controllers with flash messages |
| Modify | `www/assets/css/admin.css` |

---

## 8. Out of Scope

- Changes to public-facing lang files (`lang/cs.json` etc.)
- Per-user DB preference (language is per-session only)
- Admin URL lang prefix (no routing changes)
- Tests (no existing admin controller tests)
