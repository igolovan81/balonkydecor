# Admin Internationalisation — Design Spec

**Date:** 2026-06-29  
**Scope:** Navigation links and Settings page. Flash messages from controllers are out of scope for this iteration.  
**Languages:** cs, sk, en, uk, ru (same set as the public site)

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
