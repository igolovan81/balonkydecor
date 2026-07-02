# Admin Flash Message & Confirm-Dialog Internationalization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every admin flash message and delete-confirmation dialog respect the admin's language preference instead of always showing Czech.

**Architecture:** `AdminBaseController::flash()`'s signature is unchanged; every call site is changed to pass a translation key instead of literal text, and `templates/layout/admin-base.twig` translates it at render time via the existing `t()` function. The 5 confirm-dialog templates are wired to translation keys that already exist in all 5 language files but were never referenced.

**Tech Stack:** PHP 8 / Slim 4, Twig 3 (`t()` Twig function backed by `App\Services\I18n`), JSON translation files.

## Global Constraints

- `AdminBaseController::flash(string $type, string $message): void` signature is unchanged — only what callers pass as `$message` changes, from literal text to a dot-path translation key.
- Translation happens at render time (`{{ t(flash.message) }}` in `templates/layout/admin-base.twig`), not at flash-set time — this is required because the flash is read on the request *after* the one that set it.
- All 5 `lang/admin/{cs,en,sk,ru,uk}.json` files must stay in lockstep — same key set, same key count, after every task.
- No change to any public-facing (non-admin) template or string.
- No change to the 3 hardcoded-Czech JS runtime strings inside the product/category translate-button handler — that is a separate, previously-approved scope decision, not touched here.
- The 5 `*.confirm_delete` translation keys already exist in all 5 lang files (added in an earlier iteration) — no new translation content needed for those, only wiring them into templates.

---

## File Structure

- `lang/admin/{cs,en,sk,ru,uk}.json` — 25 new `*.flash.*` keys added to each (125 new lines total across the 5 files).
- `templates/layout/admin-base.twig` — one line changed (`{{ flash.message }}` → `{{ t(flash.message) }}`).
- `src/Controllers/Admin/{AdminBaseController,CategoryController,OrderController,BlogController,PageController,ProductController,SettingsController,UserController,GalleryController}.php` — 25 `$this->flash(...)` call sites, each changed to pass a key instead of literal text.
- `templates/admin/{products,categories,gallery,blog,users}/index.twig` — 5 `onsubmit="return confirm('...')"` attributes wired to existing translation keys.

---

## Task 1: Add flash message translation keys to all 5 admin lang files

**Files:**
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/sk.json`, `lang/admin/ru.json`, `lang/admin/uk.json`

**Interfaces:**
- Produces: 25 new translation keys (`common.flash.forbidden`, `categories.flash.created`, `categories.flash.delete_blocked`, `categories.flash.deleted`, `categories.flash.updated`, `orders.flash.status_changed`, `blog.flash.created`, `blog.flash.deleted`, `blog.flash.updated`, `pages.flash.updated`, `products.flash.created`, `products.flash.deleted`, `products.flash.image_deleted`, `products.flash.updated`, `settings.flash.updated`, `users.flash.cannot_delete_self`, `users.flash.created`, `users.flash.deleted`, `users.flash.password_changed`, `users.flash.password_too_short`, `users.flash.validation_required`, `gallery.flash.created`, `gallery.flash.deleted`, `gallery.flash.image_deleted`, `gallery.flash.updated`) in every one of the 5 files, consumed by Task 2.

No automated tests exist for translation JSON content in this repo. Verified via JSON validity check and key-count check (both in this task) plus the full PHPUnit suite (sanity).

All 5 files currently have identical structure (208 lines each, same key order — verified). Insert each block below at the exact anchor point given (same anchor applies to all 5 files, since key order is identical across languages). Insert one language's line per file — do not mix languages into the same file.

- [ ] **Step 1: Insert `common.flash.forbidden`**

This is a brand-new top-level group. Insert one new line, immediately after the line `"categories.title": "Kategorie",` (or that key's translated value in each file) and immediately before the line `"dashboard.col.created": ...`, in each file:

`lang/admin/cs.json`: `"common.flash.forbidden": "Nemáte oprávnění k této akci.",`
`lang/admin/en.json`: `"common.flash.forbidden": "You don't have permission to do this.",`
`lang/admin/sk.json`: `"common.flash.forbidden": "Nemáte oprávnenie na túto akciu.",`
`lang/admin/ru.json`: `"common.flash.forbidden": "У вас нет прав для этого действия.",`
`lang/admin/uk.json`: `"common.flash.forbidden": "У вас немає прав для цієї дії.",`

- [ ] **Step 2: Insert `categories.flash.*` (4 keys)**

Insert immediately after the line `"categories.edit": "Upravit",` (or translated equivalent) and immediately before `"categories.form.back": ...`, in each file. Insert all 4 lines together, in this exact order (already alphabetical):

`lang/admin/cs.json`:
```json
  "categories.flash.created": "Kategorie vytvořena.",
  "categories.flash.delete_blocked": "Kategorii nelze smazat — obsahuje produkty. Nejprve přesuňte nebo smažte produkty.",
  "categories.flash.deleted": "Kategorie smazána.",
  "categories.flash.updated": "Kategorie uložena.",
```

`lang/admin/en.json`:
```json
  "categories.flash.created": "Category created.",
  "categories.flash.delete_blocked": "Category cannot be deleted — it contains products. Move or delete the products first.",
  "categories.flash.deleted": "Category deleted.",
  "categories.flash.updated": "Category saved.",
```

`lang/admin/sk.json`:
```json
  "categories.flash.created": "Kategória vytvorená.",
  "categories.flash.delete_blocked": "Kategóriu nemožno zmazať — obsahuje produkty. Najprv presuňte alebo zmažte produkty.",
  "categories.flash.deleted": "Kategória zmazaná.",
  "categories.flash.updated": "Kategória uložená.",
```

`lang/admin/ru.json`:
```json
  "categories.flash.created": "Категория создана.",
  "categories.flash.delete_blocked": "Категорию нельзя удалить — она содержит товары. Сначала переместите или удалите товары.",
  "categories.flash.deleted": "Категория удалена.",
  "categories.flash.updated": "Категория сохранена.",
```

`lang/admin/uk.json`:
```json
  "categories.flash.created": "Категорію створено.",
  "categories.flash.delete_blocked": "Категорію не можна видалити — вона містить товари. Спочатку перемістіть або видаліть товари.",
  "categories.flash.deleted": "Категорію видалено.",
  "categories.flash.updated": "Категорію збережено.",
```

- [ ] **Step 3: Insert `orders.flash.status_changed`**

Insert immediately after the line `"orders.filter_all": "— všechny —",` (or translated equivalent) and immediately before `"orders.no_orders": ...`, in each file:

`lang/admin/cs.json`: `"orders.flash.status_changed": "Status objednávky změněn.",`
`lang/admin/en.json`: `"orders.flash.status_changed": "Order status changed.",`
`lang/admin/sk.json`: `"orders.flash.status_changed": "Stav objednávky zmenený.",`
`lang/admin/ru.json`: `"orders.flash.status_changed": "Статус заказа изменён.",`
`lang/admin/uk.json`: `"orders.flash.status_changed": "Статус замовлення змінено.",`

- [ ] **Step 4: Insert `blog.flash.*` (3 keys)**

Insert immediately after the line `"blog.edit": "Upravit",` (or translated equivalent) and immediately before `"blog.form.back": ...`, in each file. Insert all 3 lines together, in this exact order:

`lang/admin/cs.json`:
```json
  "blog.flash.created": "Příspěvek vytvořen.",
  "blog.flash.deleted": "Příspěvek smazán.",
  "blog.flash.updated": "Příspěvek uložen.",
```

`lang/admin/en.json`:
```json
  "blog.flash.created": "Post created.",
  "blog.flash.deleted": "Post deleted.",
  "blog.flash.updated": "Post saved.",
```

`lang/admin/sk.json`:
```json
  "blog.flash.created": "Príspevok vytvorený.",
  "blog.flash.deleted": "Príspevok zmazaný.",
  "blog.flash.updated": "Príspevok uložený.",
```

`lang/admin/ru.json`:
```json
  "blog.flash.created": "Запись создана.",
  "blog.flash.deleted": "Запись удалена.",
  "blog.flash.updated": "Запись сохранена.",
```

`lang/admin/uk.json`:
```json
  "blog.flash.created": "Запис створено.",
  "blog.flash.deleted": "Запис видалено.",
  "blog.flash.updated": "Запис збережено.",
```

- [ ] **Step 5: Insert `pages.flash.updated`**

Insert immediately after the line `"pages.edit": "Upravit překlady",` (or translated equivalent) and immediately before `"pages.form.back": ...`, in each file:

`lang/admin/cs.json`: `"pages.flash.updated": "Stránka uložena.",`
`lang/admin/en.json`: `"pages.flash.updated": "Page saved.",`
`lang/admin/sk.json`: `"pages.flash.updated": "Stránka uložená.",`
`lang/admin/ru.json`: `"pages.flash.updated": "Страница сохранена.",`
`lang/admin/uk.json`: `"pages.flash.updated": "Сторінку збережено.",`

- [ ] **Step 6: Insert `products.flash.*` (4 keys)**

Insert immediately after the line `"products.edit": "Upravit",` (or translated equivalent) and immediately before `"products.form.active": ...`, in each file. Insert all 4 lines together, in this exact order:

`lang/admin/cs.json`:
```json
  "products.flash.created": "Produkt vytvořen.",
  "products.flash.deleted": "Produkt smazán.",
  "products.flash.image_deleted": "Obrázek smazán.",
  "products.flash.updated": "Produkt uložen.",
```

`lang/admin/en.json`:
```json
  "products.flash.created": "Product created.",
  "products.flash.deleted": "Product deleted.",
  "products.flash.image_deleted": "Image deleted.",
  "products.flash.updated": "Product saved.",
```

`lang/admin/sk.json`:
```json
  "products.flash.created": "Produkt vytvorený.",
  "products.flash.deleted": "Produkt zmazaný.",
  "products.flash.image_deleted": "Obrázok zmazaný.",
  "products.flash.updated": "Produkt uložený.",
```

`lang/admin/ru.json`:
```json
  "products.flash.created": "Товар создан.",
  "products.flash.deleted": "Товар удалён.",
  "products.flash.image_deleted": "Изображение удалено.",
  "products.flash.updated": "Товар сохранён.",
```

`lang/admin/uk.json`:
```json
  "products.flash.created": "Товар створено.",
  "products.flash.deleted": "Товар видалено.",
  "products.flash.image_deleted": "Зображення видалено.",
  "products.flash.updated": "Товар збережено.",
```

- [ ] **Step 7: Insert `settings.flash.updated`**

Insert immediately after the line `"settings.contact_email": "Kontaktní e-mail",` (or translated equivalent) and immediately before `"settings.gopay": ...`, in each file:

`lang/admin/cs.json`: `"settings.flash.updated": "Nastavení uloženo.",`
`lang/admin/en.json`: `"settings.flash.updated": "Settings saved.",`
`lang/admin/sk.json`: `"settings.flash.updated": "Nastavenia uložené.",`
`lang/admin/ru.json`: `"settings.flash.updated": "Настройки сохранены.",`
`lang/admin/uk.json`: `"settings.flash.updated": "Налаштування збережено.",`

- [ ] **Step 8: Insert `users.flash.*` (6 keys)**

Insert immediately after the line `"users.delete": "Smazat",` (or translated equivalent) and immediately before `"users.form.back": ...`, in each file. Insert all 6 lines together, in this exact order:

`lang/admin/cs.json`:
```json
  "users.flash.cannot_delete_self": "Nemůžete smazat vlastní účet.",
  "users.flash.created": "Uživatel vytvořen.",
  "users.flash.deleted": "Uživatel smazán.",
  "users.flash.password_changed": "Heslo změněno.",
  "users.flash.password_too_short": "Heslo musí mít alespoň 8 znaků.",
  "users.flash.validation_required": "Vyplňte e-mail a heslo (min. 8 znaků).",
```

`lang/admin/en.json`:
```json
  "users.flash.cannot_delete_self": "You cannot delete your own account.",
  "users.flash.created": "User created.",
  "users.flash.deleted": "User deleted.",
  "users.flash.password_changed": "Password changed.",
  "users.flash.password_too_short": "Password must be at least 8 characters.",
  "users.flash.validation_required": "Fill in email and password (min. 8 characters).",
```

`lang/admin/sk.json`:
```json
  "users.flash.cannot_delete_self": "Nemôžete zmazať vlastný účet.",
  "users.flash.created": "Používateľ vytvorený.",
  "users.flash.deleted": "Používateľ zmazaný.",
  "users.flash.password_changed": "Heslo zmenené.",
  "users.flash.password_too_short": "Heslo musí mať aspoň 8 znakov.",
  "users.flash.validation_required": "Vyplňte e-mail a heslo (min. 8 znakov).",
```

`lang/admin/ru.json`:
```json
  "users.flash.cannot_delete_self": "Вы не можете удалить собственную учётную запись.",
  "users.flash.created": "Пользователь создан.",
  "users.flash.deleted": "Пользователь удалён.",
  "users.flash.password_changed": "Пароль изменён.",
  "users.flash.password_too_short": "Пароль должен содержать не менее 8 символов.",
  "users.flash.validation_required": "Заполните e-mail и пароль (мин. 8 символов).",
```

`lang/admin/uk.json`:
```json
  "users.flash.cannot_delete_self": "Ви не можете видалити власний обліковий запис.",
  "users.flash.created": "Користувача створено.",
  "users.flash.deleted": "Користувача видалено.",
  "users.flash.password_changed": "Пароль змінено.",
  "users.flash.password_too_short": "Пароль має містити щонайменше 8 символів.",
  "users.flash.validation_required": "Заповніть e-mail і пароль (мін. 8 символів).",
```

- [ ] **Step 9: Insert `gallery.flash.*` (4 keys)**

Insert immediately after the line `"gallery.edit": "Upravit",` (or translated equivalent) and immediately before `"gallery.form.add_photos": ...`, in each file. Insert all 4 lines together, in this exact order:

`lang/admin/cs.json`:
```json
  "gallery.flash.created": "Album vytvořeno.",
  "gallery.flash.deleted": "Album smazáno.",
  "gallery.flash.image_deleted": "Obrázek smazán.",
  "gallery.flash.updated": "Album uloženo.",
```

`lang/admin/en.json`:
```json
  "gallery.flash.created": "Album created.",
  "gallery.flash.deleted": "Album deleted.",
  "gallery.flash.image_deleted": "Image deleted.",
  "gallery.flash.updated": "Album saved.",
```

`lang/admin/sk.json`:
```json
  "gallery.flash.created": "Album vytvorený.",
  "gallery.flash.deleted": "Album zmazaný.",
  "gallery.flash.image_deleted": "Obrázok zmazaný.",
  "gallery.flash.updated": "Album uložený.",
```

`lang/admin/ru.json`:
```json
  "gallery.flash.created": "Альбом создан.",
  "gallery.flash.deleted": "Альбом удалён.",
  "gallery.flash.image_deleted": "Изображение удалено.",
  "gallery.flash.updated": "Альбом сохранён.",
```

`lang/admin/uk.json`:
```json
  "gallery.flash.created": "Альбом створено.",
  "gallery.flash.deleted": "Альбом видалено.",
  "gallery.flash.image_deleted": "Зображення видалено.",
  "gallery.flash.updated": "Альбом збережено.",
```

- [ ] **Step 10: Verify JSON validity and key counts**

Run:
```bash
for f in lang/admin/*.json; do php -r "json_decode(file_get_contents('$f')) !== null || exit(1); echo count(json_decode(file_get_contents('$f'), true)) . ' $f' . PHP_EOL;"; done
```
Expected: no errors, and all 5 files report the same count — 208 + 25 = 233 keys each.

- [ ] **Step 11: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: PASS (this task touches no PHP).

- [ ] **Step 12: Commit**

```bash
git add lang/admin/cs.json lang/admin/en.json lang/admin/sk.json lang/admin/ru.json lang/admin/uk.json
git commit -m "feat: add flash message translation keys to all admin languages"
```

---

## Task 2: Wire flash messages to use translation keys

**Files:**
- Modify: `templates/layout/admin-base.twig:46`
- Modify: `src/Controllers/Admin/AdminBaseController.php:57`
- Modify: `src/Controllers/Admin/CategoryController.php:39,61,69,73`
- Modify: `src/Controllers/Admin/OrderController.php:44`
- Modify: `src/Controllers/Admin/BlogController.php:42,68,75`
- Modify: `src/Controllers/Admin/PageController.php:39`
- Modify: `src/Controllers/Admin/ProductController.php:52,84,95,109`
- Modify: `src/Controllers/Admin/SettingsController.php:38`
- Modify: `src/Controllers/Admin/UserController.php:31,35,45,49,59,63`
- Modify: `src/Controllers/Admin/GalleryController.php:35,58,69,83`

**Interfaces:**
- Consumes: the 25 translation keys added in Task 1 (exact key names listed in Task 1's Interfaces block).

No automated controller-level tests exist for these methods (confirmed pattern throughout this project). Verified by the full PHPUnit suite (sanity) and Task 4's manual verification.

- [ ] **Step 1: Translate the flash message in the template**

In `templates/layout/admin-base.twig`, replace:
```twig
            <div class="flash-{{ flash.type }}">{{ flash.message }}</div>
```
with:
```twig
            <div class="flash-{{ flash.type }}">{{ t(flash.message) }}</div>
```

- [ ] **Step 2: Update `AdminBaseController::requireRole()`**

In `src/Controllers/Admin/AdminBaseController.php`, replace:
```php
            $this->flash('error', 'Nemáte oprávnění k této akci.');
```
with:
```php
            $this->flash('error', 'common.flash.forbidden');
```

- [ ] **Step 3: Update `CategoryController`**

In `src/Controllers/Admin/CategoryController.php`, replace:
```php
        $this->flash('success', 'Kategorie vytvořena.');
```
with:
```php
        $this->flash('success', 'categories.flash.created');
```

Replace:
```php
        $this->flash('success', 'Kategorie uložena.');
```
with:
```php
        $this->flash('success', 'categories.flash.updated');
```

Replace:
```php
            $this->flash('error', 'Kategorii nelze smazat — obsahuje produkty. Nejprve přesuňte nebo smažte produkty.');
```
with:
```php
            $this->flash('error', 'categories.flash.delete_blocked');
```

Replace:
```php
        $this->flash('success', 'Kategorie smazána.');
```
with:
```php
        $this->flash('success', 'categories.flash.deleted');
```

- [ ] **Step 4: Update `OrderController`**

In `src/Controllers/Admin/OrderController.php`, replace:
```php
            $this->flash('success', 'Status objednávky změněn.');
```
with:
```php
            $this->flash('success', 'orders.flash.status_changed');
```

- [ ] **Step 5: Update `BlogController`**

In `src/Controllers/Admin/BlogController.php`, replace:
```php
        $this->flash('success', 'Příspěvek vytvořen.');
```
with:
```php
        $this->flash('success', 'blog.flash.created');
```

Replace:
```php
        $this->flash('success', 'Příspěvek uložen.');
```
with:
```php
        $this->flash('success', 'blog.flash.updated');
```

Replace:
```php
        $this->flash('success', 'Příspěvek smazán.');
```
with:
```php
        $this->flash('success', 'blog.flash.deleted');
```

- [ ] **Step 6: Update `PageController`**

In `src/Controllers/Admin/PageController.php`, replace:
```php
        $this->flash('success', 'Stránka uložena.');
```
with:
```php
        $this->flash('success', 'pages.flash.updated');
```

- [ ] **Step 7: Update `ProductController`**

In `src/Controllers/Admin/ProductController.php`, replace:
```php
        $this->flash('success', 'Produkt vytvořen.');
```
with:
```php
        $this->flash('success', 'products.flash.created');
```

Replace:
```php
        $this->flash('success', 'Produkt uložen.');
```
with:
```php
        $this->flash('success', 'products.flash.updated');
```

Replace:
```php
        $this->flash('success', 'Obrázek smazán.');
```
with:
```php
        $this->flash('success', 'products.flash.image_deleted');
```

Replace:
```php
        $this->flash('success', 'Produkt smazán.');
```
with:
```php
        $this->flash('success', 'products.flash.deleted');
```

- [ ] **Step 8: Update `SettingsController`**

In `src/Controllers/Admin/SettingsController.php`, replace:
```php
        $this->flash('success', 'Nastavení uloženo.');
```
with:
```php
        $this->flash('success', 'settings.flash.updated');
```

- [ ] **Step 9: Update `UserController`**

In `src/Controllers/Admin/UserController.php`, replace:
```php
            $this->flash('error', 'Vyplňte e-mail a heslo (min. 8 znaků).');
```
with:
```php
            $this->flash('error', 'users.flash.validation_required');
```

Replace:
```php
        $this->flash('success', 'Uživatel vytvořen.');
```
with:
```php
        $this->flash('success', 'users.flash.created');
```

Replace:
```php
            $this->flash('error', 'Heslo musí mít alespoň 8 znaků.');
```
with:
```php
            $this->flash('error', 'users.flash.password_too_short');
```

Replace:
```php
        $this->flash('success', 'Heslo změněno.');
```
with:
```php
        $this->flash('success', 'users.flash.password_changed');
```

Replace:
```php
            $this->flash('error', 'Nemůžete smazat vlastní účet.');
```
with:
```php
            $this->flash('error', 'users.flash.cannot_delete_self');
```

Replace:
```php
        $this->flash('success', 'Uživatel smazán.');
```
with:
```php
        $this->flash('success', 'users.flash.deleted');
```

- [ ] **Step 10: Update `GalleryController`**

In `src/Controllers/Admin/GalleryController.php`, replace:
```php
        $this->flash('success', 'Album vytvořeno.');
```
with:
```php
        $this->flash('success', 'gallery.flash.created');
```

Replace:
```php
        $this->flash('success', 'Album uloženo.');
```
with:
```php
        $this->flash('success', 'gallery.flash.updated');
```

Replace:
```php
        $this->flash('success', 'Obrázek smazán.');
```
with:
```php
        $this->flash('success', 'gallery.flash.image_deleted');
```

Replace:
```php
        $this->flash('success', 'Album smazáno.');
```
with:
```php
        $this->flash('success', 'gallery.flash.deleted');
```

- [ ] **Step 11: Verify no hardcoded flash text remains**

Run:
```bash
grep -rn "\$this->flash(" src/Controllers/Admin/*.php
```
Expected: every line's second argument is a dot-path key matching one of the 25 keys from Task 1 (e.g. `'products.flash.updated'`), not literal Czech text.

- [ ] **Step 12: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: PASS.

- [ ] **Step 13: Commit**

```bash
git add templates/layout/admin-base.twig src/Controllers/Admin/AdminBaseController.php src/Controllers/Admin/CategoryController.php src/Controllers/Admin/OrderController.php src/Controllers/Admin/BlogController.php src/Controllers/Admin/PageController.php src/Controllers/Admin/ProductController.php src/Controllers/Admin/SettingsController.php src/Controllers/Admin/UserController.php src/Controllers/Admin/GalleryController.php
git commit -m "feat: translate admin flash messages via existing i18n system"
```

---

## Task 3: Wire delete-confirmation dialogs to existing translation keys

**Files:**
- Modify: `templates/admin/products/index.twig:35`
- Modify: `templates/admin/categories/index.twig:27`
- Modify: `templates/admin/gallery/index.twig:18`
- Modify: `templates/admin/blog/index.twig:27`
- Modify: `templates/admin/users/index.twig:31`

**Interfaces:**
- Consumes: `products.confirm_delete`, `categories.confirm_delete`, `gallery.confirm_delete`, `blog.confirm_delete`, `users.confirm_delete` — these keys already exist in all 5 `lang/admin/*.json` files (added in an earlier iteration, unrelated to Task 1 of this plan) with correct translations; no new translation content is needed.

None of the 5 translated strings contain an apostrophe or backslash in any of the 5 languages (verified against `lang/admin/*.json` during design) — direct interpolation inside the single-quoted JS string in `onsubmit` is safe.

- [ ] **Step 1: Wire the product delete confirmation**

In `templates/admin/products/index.twig`, replace:
```twig
            <form method="POST" action="/admin/products/{{ p.id }}/delete" style="display:inline" onsubmit="return confirm('Smazat produkt?')">
```
with:
```twig
            <form method="POST" action="/admin/products/{{ p.id }}/delete" style="display:inline" onsubmit="return confirm('{{ t('products.confirm_delete') }}')">
```

- [ ] **Step 2: Wire the category delete confirmation**

In `templates/admin/categories/index.twig`, replace:
```twig
            <form method="POST" action="/admin/categories/{{ cat.id }}/delete" style="display:inline" onsubmit="return confirm('Smazat?')">
```
with:
```twig
            <form method="POST" action="/admin/categories/{{ cat.id }}/delete" style="display:inline" onsubmit="return confirm('{{ t('categories.confirm_delete') }}')">
```

- [ ] **Step 3: Wire the gallery delete confirmation**

In `templates/admin/gallery/index.twig`, replace:
```twig
            <form method="POST" action="/admin/gallery/{{ a.id }}/delete" style="display:inline" onsubmit="return confirm('Smazat album i se všemi fotkami?')">
```
with:
```twig
            <form method="POST" action="/admin/gallery/{{ a.id }}/delete" style="display:inline" onsubmit="return confirm('{{ t('gallery.confirm_delete') }}')">
```

- [ ] **Step 4: Wire the blog delete confirmation**

In `templates/admin/blog/index.twig`, replace:
```twig
            <form method="POST" action="/admin/blog/{{ p.id }}/delete" style="display:inline" onsubmit="return confirm('Smazat příspěvek?')">
```
with:
```twig
            <form method="POST" action="/admin/blog/{{ p.id }}/delete" style="display:inline" onsubmit="return confirm('{{ t('blog.confirm_delete') }}')">
```

- [ ] **Step 5: Wire the user delete confirmation**

In `templates/admin/users/index.twig`, replace:
```twig
            <form method="POST" action="/admin/users/{{ u.id }}/delete" style="display:inline" onsubmit="return confirm('Smazat uživatele?')">
```
with:
```twig
            <form method="POST" action="/admin/users/{{ u.id }}/delete" style="display:inline" onsubmit="return confirm('{{ t('users.confirm_delete') }}')">
```

- [ ] **Step 6: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: PASS (this task touches no PHP).

- [ ] **Step 7: Commit**

```bash
git add templates/admin/products/index.twig templates/admin/categories/index.twig templates/admin/gallery/index.twig templates/admin/blog/index.twig templates/admin/users/index.twig
git commit -m "feat: translate admin delete-confirmation dialogs"
```

---

## Task 4: Manual verification

**Files:** none (verification only, per this project's guidance that UI changes must be checked against a running app before being called done).

- [ ] **Step 1: Start the local stack**

```bash
docker compose up -d
php -S localhost:8080 -t www
```

- [ ] **Step 2: Log in with a non-Czech admin language**

Log in at `http://localhost:8080/admin/login`, then switch the admin language to something other than Czech (e.g. Russian or English) via the language switcher.

- [ ] **Step 3: Verify translated flash messages**

Trigger at least: creating a product (`products.flash.created`), saving a product (`products.flash.updated`), and one validation-error case (e.g. `/admin/users/new` with a short password → `users.flash.password_too_short`). Confirm each flash renders in the selected language, not Czech.

- [ ] **Step 4: Verify translated confirm dialogs**

On `/admin/products`, click Delete on a product and confirm the browser's confirm() popup text is in the selected language, not Czech "Smazat produkt?". Repeat for one other entity (e.g. categories).

- [ ] **Step 5: Verify Czech is unchanged**

Switch the admin language back to Czech. Repeat one flash trigger and one delete-confirm click. Confirm the text is byte-identical to what it was before this plan (since `cs` is the source language for every key, this should be a no-op visually).

- [ ] **Step 6: Verify all 5 languages render (no raw key fallback)**

For each of the remaining languages (Slovak, Ukrainian, and whichever of English/Russian wasn't used in Step 3), trigger at least one flash message and confirm it shows real translated text, not the raw key string (e.g. not literally `products.flash.updated` — `I18n::t()` falls back to echoing the key itself if a translation is missing, so seeing a raw dotted key is the specific failure mode to watch for).

This task has no commit — it's a verification pass over the work committed in Tasks 1-3.

---

## Self-Review Notes

- **Spec coverage:** all 25 flash keys across all 5 languages (Task 1), all 25 controller call sites updated to use them plus the template render change (Task 2), all 5 confirm-dialog templates wired to the already-existing keys (Task 3) — matches the spec's two "Fix" sections exactly. Out-of-scope items from the spec (translate-button JS strings, public-facing templates, `flash()` signature) are correctly untouched by every task.
- **Placeholder scan:** no TBD/TODO; every insertion and every replacement shows the complete exact text for all 5 languages / all 25 call sites — nothing left for the implementer to compose themselves.
- **Type consistency:** `AdminBaseController::flash(string $type, string $message): void` signature referenced identically in Task 2's Interfaces block and every call-site edit — no drift. Every translation key used in Task 2/3's PHP or Twig edits matches a key actually inserted in Task 1 (cross-checked against Task 1's Interfaces key list) or an existing `*.confirm_delete` key (Task 3, verified pre-existing during design, not fabricated).
