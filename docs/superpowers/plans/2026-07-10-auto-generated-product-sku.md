# Auto-generated Product SKU Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On the product **create** form only, auto-generate the SKU from the English product name (locked by default, editable on demand), and guarantee SKU uniqueness server-side instead of crashing on a duplicate.

**Architecture:** `ProductModel` gains two pure/DB-simple static methods (`slugify()`, `uniqueSku()`). `ProductController::createSubmit` uses them to resolve the final SKU before insert. The create form renders the SKU input `readonly` with a live client-side slug preview (vanilla JS, mirroring the existing category-slug-generator pattern) and an "Edit manually" unlock button. The edit form and `editSubmit` are untouched.

**Tech Stack:** PHP 8 / Slim 4, PDO/MySQL 8, Twig 3, vanilla JS, PHPUnit 11 against real Docker MySQL.

## Global Constraints

- This behavior applies to the **create** form/flow only — `editSubmit` and the edit form's SKU field are never touched by this plan.
- Manually-typed SKUs (create form, unlocked) go through `uniqueSku()` only, never `slugify()` — an admin's intentional custom value keeps its exact casing/format.
- New translation keys go in all 5 `lang/admin/{cs,en,ru,uk,sk}.json` files, kept alphabetically sorted (existing convention).
- Prepared statements with bound parameters only (`.claude/rules/database.md`).
- Run `php vendor/bin/phpunit` (whole suite) before considering any task done; must be fully green.
- Local dev DB (`docker compose up -d`) must be running for model tests.

---

### Task 1: `ProductModel::slugify()` and `ProductModel::uniqueSku()`

**Files:**
- Modify: `src/Models/ProductModel.php`
- Test: `tests/Unit/Models/ProductModelTest.php`

**Interfaces:**
- Produces: `ProductModel::slugify(string $name): string` — lowercases, collapses runs
  of non-`[a-z0-9]` characters to a single `-`, trims leading/trailing `-`; returns
  `"product"` if the result would be empty. `ProductModel::uniqueSku(string $candidate): string`
  — returns `$candidate` unchanged if no `products` row has that `sku`, otherwise
  appends `-2`, `-3`, ... (checking the DB each time) until free. Both consumed by
  Task 2 (`ProductController::createSubmit`).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Models/ProductModelTest.php`, right after `test_set_translations_stores_meta_fields` (before `test_create_persists_limited_stock`):

```php
    public function test_slugify_converts_name_to_kebab_case(): void
    {
        $this->assertSame('latex-balloons-kitten-50-pcs', ProductModel::slugify('Latex balloons kitten 50 pcs'));
    }

    public function test_slugify_collapses_punctuation_and_symbols(): void
    {
        $this->assertSame('foo-bar', ProductModel::slugify('  Foo!!  ---  Bar??  '));
    }

    public function test_slugify_falls_back_to_product_when_empty(): void
    {
        $this->assertSame('product', ProductModel::slugify('   '));
        $this->assertSame('product', ProductModel::slugify('###'));
    }

    public function test_unique_sku_returns_candidate_when_free(): void
    {
        $candidate = 'free-sku-' . uniqid();
        $this->assertSame($candidate, ProductModel::uniqueSku($candidate));
    }

    public function test_unique_sku_appends_suffix_on_single_collision(): void
    {
        $base = 'collide-sku-' . uniqid();
        ProductModel::create(['sku' => $base, 'price' => 9.99, 'category_id' => self::$categoryId], self::$userId);
        $this->assertSame($base . '-2', ProductModel::uniqueSku($base));
    }

    public function test_unique_sku_appends_incrementing_suffix_on_multiple_collisions(): void
    {
        $base = 'collide-sku-' . uniqid();
        ProductModel::create(['sku' => $base, 'price' => 9.99, 'category_id' => self::$categoryId], self::$userId);
        ProductModel::create(['sku' => $base . '-2', 'price' => 9.99, 'category_id' => self::$categoryId], self::$userId);
        $this->assertSame($base . '-3', ProductModel::uniqueSku($base));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php --testdox`
Expected: FAIL — `Call to undefined method App\Models\ProductModel::slugify()` (and
`::uniqueSku()`).

- [ ] **Step 3: Implement the two methods**

In `src/Models/ProductModel.php`, add these two methods directly above `public static function create(`:

```php
    public static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'product';
    }

    public static function uniqueSku(string $candidate): string
    {
        $pdo    = Database::getConnection();
        $stmt   = $pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ?');
        $sku    = $candidate;
        $suffix = 2;
        $stmt->execute([$sku]);
        while ((int) $stmt->fetchColumn() > 0) {
            $sku = $candidate . '-' . $suffix;
            $suffix++;
            $stmt->execute([$sku]);
        }
        return $sku;
    }

```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php --testdox`
Expected: PASS (all tests, including every pre-existing one in this file).

- [ ] **Step 5: Commit**

```bash
git add src/Models/ProductModel.php tests/Unit/Models/ProductModelTest.php
git commit -m "feat: add ProductModel::slugify() and ::uniqueSku() helpers"
```

---

### Task 2: Wire SKU resolution into `ProductController::createSubmit`

**Files:**
- Modify: `src/Controllers/Admin/ProductController.php:33-59`

**Interfaces:**
- Consumes: `ProductModel::slugify(string $name): string` and
  `ProductModel::uniqueSku(string $candidate): string` (Task 1).
- Produces: no new public interface — the `$sku` local variable used for both
  `ProductModel::create()` and the existing `Notifier::notify()` call now holds the
  final resolved SKU instead of the raw (possibly empty) POST value.

- [ ] **Step 1: Update `createSubmit()`**

Replace the SKU-resolution portion of `createSubmit` in `src/Controllers/Admin/ProductController.php`:

```php
    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $sku    = trim($body['sku'] ?? '');
        if ($sku === '') {
            $nameForSku = trim($body['t']['en']['name'] ?? '');
            if ($nameForSku === '') {
                foreach (self::LANGS as $lang) {
                    $candidate = trim($body['t'][$lang]['name'] ?? '');
                    if ($candidate !== '') {
                        $nameForSku = $candidate;
                        break;
                    }
                }
            }
            $sku = ProductModel::slugify($nameForSku);
        }
        $sku = ProductModel::uniqueSku($sku);
        $id  = ProductModel::create([
            'sku'         => $sku,
            'price'       => $body['price'] ?? '0.00',
            'category_id' => $body['category_id'] ?? 1,
            'is_active'   => isset($body['is_active']) ? 1 : 0,
            'stock_type'  => $body['stock_type'] ?? 'unlimited',
            'stock_qty'   => $body['stock_qty'] ?? 0,
        ], $userId);
        $translations = \App\Services\Translator::autoFill(
            $body['t'] ?? [],
            $request->getAttribute('admin_lang', 'cs'),
            self::LANGS,
            self::TRANSLATABLE_FIELDS
        );
        ProductModel::setTranslations($id, $translations);
        $this->handleImageUpload($request, $id, true);
        \App\Services\Notifier::notify(
            'product', $id, $sku, 'created', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'products.flash.created');
        return $this->redirect($response, '/admin/products');
    }
```

Do not modify `editSubmit()` — it keeps using the raw submitted `sku` value as-is.

- [ ] **Step 2: Syntax-check**

Run: `php -l src/Controllers/Admin/ProductController.php`
Expected: `No syntax errors detected in src/Controllers/Admin/ProductController.php`

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/Admin/ProductController.php
git commit -m "feat: auto-generate and dedupe SKU on product create"
```

---

### Task 3: Admin translation keys

**Files:**
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`

**Interfaces:**
- Produces: translation keys `products.form.sku_edit_manually`,
  `products.form.sku_hint`. Consumed by Task 4 (`products/form.twig`).

- [ ] **Step 1: Add both keys to each file, between `"products.form.sku"` and `"products.form.stock_label"`**

`lang/admin/cs.json`:
```json
  "products.form.sku_edit_manually": "Upravit ručně",
  "products.form.sku_hint": "Automaticky generováno z anglického názvu produktu. Kliknutím na „Upravit ručně“ jej můžete nastavit sami.",
```

`lang/admin/en.json`:
```json
  "products.form.sku_edit_manually": "Edit manually",
  "products.form.sku_hint": "Auto-generated from the English product name. Click \"Edit manually\" to set it yourself.",
```

`lang/admin/ru.json`:
```json
  "products.form.sku_edit_manually": "Изменить вручную",
  "products.form.sku_hint": "Автоматически генерируется из английского названия товара. Нажмите «Изменить вручную», чтобы задать его самостоятельно.",
```

`lang/admin/uk.json`:
```json
  "products.form.sku_edit_manually": "Редагувати вручну",
  "products.form.sku_hint": "Автоматично генерується з англійської назви товару. Натисніть «Редагувати вручну», щоб задати його самостійно.",
```

`lang/admin/sk.json`:
```json
  "products.form.sku_edit_manually": "Upraviť ručne",
  "products.form.sku_hint": "Automaticky generované z anglického názvu produktu. Kliknutím na „Upraviť ručne“ ho môžete nastaviť sami.",
```

(Alphabetically: `sku` < `sku_edit_manually` < `sku_hint` < `stock_label`, so both new
keys land together right after the existing `products.form.sku` line and before
`products.form.stock_label`.)

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
Expected: `OK, all files have 267 identical keys` (265 existing + 2 new).

- [ ] **Step 3: Commit**

```bash
git add lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/uk.json lang/admin/sk.json
git commit -m "feat: add SKU auto-generation hint translations"
```

---

### Task 4: Create-form UI — readonly SKU field, live preview, unlock button

**Files:**
- Modify: `templates/admin/products/form.twig`
- Modify: `www/assets/css/admin.css`

**Interfaces:**
- Consumes: `products.form.sku_edit_manually`, `products.form.sku_hint` (Task 3).

- [ ] **Step 1: Update the SKU field markup**

In `templates/admin/products/form.twig`, replace:

```twig
            <div class="form-group">
                <label>{{ t('products.form.sku') }}</label>
                <input type="text" name="sku" value="{{ product.sku ?? '' }}" required>
            </div>
```

with:

```twig
            <div class="form-group">
                <label>{{ t('products.form.sku') }}</label>
                <input type="text" id="sku-input" class="sku-input" name="sku" value="{{ product.sku ?? '' }}" {% if not product %}readonly{% endif %} required>
                {% if not product %}
                <div style="margin-top:0.35rem;">
                    <button type="button" id="sku-edit-btn" class="btn-link" style="font-size:0.85rem">{{ t('products.form.sku_edit_manually') }}</button>
                </div>
                <p class="audit-meta" style="margin-top:0.35rem;">{{ t('products.form.sku_hint') }}</p>
                {% endif %}
            </div>
```

- [ ] **Step 2: Add the live-preview / unlock JS**

In the same file's `{% block scripts %}`, add this block right after the "Stock quantity field toggle" IIFE and before the "Translate buttons" section:

```javascript
// SKU auto-generation from English name (create form only — readonly + unlock button
// are only rendered when there's no existing product)
(function () {
    const skuInput    = document.getElementById('sku-input');
    const editBtn     = document.getElementById('sku-edit-btn');
    const enNameInput = document.querySelector('input[name="t[en][name]"]');
    if (!skuInput || !editBtn || !enNameInput) return;

    function slugify(s) {
        return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }

    enNameInput.addEventListener('input', () => {
        if (skuInput.readOnly) {
            skuInput.value = slugify(enNameInput.value);
        }
    });

    editBtn.addEventListener('click', () => {
        skuInput.readOnly = false;
        skuInput.focus();
        editBtn.style.display = 'none';
    });
})();
```

- [ ] **Step 3: Add the readonly styling**

In `www/assets/css/admin.css`, insert this line right after the shared text-input rule block (after the line ending `.admin-form textarea { width:100%; ...box-sizing:border-box; }`, i.e. right before `.admin-form textarea { min-height:120px; resize:vertical; }`):

```css
.sku-input[readonly] { background:#f5f5f5; color:#666; }
```

- [ ] **Step 4: Manually verify in the browser**

With the local server running (`docker compose up -d`, `php -S localhost:8080 -t www` if not
already running), log into `/admin/login` and open `http://localhost:8080/admin/products/new`:
- The SKU field should be greyed out (readonly) and empty initially, with the "Edit manually"
  link and hint text visible beneath it.
- Typing into the English (EN) name tab should live-update the SKU field with a slugified
  preview (lowercase, hyphenated).
- Clicking "Edit manually" should un-grey the field, let you type freely, and hide the button.
- Submitting the form should create the product with the generated (or manually-edited) SKU.
- Open `http://localhost:8080/admin/products/{id}/edit` for that product (or any existing
  one) and confirm the SKU field there is a normal, always-editable input with no hint/button
  — the create-only behavior must not appear on the edit form.

- [ ] **Step 5: Commit**

```bash
git add templates/admin/products/form.twig www/assets/css/admin.css
git commit -m "feat: auto-generate SKU on the product create form"
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
curl -s -o /dev/null -w "CS homepage:  %{http_code}\n" http://localhost:8080/cs/
curl -s -o /dev/null -w "Admin login:  %{http_code}\n" http://localhost:8080/admin/login
curl -s -o /dev/null -w "Products new: %{http_code}\n" http://localhost:8080/admin/products/new
```
Expected: `CS homepage` and `Admin login` return `200`; `Products new` returns `302`
if not authenticated in this shell (redirect to login — routing didn't break), or
`200` if a session cookie is present. The authenticated browser check in Task 4 Step 4
already covers the real behavior.

- [ ] **Step 3: Final commit if any stragglers remain**

```bash
git status
```
Expected: clean working tree (everything already committed task-by-task). If anything
is outstanding, commit it with a clear message.
