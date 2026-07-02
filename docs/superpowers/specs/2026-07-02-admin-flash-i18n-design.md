# Admin Flash Message & Confirm-Dialog Internationalization Design

**Date:** 2026-07-02
**Scope:** All admin controllers' flash messages + 5 delete-confirmation dialogs — no public-facing changes
**Status:** Approved

---

## Overview

The admin panel's UI labels are already fully translated via the `t()` Twig function backed by `admin_i18n` (an `I18n` instance built from `lang/admin/{lang}.json`, selected per-admin via `AdminLangMiddleware`). Flash messages bypass this entirely: `AdminBaseController::flash(string $type, string $message)` stores the literal message text in `$_SESSION['flash']`, and `templates/layout/admin-base.twig:46` renders it raw (`{{ flash.message }}`). Every one of the 25 `$this->flash(...)` call sites across 9 admin controllers hardcodes Czech text directly, so an admin using any other language (confirmed: user saw "Produkt uložen." while using RU) always sees Czech success/error messages regardless of their preference.

Separately, 5 delete-confirmation dialogs (`onsubmit="return confirm('Smazat produkt?')"` and similar, one each in `products/index.twig`, `categories/index.twig`, `gallery/index.twig`, `blog/index.twig`, `users/index.twig`) have the same problem via a different mechanism (inline JS, not server flash).

---

## Fix 1: Flash messages — translate at render time

**Why render time, not set time:** flash messages are written during a POST handler and read on the *next* request after a redirect (`AdminBaseController::getFlash()` reads and clears `$_SESSION['flash']` inside `renderAdmin()`). Resolving the translated string immediately in the POST handler would use whatever `admin_i18n` context exists at write time, which is a subtly different request than the one that actually renders it. The correct fix is for `flash()` to store a **translation key** instead of literal text, and for the template to translate it when displayed — reusing the exact `admin_i18n`/`t()` setup `renderAdmin()` already establishes per-request. `AdminBaseController::flash()`'s signature is unchanged (`string $type, string $message`) — only what callers pass as `$message` changes, from a literal string to a dot-path key like `products.flash.updated`.

**Template change:** `templates/layout/admin-base.twig:46` changes from `{{ flash.message }}` to `{{ t(flash.message) }}`. `admin_i18n`/`t()` is guaranteed available here — every admin route that can set a flash is inside the `AdminLangMiddleware`-protected route group, so this isn't a new dependency, just reusing what's already wired.

**25 call sites → 25 new translation keys**, grouped under each controller's existing key namespace (`products.*`, `categories.*`, `users.*`, etc.) plus one `common.flash.forbidden` for the shared "no permission" message in `AdminBaseController::requireRole()` (used by `UserController`, not tied to one entity). Full key list (Czech source text as currently hardcoded, English shown as the reference translation — Slovak/Russian/Ukrainian follow the same pattern with natural-language equivalents, full text specified in the implementation plan):

| Key | Czech (current, unchanged) | English |
|---|---|---|
| `common.flash.forbidden` | Nemáte oprávnění k této akci. | You don't have permission to do this. |
| `categories.flash.created` | Kategorie vytvořena. | Category created. |
| `categories.flash.updated` | Kategorie uložena. | Category saved. |
| `categories.flash.delete_blocked` | Kategorii nelze smazat — obsahuje produkty. Nejprve přesuňte nebo smažte produkty. | Category cannot be deleted — it contains products. Move or delete the products first. |
| `categories.flash.deleted` | Kategorie smazána. | Category deleted. |
| `orders.flash.status_changed` | Status objednávky změněn. | Order status changed. |
| `blog.flash.created` | Příspěvek vytvořen. | Post created. |
| `blog.flash.updated` | Příspěvek uložen. | Post saved. |
| `blog.flash.deleted` | Příspěvek smazán. | Post deleted. |
| `pages.flash.updated` | Stránka uložena. | Page saved. |
| `products.flash.created` | Produkt vytvořen. | Product created. |
| `products.flash.updated` | Produkt uložen. | Product saved. |
| `products.flash.image_deleted` | Obrázek smazán. | Image deleted. |
| `products.flash.deleted` | Produkt smazán. | Product deleted. |
| `settings.flash.updated` | Nastavení uloženo. | Settings saved. |
| `users.flash.validation_required` | Vyplňte e-mail a heslo (min. 8 znaků). | Fill in email and password (min. 8 characters). |
| `users.flash.created` | Uživatel vytvořen. | User created. |
| `users.flash.password_too_short` | Heslo musí mít alespoň 8 znaků. | Password must be at least 8 characters. |
| `users.flash.password_changed` | Heslo změněno. | Password changed. |
| `users.flash.cannot_delete_self` | Nemůžete smazat vlastní účet. | You cannot delete your own account. |
| `users.flash.deleted` | Uživatel smazán. | User deleted. |
| `gallery.flash.created` | Album vytvořeno. | Album created. |
| `gallery.flash.updated` | Album uloženo. | Album saved. |
| `gallery.flash.image_deleted` | Obrázek smazán. | Image deleted. |
| `gallery.flash.deleted` | Album smazáno. | Album deleted. |

Each controller's `$this->flash('success'|'error', '<literal text>')` call is replaced with `$this->flash('success'|'error', '<key from the table above>')` — a one-line change per call site, 25 sites across `AdminBaseController.php`, `CategoryController.php`, `OrderController.php`, `BlogController.php`, `PageController.php`, `ProductController.php`, `SettingsController.php`, `UserController.php`, `GalleryController.php`.

---

## Fix 2: Delete-confirmation dialogs — wire up existing (unused) keys

`lang/admin/{cs,en,sk,ru,uk}.json` already contain `blog.confirm_delete`, `categories.confirm_delete`, `gallery.confirm_delete`, `products.confirm_delete`, and `users.confirm_delete` keys, correctly translated in all 5 languages — they were added in an earlier iteration of this project but never actually referenced by any template. No new translation content is needed for this fix.

Each of the 5 `onsubmit="return confirm('<literal Czech text>')"` attributes changes to `onsubmit="return confirm('{{ t('<key>') }}')"`:
- `templates/admin/products/index.twig` → `t('products.confirm_delete')`
- `templates/admin/categories/index.twig` → `t('categories.confirm_delete')`
- `templates/admin/gallery/index.twig` → `t('gallery.confirm_delete')`
- `templates/admin/blog/index.twig` → `t('blog.confirm_delete')`
- `templates/admin/users/index.twig` → `t('users.confirm_delete')`

None of the 5 translated strings contain an apostrophe or backslash in any of the 5 languages (verified against the existing lang files), so direct interpolation inside the single-quoted JS string is safe — no additional escaping filter needed, consistent with how `{{ admin_lang }}` is already interpolated directly into inline `<script>` blocks elsewhere in the admin templates.

---

## Out of Scope

- JS-side hardcoded Czech strings inside the product/category form's translate-button handler ("Nejprve vyplňte texty ve výchozím jazyce.", "Překládám…", "Překlad se nezdařil: …") — this was an explicit, separate, previously-documented scope decision ("JS runtime strings remain hardcoded Czech per spec", noted in a code comment) and is not revisited here.
- Any public-facing (non-admin) template or string.
- Any change to `AdminBaseController::flash()`'s method signature, to `AdminLangMiddleware`, or to how `admin_lang`/`admin_i18n` are determined — this design only adds keys and changes what string is passed as the existing `$message` parameter.

---

## Testing

No automated template/flash-rendering tests exist in this repo (confirmed pattern throughout this project). Verified by:
1. Full PHPUnit suite (sanity — no PHP behavior changes, only string literals passed to an unchanged method signature).
2. Manual verification: trigger each flash-producing action (create/update/delete for at least one entity type, plus one validation-error case) while logged in with a non-Czech `admin_lang`, and confirm the flash message renders translated instead of in Czech. Confirm a delete-confirmation dialog also renders translated. Confirm the Czech (`cs`) admin experience is byte-for-byte unchanged (same text as today, since `cs` is the source language for every key).
