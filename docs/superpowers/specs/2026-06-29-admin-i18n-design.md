# Admin Internationalisation — Design Spec

**Date:** 2026-06-29  
**Languages:** cs, sk, en, uk, ru (same set as the public site)

---

## Iteration 2 — Dashboard, Users, Blog (2026-06-29)

**Scope:** Extend i18n to Dashboard, Users, and Blog section templates. Flash messages from PHP controllers and JS `confirm()` dialogs are out of scope (matches Settings pattern).

### Infrastructure (already in place from Iteration 1)
- `lang/admin/{cs,en,ru,sk,uk}.json` with `nav.*` + `settings.*` keys
- `AdminBaseController::renderAdmin()` wires up `I18nExtension` from `admin_i18n` request attribute
- `AdminLangController` + `/admin/set-lang` route

### New translation keys

**`dashboard.*` (12 keys)**

| Key | CS | EN |
|-----|----|----|
| `dashboard.title` | Dashboard | Dashboard |
| `dashboard.stats.orders_today` | Objednávky dnes | Orders today |
| `dashboard.stats.orders_pending` | Čekající objednávky | Pending orders |
| `dashboard.stats.orders_total` | Celkem objednávek | Total orders |
| `dashboard.stats.products_active` | Aktivní produkty | Active products |
| `dashboard.recent_orders` | Poslední objednávky | Recent orders |
| `dashboard.col.number` | Číslo | Number |
| `dashboard.col.customer` | Zákazník | Customer |
| `dashboard.col.total` | Celkem | Total |
| `dashboard.col.status` | Status | Status |
| `dashboard.col.created` | Vytvořena | Created |
| `dashboard.no_orders` | Žádné objednávky. | No orders. |

**`users.*` (19 keys)**

| Key | CS | EN |
|-----|----|----|
| `users.title` | Uživatelé | Users |
| `users.add` | + Přidat uživatele | + Add user |
| `users.col.id` | ID | ID |
| `users.col.email` | E-mail | E-mail |
| `users.col.role` | Role | Role |
| `users.col.created` | Vytvořen | Created |
| `users.col.actions` | Akce | Actions |
| `users.new_password` | Nové heslo | New password |
| `users.change_password` | Změnit heslo | Change password |
| `users.delete` | Smazat | Delete |
| `users.confirm_delete` | Smazat uživatele? | Delete user? |
| `users.no_users` | Žádní uživatelé. | No users. |
| `users.form.title_new` | Nový uživatel | New user |
| `users.form.back` | ← Zpět | ← Back |
| `users.form.email` | E-mail | E-mail |
| `users.form.password` | Heslo (min. 8 znaků) | Password (min. 8 characters) |
| `users.form.role` | Role | Role |
| `users.form.create` | Vytvořit | Create |
| `users.form.cancel` | Zrušit | Cancel |

**`blog.*` (24 keys)**

| Key | CS | EN |
|-----|----|----|
| `blog.title` | Blog | Blog |
| `blog.add` | + Nový příspěvek | + New post |
| `blog.col.id` | ID | ID |
| `blog.col.slug` | Slug | Slug |
| `blog.col.status` | Status | Status |
| `blog.col.date` | Datum | Date |
| `blog.col.actions` | Akce | Actions |
| `blog.edit` | Upravit | Edit |
| `blog.delete` | Smazat | Delete |
| `blog.confirm_delete` | Smazat příspěvek? | Delete post? |
| `blog.no_posts` | Žádné příspěvky. | No posts. |
| `blog.form.title_new` | Nový příspěvek | New post |
| `blog.form.title_edit` | Upravit příspěvek | Edit post |
| `blog.form.back` | ← Zpět | ← Back |
| `blog.form.slug` | Slug | Slug |
| `blog.form.status` | Status | Status |
| `blog.form.status_draft` | Koncept (draft) | Draft |
| `blog.form.status_published` | Publikováno | Published |
| `blog.form.published_at` | Datum publikace | Publication date |
| `blog.form.translations` | Překlady | Translations |
| `blog.form.title_label` | Nadpis | Title |
| `blog.form.body_label` | Obsah — HTML povoleno | Content — HTML allowed |
| `blog.form.save` | Uložit | Save |
| `blog.form.cancel` | Zrušit | Cancel |

### Twig templates to update

- `templates/admin/dashboard.twig` — 12 strings
- `templates/admin/users/index.twig` — 10 strings
- `templates/admin/users/form.twig` — 9 strings
- `templates/admin/blog/index.twig` — 10 strings
- `templates/admin/blog/form.twig` — 14 strings

**Dynamic label pattern (blog/form.twig, inside `for lang in langs` loop):**
```twig
{{ t('blog.form.title_label') }} ({{ lang|upper }})
{{ t('blog.form.body_label') }} ({{ lang|upper }})
```

### Key invariant
After this iteration, all 5 lang files must have identical key sets:
`nav.*` (10) + `settings.*` (16) + `dashboard.*` (12) + `users.*` (19) + `blog.*` (24) = **81 keys per file**

---

## Iteration 3 — Products, Categories (2026-06-29)

**Scope:** Extend i18n to Products and Categories section templates. Flash messages, JS confirm() dialogs, and JS runtime strings remain hardcoded Czech (same pattern as Iterations 1–2).

### Infrastructure (already in place)
Same as Iteration 2 — no new files or classes.

### New translation keys

**`products.*` (27 keys)**

| Key | CS | EN |
|-----|----|----|
| `products.title` | Produkty | Products |
| `products.add` | + Přidat produkt | + Add product |
| `products.col.image` | Obrázek | Image |
| `products.col.sku` | SKU | SKU |
| `products.col.category` | Kategorie | Category |
| `products.col.price` | Cena | Price |
| `products.col.active` | Aktivní | Active |
| `products.col.actions` | Akce | Actions |
| `products.edit` | Upravit | Edit |
| `products.delete` | Smazat | Delete |
| `products.confirm_delete` | Smazat produkt? | Delete product? |
| `products.no_products` | Žádné produkty. | No products. |
| `products.form.title_new` | Nový produkt | New product |
| `products.form.title_edit` | Upravit produkt | Edit product |
| `products.form.back` | ← Zpět | ← Back |
| `products.form.sku` | SKU | SKU |
| `products.form.price` | Cena (Kč) | Price (CZK) |
| `products.form.category` | Kategorie | Category |
| `products.form.active` | Aktivní | Active |
| `products.form.add_image` | Přidat obrázek | Add image |
| `products.form.existing_images` | Stávající obrázky | Existing images |
| `products.form.delete_image` | Smazat | Delete |
| `products.form.translations` | Překlady | Translations |
| `products.form.name_label` | Název | Name |
| `products.form.desc_label` | Popis | Description |
| `products.form.save` | Uložit | Save |
| `products.form.cancel` | Zrušit | Cancel |

**`categories.*` (22 keys)**

| Key | CS | EN |
|-----|----|----|
| `categories.title` | Kategorie | Categories |
| `categories.add` | + Přidat kategorii | + Add category |
| `categories.col.id` | ID | ID |
| `categories.col.name` | Název | Name |
| `categories.col.slug` | Slug | Slug |
| `categories.col.order` | Pořadí | Order |
| `categories.col.actions` | Akce | Actions |
| `categories.edit` | Upravit | Edit |
| `categories.delete` | Smazat | Delete |
| `categories.confirm_delete` | Smazat? | Delete? |
| `categories.no_categories` | Žádné kategorie. | No categories. |
| `categories.form.title_new` | Nová kategorie | New category |
| `categories.form.title_edit` | Upravit kategorii | Edit category |
| `categories.form.back` | ← Zpět | ← Back |
| `categories.form.slug` | Slug (URL) | Slug (URL) |
| `categories.form.order` | Pořadí | Order |
| `categories.form.translations` | Překlady | Translations |
| `categories.form.name_label` | Název | Name |
| `categories.form.desc_label` | Popis | Description |
| `categories.form.translate_btn` | Přeložit z češtiny | Translate from Czech |
| `categories.form.save` | Uložit | Save |
| `categories.form.cancel` | Zrušit | Cancel |

### Twig templates to update

- `templates/admin/products/index.twig` — 11 strings
- `templates/admin/products/form.twig` — 15 strings
- `templates/admin/categories/index.twig` — 10 strings
- `templates/admin/categories/form.twig` — 12 strings

**Dynamic label pattern (products/form.twig, inside `for lang in langs` loop):**
```twig
{{ t('products.form.name_label') }} ({{ lang|upper }})
{{ t('products.form.desc_label') }} ({{ lang|upper }})
```

**Dynamic label pattern (categories/form.twig, inside `for lang in langs` loop):**
The form uses a `lang_labels` map already in place — preserve it:
```twig
{{ t('categories.form.name_label') }} ({{ lang_labels[lang] ?? lang|upper }})
{{ t('categories.form.desc_label') }} ({{ lang_labels[lang] ?? lang|upper }})
```

**Auto-translate button (categories/form.twig, line 36):**
The Twig-rendered button label is translated: `{{ t('categories.form.translate_btn') }}`.
The JS runtime strings (loading state, error messages, button reset) remain hardcoded Czech — known minor inconsistency, out of scope per spec.

### Key invariant
After this iteration, all 5 lang files must have identical key sets:
28 (nav+settings) + 55 (dashboard+users+blog) + 49 (products+categories) = **132 keys per file**

---

## Iteration 1 — Navigation and Settings (2026-06-29)

**Scope:** Navigation links and Settings page. Flash messages from controllers are out of scope for this iteration.  

---

## 1. Data Layer

### Migration
`database/migrations/V006__admin_lang.sql`  
Adds `lang VARCHAR(5) NOT NULL DEFAULT 'cs'` to the `users` table.

### Admin lang files
Five new files under `lang/admin/`:
- `cs.json`, `sk.json`, `en.json`, `uk.json`, `ru.json`

Each file contains only the strings needed for the current scope: navigation labels and settings page labels. Keys use a flat namespace (no `admin.` prefix — these files are already admin-scoped). Example keys: `nav.dashboard`, `nav.products`, `settings.title`, `settings.web`, `settings.save`, etc.

### UserModel additions
- `getLang(int $id): string` — reads `lang` column for given user id
- `setLang(int $id, string $lang): void` — updates `lang` column

---

## 2. Middleware & Request Flow

### AdminLangMiddleware
`src/Middleware/AdminLangMiddleware.php`

Runs on all `/admin/*` routes (added to the admin group in `app.php`). Steps:
1. Starts the session if not already started
2. Reads `$_SESSION['admin_lang']` (fallback: `'cs'`)
3. Creates `new I18n($lang, __DIR__ . '/../../lang/admin')` — reuses the existing service unchanged
4. Attaches it to the request as attribute `admin_i18n`

### AdminBaseController patch
`renderAdmin()` reads `$request->getAttribute('admin_i18n')`, registers `I18nExtension` with it (same pattern as `BaseController::render()`), and passes `admin_lang` to every template so the switcher can highlight the active language.

### Login flow patch
`LoginController::login()` (POST) — after successful credential validation and writing `$_SESSION['admin_user']`, additionally calls `UserModel::getLang($userId)` and writes the result to `$_SESSION['admin_lang']`.

---

## 3. Language Switcher & Route

### Route
`GET /admin/set-lang?l={lang}` — registered in `routes.php` inside the admin group (before `/{lang}/*` public routes per the critical routing rule).

### AdminLangController::setLang()
1. Validates `?l=` is one of the 5 supported langs; silently redirects without change on invalid input
2. Calls `UserModel::setLang($_SESSION['admin_user']['id'], $lang)`
3. Writes `$_SESSION['admin_lang'] = $lang`
4. Redirects to `HTTP_REFERER` (fallback: `/admin`)

### Switcher UI
Small row of links in `admin-base.twig` sidebar footer, below the email/role and above the logout link:

```
CZ · SK · EN · UA · RU
```

Active lang gets a CSS `active` class. Each link: `<a href="/admin/set-lang?l=xx">`.

---

## 4. Template Changes

### admin-base.twig
Replace all hardcoded Czech strings with `t()` calls:
- Nav: Dashboard, Produkty, Kategorie, Objednávky, Galerie, Blog, Stránky, Nastavení, Uživatelé, Odhlásit se
- Add lang switcher block in sidebar footer

### admin/settings/index.twig
Replace all Czech labels, section headings, button text, and the GoPay hint paragraph with `t()` calls. Form action and input `name` attributes are unchanged.

---

## Deliverables Summary

| What | Path |
|------|------|
| DB migration | `database/migrations/V006__admin_lang.sql` |
| Admin lang files (×5) | `lang/admin/{cs,sk,en,uk,ru}.json` |
| AdminLangMiddleware | `src/Middleware/AdminLangMiddleware.php` |
| AdminBaseController patch | `src/Controllers/Admin/AdminBaseController.php` |
| AdminLangController | `src/Controllers/Admin/AdminLangController.php` |
| UserModel patch | `src/Models/UserModel.php` |
| LoginController patch | `src/Controllers/Admin/LoginController.php` |
| Route registration | `src/routes.php` |
| Templates | `templates/layout/admin-base.twig`, `templates/admin/settings/index.twig` |
