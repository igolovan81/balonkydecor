# Auto-generated Category Slug Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Port the recently-shipped product-SKU auto-generation pattern onto category slugs: on the **create** form only, auto-generate the slug from the English category name (locked by default, editable via an unlock button), and guarantee slug uniqueness server-side instead of crashing on a duplicate.

**Architecture:** `CategoryModel` gains `slugify()`/`uniqueSlug()`, mirroring `ProductModel::slugify()`/`::uniqueSku()` exactly. `CategoryController::createSubmit` resolves the final slug the same way `ProductController::createSubmit` resolves SKU. The create form's existing "type-to-override, current-language-tab" JS is removed and replaced with the same readonly + "Edit manually" button pattern used on the product form, sourced from the EN name tab. The edit form and `editSubmit` are untouched.

**Tech Stack:** PHP 8 / Slim 4, PDO/MySQL 8, Twig 3, vanilla JS, PHPUnit 11 against real Docker MySQL.

## Global Constraints

- This behavior applies to the **create** form/flow only — `editSubmit` and the edit form's slug field are never touched by this plan.
- Manually-typed slugs (create form, unlocked) go through `uniqueSlug()` only, never `slugify()`.
- New translation keys go in all 5 `lang/admin/{cs,en,ru,uk,sk}.json` files, kept alphabetically sorted (existing convention).
- Prepared statements with bound parameters only (`.claude/rules/database.md`).
- Run `php vendor/bin/phpunit` (whole suite) before considering any task done; must be fully green.
- Local dev DB (`docker compose up -d`) must be running for model tests.

---

### Task 1: `CategoryModel::slugify()` and `CategoryModel::uniqueSlug()`

**Files:**
- Modify: `src/Models/CategoryModel.php`
- Test: `tests/Unit/Models/CategoryModelTest.php`

**Interfaces:**
- Produces: `CategoryModel::slugify(string $name): string` — lowercases, collapses runs
  of non-`[a-z0-9]` characters to a single `-`, trims leading/trailing `-`; returns
  `"category"` if the result would be empty. `CategoryModel::uniqueSlug(string $candidate): string`
  — returns `$candidate` unchanged if no `categories` row has that `slug`, otherwise
  appends `-2`, `-3`, ... (checking the DB each time) until free. Both consumed by
  Task 2 (`CategoryController::createSubmit`).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Models/CategoryModelTest.php`, right after `test_each_row_has_expected_keys` (before `test_create_records_creator_and_updater`):

```php
    public function test_slugify_converts_name_to_kebab_case(): void
    {
        $this->assertSame('summer-party-decorations', CategoryModel::slugify('Summer party decorations'));
    }

    public function test_slugify_collapses_punctuation_and_symbols(): void
    {
        $this->assertSame('foo-bar', CategoryModel::slugify('  Foo!!  ---  Bar??  '));
    }

    public function test_slugify_falls_back_to_category_when_empty(): void
    {
        $this->assertSame('category', CategoryModel::slugify('   '));
        $this->assertSame('category', CategoryModel::slugify('###'));
    }

    public function test_unique_slug_returns_candidate_when_free(): void
    {
        $candidate = 'free-slug-' . uniqid();
        $this->assertSame($candidate, CategoryModel::uniqueSlug($candidate));
    }

    public function test_unique_slug_appends_suffix_on_single_collision(): void
    {
        $base = 'collide-slug-' . uniqid();
        CategoryModel::create(['slug' => $base, 'sort_order' => 0], self::$userId);
        $this->assertSame($base . '-2', CategoryModel::uniqueSlug($base));
    }

    public function test_unique_slug_appends_incrementing_suffix_on_multiple_collisions(): void
    {
        $base = 'collide-slug-' . uniqid();
        CategoryModel::create(['slug' => $base, 'sort_order' => 0], self::$userId);
        CategoryModel::create(['slug' => $base . '-2', 'sort_order' => 0], self::$userId);
        $this->assertSame($base . '-3', CategoryModel::uniqueSlug($base));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Models/CategoryModelTest.php --testdox`
Expected: FAIL — `Call to undefined method App\Models\CategoryModel::slugify()` (and
`::uniqueSlug()`).

- [ ] **Step 3: Implement the two methods**

In `src/Models/CategoryModel.php`, add these two methods directly above `public static function create(`:

```php
    public static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'category';
    }

    public static function uniqueSlug(string $candidate): string
    {
        $pdo    = Database::getConnection();
        $stmt   = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE slug = ?');
        $slug   = $candidate;
        $suffix = 2;
        $stmt->execute([$slug]);
        while ((int) $stmt->fetchColumn() > 0) {
            $slug = $candidate . '-' . $suffix;
            $suffix++;
            $stmt->execute([$slug]);
        }
        return $slug;
    }

```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Models/CategoryModelTest.php --testdox`
Expected: PASS (all tests, including every pre-existing one in this file).

- [ ] **Step 5: Commit**

```bash
git add src/Models/CategoryModel.php tests/Unit/Models/CategoryModelTest.php
git commit -m "feat: add CategoryModel::slugify() and ::uniqueSlug() helpers"
```

---

### Task 2: Wire slug resolution into `CategoryController::createSubmit`

**Files:**
- Modify: `src/Controllers/Admin/CategoryController.php:28-49`

**Interfaces:**
- Consumes: `CategoryModel::slugify(string $name): string` and
  `CategoryModel::uniqueSlug(string $candidate): string` (Task 1).
- Produces: no new public interface — internal wiring only.

- [ ] **Step 1: Update `createSubmit()`**

Replace the body of `createSubmit` in `src/Controllers/Admin/CategoryController.php`:

```php
    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $slug   = trim($body['slug'] ?? '');
        if ($slug === '') {
            $nameForSlug = trim($body['t']['en']['name'] ?? '');
            if ($nameForSlug === '') {
                foreach (self::LANGS as $lang) {
                    $candidate = trim($body['t'][$lang]['name'] ?? '');
                    if ($candidate !== '') {
                        $nameForSlug = $candidate;
                        break;
                    }
                }
            }
            $slug = CategoryModel::slugify($nameForSlug);
        }
        $slug = CategoryModel::uniqueSlug($slug);
        $id   = CategoryModel::create(
            ['slug' => $slug, 'sort_order' => (int) ($body['sort_order'] ?? 0)],
            $userId
        );
        $translations = \App\Services\Translator::autoFill(
            $body['t'] ?? [],
            $request->getAttribute('admin_lang', 'cs'),
            self::LANGS,
            self::TRANSLATABLE_FIELDS
        );
        CategoryModel::setTranslations($id, $translations);
        \App\Services\Notifier::notify(
            'category', $id, $this->categoryLabel($translations, $body),
            'created', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'categories.flash.created');
        return $this->redirect($response, '/admin/categories');
    }
```

Do not modify `editSubmit()` — it keeps using the raw submitted `slug` value as-is.

- [ ] **Step 2: Syntax-check**

Run: `php -l src/Controllers/Admin/CategoryController.php`
Expected: `No syntax errors detected in src/Controllers/Admin/CategoryController.php`

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/Admin/CategoryController.php
git commit -m "feat: auto-generate and dedupe slug on category create"
```

---

### Task 3: Admin translation keys

**Files:**
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`

**Interfaces:**
- Produces: translation keys `categories.form.slug_edit_manually`,
  `categories.form.slug_hint`. Consumed by Task 4 (`categories/form.twig`).

- [ ] **Step 1: Add both keys to each file, between `"categories.form.slug"` and `"categories.form.title_edit"`**

`lang/admin/cs.json`:
```json
  "categories.form.slug_edit_manually": "Upravit ručně",
  "categories.form.slug_hint": "Automaticky generováno z anglického názvu kategorie. Kliknutím na „Upravit ručně“ jej můžete nastavit sami.",
```

`lang/admin/en.json`:
```json
  "categories.form.slug_edit_manually": "Edit manually",
  "categories.form.slug_hint": "Auto-generated from the English category name. Click \"Edit manually\" to set it yourself.",
```

`lang/admin/ru.json`:
```json
  "categories.form.slug_edit_manually": "Изменить вручную",
  "categories.form.slug_hint": "Автоматически генерируется из английского названия категории. Нажмите «Изменить вручную», чтобы задать его самостоятельно.",
```

`lang/admin/uk.json`:
```json
  "categories.form.slug_edit_manually": "Редагувати вручну",
  "categories.form.slug_hint": "Автоматично генерується з англійської назви категорії. Натисніть «Редагувати вручну», щоб задати його самостійно.",
```

`lang/admin/sk.json`:
```json
  "categories.form.slug_edit_manually": "Upraviť ručne",
  "categories.form.slug_hint": "Automaticky generované z anglického názvu kategórie. Kliknutím na „Upraviť ručne“ ho môžete nastaviť sami.",
```

(Alphabetically: `slug` < `slug_edit_manually` < `slug_hint` < `title_edit`, so both new
keys land together right after the existing `categories.form.slug` line and before
`categories.form.title_edit`.)

- [ ] **Step 2: Verify all 5 files stay valid JSON with identical key sets**

```bash
python3 -c "
import json
files = ['cs','en','ru','uk','sk']
keysets = {}
for l in files:
    d = json.load(open(f'lang/admin/{l}.json'))
    keysets[l] = set(d.keys())
base = keysets['cs']
for l in files:
    assert keysets[l] == base, f'{l} differs: {keysets[l] ^ base}'
print('OK, all files have', len(base), 'identical keys')
"
```
Expected: `OK, all files have 269 identical keys` (267 existing + 2 new).

- [ ] **Step 3: Commit**

```bash
git add lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/uk.json lang/admin/sk.json
git commit -m "feat: add slug auto-generation hint translations"
```

---

### Task 4: Create-form UI — readonly slug field, live preview, unlock button

**Files:**
- Modify: `templates/admin/categories/form.twig`
- Modify: `www/assets/css/admin.css`

**Interfaces:**
- Consumes: `categories.form.slug_edit_manually`, `categories.form.slug_hint` (Task 3).

- [ ] **Step 1: Update the slug field markup**

In `templates/admin/categories/form.twig`, replace:

```twig
    <div class="form-group">
        <label>{{ t('categories.form.slug') }}</label>
        <input type="text" name="slug" value="{{ category.slug ?? '' }}" required>
    </div>
```

with:

```twig
    <div class="form-group">
        <label>{{ t('categories.form.slug') }}</label>
        <input type="text" id="slug-input" class="slug-input" name="slug" value="{{ category.slug ?? '' }}" {% if not category %}readonly{% endif %} required>
        {% if not category %}
        <div style="margin-top:0.35rem;">
            <button type="button" id="slug-edit-btn" class="btn-link" style="font-size:0.85rem">{{ t('categories.form.slug_edit_manually') }}</button>
        </div>
        <p class="audit-meta" style="margin-top:0.35rem;">{{ t('categories.form.slug_hint') }}</p>
        {% endif %}
    </div>
```

- [ ] **Step 2: Replace the slug auto-generation JS**

In the same file's `{% block scripts %}`, remove this entire existing IIFE:

```javascript
// Slug auto-generation from preferred-language name
(function () {
    const slugInput = document.querySelector('input[name="slug"]');
    const prefName  = document.querySelector('input[name="t[' + PREFERRED_LANG + '][name]"]');
    if (!slugInput || !prefName) return;

    function toSlug(s) {
        return s.toLowerCase()
            .replace(/[áä]/g,'a').replace(/č/g,'c').replace(/ď/g,'d')
            .replace(/[éě]/g,'e').replace(/í/g,'i').replace(/ň/g,'n')
            .replace(/[óö]/g,'o').replace(/[řŕ]/g,'r').replace(/š/g,'s')
            .replace(/ť/g,'t').replace(/[úůü]/g,'u').replace(/ý/g,'y')
            .replace(/ž/g,'z')
            .replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
    }

    let locked = slugInput.value !== '';
    slugInput.addEventListener('input', () => { locked = slugInput.value !== ''; });
    prefName.addEventListener('input', () => { if (!locked) slugInput.value = toSlug(prefName.value); });
})();
```

and replace it with:

```javascript
// Slug auto-generation from English name (create form only — readonly + unlock button
// are only rendered when there's no existing category)
(function () {
    const slugInput   = document.getElementById('slug-input');
    const editBtn     = document.getElementById('slug-edit-btn');
    const enNameInput = document.querySelector('input[name="t[en][name]"]');
    if (!slugInput || !editBtn || !enNameInput) return;

    function slugify(s) {
        return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }

    enNameInput.addEventListener('input', () => {
        if (slugInput.readOnly) {
            slugInput.value = slugify(enNameInput.value);
        }
    });

    editBtn.addEventListener('click', () => {
        slugInput.readOnly = false;
        slugInput.focus();
        editBtn.style.display = 'none';
    });
})();
```

- [ ] **Step 3: Extend the readonly styling to the slug field**

In `www/assets/css/admin.css`, change:

```css
.sku-input[readonly] { background:#f5f5f5; color:#666; }
```

to:

```css
.sku-input[readonly], .slug-input[readonly] { background:#f5f5f5; color:#666; }
```

- [ ] **Step 4: Manually verify in the browser**

With the local server running (`docker compose up -d`, `php -S localhost:8080 -t www` if not
already running), log into `/admin/login` and open `http://localhost:8080/admin/categories/new`:
- The slug field should be greyed out (readonly) and empty initially, with the "Edit manually"
  link and hint text visible beneath it.
- Typing into the English (EN) name tab should live-update the slug field with a slugified
  preview (lowercase, hyphenated).
- Clicking "Edit manually" should un-grey the field, let you type freely, and hide the button.
- Submitting the form should create the category with the generated (or manually-edited) slug.
- Open `http://localhost:8080/admin/categories/{id}/edit` for that category (or any existing
  one) and confirm the slug field there is a normal, always-editable input with no hint/button
  — the create-only behavior must not appear on the edit form.

- [ ] **Step 5: Commit**

```bash
git add templates/admin/categories/form.twig www/assets/css/admin.css
git commit -m "feat: auto-generate slug on the category create form"
```

---

### Task 5: Full suite verification

**Files:** none (verification only)

**Interfaces:**
- Consumes: everything from Tasks 1–4.

- [ ] **Step 1: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests pass, zero failures/errors.

- [ ] **Step 2: Smoke check**

```bash
curl -s -o /dev/null -w "CS homepage:    %{http_code}\n" http://localhost:8080/cs/
curl -s -o /dev/null -w "Admin login:    %{http_code}\n" http://localhost:8080/admin/login
curl -s -o /dev/null -w "Categories new: %{http_code}\n" http://localhost:8080/admin/categories/new
```
Expected: `CS homepage` and `Admin login` return `200`; `Categories new` returns `302`
if not authenticated in this shell (redirect to login), or `200` if a session cookie is
present. The authenticated browser check in Task 4 Step 4 already covers the real
behavior.

- [ ] **Step 3: Final commit if any stragglers remain**

```bash
git status
```
Expected: clean working tree (everything already committed task-by-task). If anything
is outstanding, commit it with a clear message.
