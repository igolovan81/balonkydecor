# Product Stock Management (Admin) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins set and view a product's stock status (unlimited vs. limited-with-quantity) from the admin panel, so the existing SEO JSON-LD `InStock`/`OutOfStock` signal on the public product page reflects reality instead of always reporting `InStock`.

**Architecture:** The `products.stock_type`/`products.stock_qty` columns already exist in the schema and are already read by `ProductModel`'s query methods and the public product page's JSON-LD — only the write path and admin UI are missing. Add server-side normalization to `ProductModel::create()`/`update()`, a dropdown + conditional quantity field to the admin product form, a JS toggle to show/hide the quantity field, and a stock column to the admin product list.

**Tech Stack:** PHP 8 / Slim 4, Twig 3, vanilla JS (no build step), PHPUnit 11, MySQL 8.

## Global Constraints

- `stock_type` is normalized server-side to `'unlimited'` unless the posted value is the literal string `'limited'` — never trust the raw value.
- `stock_qty` is always stored as `0` when `stock_type` resolves to `'unlimited'`, regardless of what was submitted.
- `stock_qty` is clamped to a non-negative integer when `stock_type` is `'limited'` (missing/invalid/negative → `0`).
- Scope is admin-only: no changes to cart, checkout, or the public product/shop pages. The public JSON-LD already reads these columns and needs no changes to start reflecting real data.
- No changes to `ProductModel::allActive()`, `findBySku()`, `all()`, or `findById()` — all four already `SELECT` the stock columns.

---

## File Structure

- `src/Models/ProductModel.php` — `create()`/`update()` gain stock normalization and persistence.
- `tests/Unit/Models/ProductModelTest.php` — new tests for stock persistence/defaulting/clamping.
- `src/Controllers/Admin/ProductController.php` — `createSubmit()`/`editSubmit()` pass posted stock fields through to the model.
- `templates/admin/products/form.twig` — stock type dropdown + conditional quantity field + JS toggle.
- `templates/admin/products/index.twig` — new stock column.
- `lang/admin/{cs,en,sk,ru,uk}.json` — new translation keys for both the form and the list column.

---

## Task 1: `ProductModel` stock persistence

**Files:**
- Modify: `src/Models/ProductModel.php:78-107` (`create()` and `update()`)
- Test: `tests/Unit/Models/ProductModelTest.php`

**Interfaces:**
- Produces: `ProductModel::create(array $data): int` and `ProductModel::update(int $id, array $data): void` now read optional `$data['stock_type']` and `$data['stock_qty']` keys, normalize them, and persist to the `stock_type`/`stock_qty` columns. `findById()` (unchanged, already `SELECT *`) returns these as `$product['stock_type']` (string) and `$product['stock_qty']` (numeric string from MySQL — cast with `(int)` when asserting).

- [ ] **Step 1: Write the failing tests**

Add these five methods inside the `ProductModelTest` class (before the closing `}`), right after `test_set_translations_stores_meta_fields`:

```php
    public function test_create_persists_limited_stock(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-STOCK-' . uniqid(),
            'price'       => 19.99,
            'category_id' => self::$categoryId,
            'stock_type'  => 'limited',
            'stock_qty'   => 5,
        ]);
        $product = ProductModel::findById($id);
        $this->assertSame('limited', $product['stock_type']);
        $this->assertSame(5, (int) $product['stock_qty']);
    }

    public function test_create_defaults_to_unlimited_when_stock_fields_omitted(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-STOCK-' . uniqid(),
            'price'       => 19.99,
            'category_id' => self::$categoryId,
        ]);
        $product = ProductModel::findById($id);
        $this->assertSame('unlimited', $product['stock_type']);
        $this->assertSame(0, (int) $product['stock_qty']);
    }

    public function test_create_clamps_negative_stock_qty_to_zero(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-STOCK-' . uniqid(),
            'price'       => 19.99,
            'category_id' => self::$categoryId,
            'stock_type'  => 'limited',
            'stock_qty'   => -5,
        ]);
        $product = ProductModel::findById($id);
        $this->assertSame(0, (int) $product['stock_qty']);
    }

    public function test_create_forces_zero_qty_when_unlimited(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-STOCK-' . uniqid(),
            'price'       => 19.99,
            'category_id' => self::$categoryId,
            'stock_type'  => 'unlimited',
            'stock_qty'   => 42,
        ]);
        $product = ProductModel::findById($id);
        $this->assertSame('unlimited', $product['stock_type']);
        $this->assertSame(0, (int) $product['stock_qty']);
    }

    public function test_update_persists_limited_stock(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-STOCK-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
        ]);
        ProductModel::update($id, [
            'sku'         => 'TEST-STOCK-UPDATED-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
            'stock_type'  => 'limited',
            'stock_qty'   => 3,
        ]);
        $product = ProductModel::findById($id);
        $this->assertSame('limited', $product['stock_type']);
        $this->assertSame(3, (int) $product['stock_qty']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php --testdox`
Expected: FAIL — `create()`/`update()` don't yet write `stock_type`/`stock_qty`, so every new assertion mismatches (e.g. `'unlimited'` expected `'limited'`, or vice versa, since the DB default is `unlimited`/`0`).

- [ ] **Step 3: Implement stock normalization in `create()` and `update()`**

In `src/Models/ProductModel.php`, replace the `create()` method:

```php
    public static function create(array $data): int
    {
        $pdo       = Database::getConnection();
        $stockType = ($data['stock_type'] ?? '') === 'limited' ? 'limited' : 'unlimited';
        $stockQty  = $stockType === 'limited' ? max(0, (int) ($data['stock_qty'] ?? 0)) : 0;
        $stmt = $pdo->prepare(
            'INSERT INTO products (sku, price, category_id, is_active, stock_type, stock_qty, sort_order)
             VALUES (:sku, :price, :category_id, :is_active, :stock_type, :stock_qty, 0)'
        );
        $stmt->execute([
            'sku'         => $data['sku'],
            'price'       => $data['price'],
            'category_id' => $data['category_id'] ?: 1,
            'is_active'   => (int) ($data['is_active'] ?? 1),
            'stock_type'  => $stockType,
            'stock_qty'   => $stockQty,
        ]);
        return (int) $pdo->lastInsertId();
    }
```

Replace the `update()` method:

```php
    public static function update(int $id, array $data): void
    {
        $pdo       = Database::getConnection();
        $stockType = ($data['stock_type'] ?? '') === 'limited' ? 'limited' : 'unlimited';
        $stockQty  = $stockType === 'limited' ? max(0, (int) ($data['stock_qty'] ?? 0)) : 0;
        $stmt = $pdo->prepare(
            'UPDATE products SET sku = :sku, price = :price, category_id = :category_id, is_active = :is_active,
                                  stock_type = :stock_type, stock_qty = :stock_qty WHERE id = :id'
        );
        $stmt->execute([
            'sku'         => $data['sku'],
            'price'       => $data['price'],
            'category_id' => $data['category_id'] ?: 1,
            'is_active'   => (int) ($data['is_active'] ?? 1),
            'stock_type'  => $stockType,
            'stock_qty'   => $stockQty,
            'id'          => $id,
        ]);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php --testdox`
Expected: PASS, all 9 tests green (4 existing + 5 new).

- [ ] **Step 5: Run the full suite to confirm nothing else broke**

Run: `php vendor/bin/phpunit --testdox`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Models/ProductModel.php tests/Unit/Models/ProductModelTest.php
git commit -m "feat: persist product stock type/quantity with server-side normalization"
```

---

## Task 2: Admin product form — stock dropdown + conditional quantity field

**Files:**
- Modify: `templates/admin/products/form.twig`
- Modify: `src/Controllers/Admin/ProductController.php:33-52` (`createSubmit`), `:68-88` (`editSubmit`)
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/sk.json`, `lang/admin/ru.json`, `lang/admin/uk.json`

**Interfaces:**
- Consumes: `ProductModel::create()`/`update()` from Task 1, which already normalize whatever `stock_type`/`stock_qty` values are passed in `$data`.

No automated template/JS/controller tests exist for this path in this repo (confirmed in prior work on this codebase — no HTTP/functional test harness). Verified by the full PHPUnit suite (sanity) and later by manual browser testing (Task 4).

- [ ] **Step 1: Add form-related translation keys to all 5 admin lang files**

Insert 4 new lines immediately after the `products.form.sku` line and before the `products.form.title_edit` line in each file (alphabetical position: `sku` < `stock_label` < `stock_limited` < `stock_qty_label` < `stock_unlimited` < `title_edit`).

`lang/admin/cs.json` (after `"products.form.sku": "SKU",`):
```json
  "products.form.stock_label": "Skladem",
  "products.form.stock_limited": "Omezeno",
  "products.form.stock_qty_label": "Množství",
  "products.form.stock_unlimited": "Neomezeno",
```

`lang/admin/en.json` (after `"products.form.sku": "SKU",`):
```json
  "products.form.stock_label": "Stock",
  "products.form.stock_limited": "Limited",
  "products.form.stock_qty_label": "Quantity",
  "products.form.stock_unlimited": "Unlimited",
```

`lang/admin/sk.json` (after `"products.form.sku": "SKU",`):
```json
  "products.form.stock_label": "Skladom",
  "products.form.stock_limited": "Obmedzené",
  "products.form.stock_qty_label": "Množstvo",
  "products.form.stock_unlimited": "Neobmedzené",
```

`lang/admin/ru.json` (after `"products.form.sku": "SKU",`):
```json
  "products.form.stock_label": "На складе",
  "products.form.stock_limited": "Ограничено",
  "products.form.stock_qty_label": "Количество",
  "products.form.stock_unlimited": "Неограничено",
```

`lang/admin/uk.json` (after `"products.form.sku": "SKU",`):
```json
  "products.form.stock_label": "На складі",
  "products.form.stock_limited": "Обмежено",
  "products.form.stock_qty_label": "Кількість",
  "products.form.stock_unlimited": "Необмежено",
```

- [ ] **Step 2: Verify all 5 admin lang files still have identical key counts**

Run:
```bash
for f in lang/admin/*.json; do php -r "echo count(json_decode(file_get_contents('$f'), true)) . ' $f' . PHP_EOL;"; done
```
Expected: all 5 files report the same key count, 4 more than before this task.

- [ ] **Step 3: Add the stock dropdown and quantity field to the form template**

In `templates/admin/products/form.twig`, insert this block between the Category field's closing `</div>` and the Active checkbox's opening `<div class="form-group">` — i.e. replace:

```twig
    <div class="form-group">
        <label>{{ t('products.form.category') }}</label>
        <select name="category_id">
            {% for cat in categories %}
            <option value="{{ cat.id }}" {% if product.category_id == cat.id %}selected{% endif %}>{{ cat.name }}</option>
            {% endfor %}
        </select>
    </div>
    <div class="form-group">
        <label>
            <input type="checkbox" name="is_active" value="1" {% if product is null or product.is_active %}checked{% endif %}>
            {{ t('products.form.active') }}
        </label>
    </div>
```

with:

```twig
    <div class="form-group">
        <label>{{ t('products.form.category') }}</label>
        <select name="category_id">
            {% for cat in categories %}
            <option value="{{ cat.id }}" {% if product.category_id == cat.id %}selected{% endif %}>{{ cat.name }}</option>
            {% endfor %}
        </select>
    </div>
    <div class="form-group">
        <label>{{ t('products.form.stock_label') }}</label>
        <select name="stock_type" id="stock-type-select">
            <option value="unlimited" {% if (product.stock_type ?? 'unlimited') == 'unlimited' %}selected{% endif %}>{{ t('products.form.stock_unlimited') }}</option>
            <option value="limited" {% if (product.stock_type ?? '') == 'limited' %}selected{% endif %}>{{ t('products.form.stock_limited') }}</option>
        </select>
    </div>
    <div class="form-group" id="stock-qty-group" style="{% if (product.stock_type ?? 'unlimited') != 'limited' %}display:none;{% endif %}">
        <label>{{ t('products.form.stock_qty_label') }}</label>
        <input type="number" name="stock_qty" min="0" step="1" value="{{ product.stock_qty ?? 0 }}">
    </div>
    <div class="form-group">
        <label>
            <input type="checkbox" name="is_active" value="1" {% if product is null or product.is_active %}checked{% endif %}>
            {{ t('products.form.active') }}
        </label>
    </div>
```

- [ ] **Step 4: Add the show/hide JS toggle**

In the same file's `{% block scripts %}`, insert this block right after the lang-tab click-handler block and before the `// Translate buttons` comment — i.e. replace:

```twig
document.querySelectorAll('.lang-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.lang-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.lang-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel-' + tab.dataset.lang).classList.add('active');
    });
});

// Translate buttons — JS runtime strings remain hardcoded Czech per spec
```

with:

```twig
document.querySelectorAll('.lang-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.lang-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.lang-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel-' + tab.dataset.lang).classList.add('active');
    });
});

// Stock quantity field toggle
(function () {
    const stockTypeSelect = document.getElementById('stock-type-select');
    const stockQtyGroup   = document.getElementById('stock-qty-group');
    if (!stockTypeSelect || !stockQtyGroup) return;

    function syncStockQtyVisibility() {
        stockQtyGroup.style.display = stockTypeSelect.value === 'limited' ? '' : 'none';
    }

    stockTypeSelect.addEventListener('change', syncStockQtyVisibility);
    syncStockQtyVisibility();
})();

// Translate buttons — JS runtime strings remain hardcoded Czech per spec
```

- [ ] **Step 5: Wire the posted fields through in `ProductController`**

In `src/Controllers/Admin/ProductController.php`, in `createSubmit()`, replace:

```php
        $id   = ProductModel::create([
            'sku'         => trim($body['sku'] ?? ''),
            'price'       => $body['price'] ?? '0.00',
            'category_id' => $body['category_id'] ?? 1,
            'is_active'   => isset($body['is_active']) ? 1 : 0,
        ]);
```

with:

```php
        $id   = ProductModel::create([
            'sku'         => trim($body['sku'] ?? ''),
            'price'       => $body['price'] ?? '0.00',
            'category_id' => $body['category_id'] ?? 1,
            'is_active'   => isset($body['is_active']) ? 1 : 0,
            'stock_type'  => $body['stock_type'] ?? 'unlimited',
            'stock_qty'   => $body['stock_qty'] ?? 0,
        ]);
```

In `editSubmit()`, replace:

```php
        ProductModel::update($id, [
            'sku'         => trim($body['sku'] ?? ''),
            'price'       => $body['price'] ?? '0.00',
            'category_id' => $body['category_id'] ?? 1,
            'is_active'   => isset($body['is_active']) ? 1 : 0,
        ]);
```

with:

```php
        ProductModel::update($id, [
            'sku'         => trim($body['sku'] ?? ''),
            'price'       => $body['price'] ?? '0.00',
            'category_id' => $body['category_id'] ?? 1,
            'is_active'   => isset($body['is_active']) ? 1 : 0,
            'stock_type'  => $body['stock_type'] ?? 'unlimited',
            'stock_qty'   => $body['stock_qty'] ?? 0,
        ]);
```

- [ ] **Step 6: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add templates/admin/products/form.twig src/Controllers/Admin/ProductController.php lang/admin/cs.json lang/admin/en.json lang/admin/sk.json lang/admin/ru.json lang/admin/uk.json
git commit -m "feat: add stock dropdown and quantity field to admin product form"
```

---

## Task 3: Admin product list — stock column

**Files:**
- Modify: `templates/admin/products/index.twig`
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/sk.json`, `lang/admin/ru.json`, `lang/admin/uk.json`

**Interfaces:**
- Consumes: `ProductModel::all()` (unchanged — already returns `stock_type`/`stock_qty` via `p.*`).

No automated template tests exist for this path. Verified by the full PHPUnit suite (sanity) and later by manual browser testing (Task 4).

- [ ] **Step 1: Add the list-column translation key to all 5 admin lang files**

Insert 1 new line immediately after the `products.col.sku` line and before the `products.confirm_delete` line in each file (alphabetical position: `sku` < `stock` and `products.col.*` sorts before `products.confirm_delete`).

`lang/admin/cs.json` (after `"products.col.sku": "SKU",`):
```json
  "products.col.stock": "Skladem",
```

`lang/admin/en.json` (after `"products.col.sku": "SKU",`):
```json
  "products.col.stock": "Stock",
```

`lang/admin/sk.json` (after `"products.col.sku": "SKU",`):
```json
  "products.col.stock": "Skladom",
```

`lang/admin/ru.json` (after `"products.col.sku": "SKU",`):
```json
  "products.col.stock": "На складе",
```

`lang/admin/uk.json` (after `"products.col.sku": "SKU",`):
```json
  "products.col.stock": "На складі",
```

- [ ] **Step 2: Verify all 5 admin lang files still have identical key counts**

Run:
```bash
for f in lang/admin/*.json; do php -r "echo count(json_decode(file_get_contents('$f'), true)) . ' $f' . PHP_EOL;"; done
```
Expected: all 5 files report the same key count, 1 more than after Task 2.

- [ ] **Step 3: Add the stock column to the product list table**

Replace the full contents of `templates/admin/products/index.twig` with:

```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ t('products.title') }}{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>{{ t('products.title') }}</h1>
    <a href="/admin/products/new" class="btn btn-primary">{{ t('products.add') }}</a>
</div>
<table class="admin-table">
    <thead>
        <tr>
            <th>{{ t('products.col.image') }}</th>
            <th>{{ t('products.col.sku') }}</th>
            <th>{{ t('products.col.category') }}</th>
            <th>{{ t('products.col.price') }}</th>
            <th>{{ t('products.col.stock') }}</th>
            <th>{{ t('products.col.active') }}</th>
            <th>{{ t('products.col.actions') }}</th>
        </tr>
    </thead>
    <tbody>
    {% for p in products %}
    <tr>
        <td>
            {% if p.primary_image %}
            <img src="/assets/uploads/products/thumb_{{ p.primary_image }}" class="img-thumb">
            {% endif %}
        </td>
        <td>{{ p.sku }}</td>
        <td>{{ p.category_name ?? '—' }}</td>
        <td>{{ p.price|number_format(2, '.', ' ') }} Kč</td>
        <td>{{ p.stock_type == 'limited' ? p.stock_qty ~ ' ks' : '—' }}</td>
        <td>{{ p.is_active ? '✓' : '—' }}</td>
        <td>
            <a href="/admin/products/{{ p.id }}/edit">{{ t('products.edit') }}</a> |
            <form method="POST" action="/admin/products/{{ p.id }}/delete" style="display:inline" onsubmit="return confirm('Smazat produkt?')">
                <button class="btn-link">{{ t('products.delete') }}</button>
            </form>
        </td>
    </tr>
    {% else %}
    <tr><td colspan="7">{{ t('products.no_products') }}</td></tr>
    {% endfor %}
    </tbody>
</table>
{% endblock %}
```

(The only functional changes from the current file: one new `<th>`/`<td>` pair for stock, and `colspan="6"` → `colspan="7"` on the empty-state row to match the new column count.)

- [ ] **Step 4: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add templates/admin/products/index.twig lang/admin/cs.json lang/admin/en.json lang/admin/sk.json lang/admin/ru.json lang/admin/uk.json
git commit -m "feat: show stock status in admin product list"
```

---

## Task 4: Manual verification in the browser

**Files:** none (verification only, per this project's guidance that UI changes must be checked in a running app before being called done).

- [ ] **Step 1: Start the local stack**

```bash
docker compose up -d
php -S localhost:8080 -t www
```

- [ ] **Step 2: Log in to the admin panel**

Visit `http://localhost:8080/admin/login` and log in (or `/admin/setup` first if no admin user exists).

- [ ] **Step 3: Verify the form toggle**

Open `/admin/products/new`. Confirm the "Skladem" dropdown defaults to "Neomezeno" and the quantity field is hidden. Switch it to "Omezeno" and confirm the quantity field appears (no page reload). Switch back to "Neomezeno" and confirm it hides again.

- [ ] **Step 4: Verify create + persistence**

Fill in SKU/price/EN name, set stock to "Omezeno" with quantity 7, save. Reopen the product for editing and confirm the dropdown shows "Omezeno" and the quantity field shows `7`.

- [ ] **Step 5: Verify the unlimited-forces-zero rule**

On that same product, switch back to "Neomezeno" (leaving the quantity field however it looks — its value doesn't matter once hidden) and save. Reopen it and confirm quantity is now `0` and stays `0` even though the field wasn't touched before the switch.

- [ ] **Step 6: Verify the list column**

Open `/admin/products`. Confirm the new "Skladem" column shows `7 ks` for a limited product and `—` for an unlimited one.

- [ ] **Step 7: Clean up test data**

Delete the test product created in Step 4 via the admin UI (or `DELETE FROM products WHERE sku = '...'` in the dev DB) so it doesn't linger.

This task has no commit — it's a verification pass over the work committed in Tasks 1-3.

---

## Self-Review Notes

- **Spec coverage:** model normalization + persistence (Task 1), admin form dropdown/conditional qty field/JS toggle/controller wiring (Task 2), admin list column (Task 3), all 5 translation keys across all 5 languages (Tasks 2-3) — all covered. Out-of-scope items from the spec (cart/checkout/public UI) are correctly untouched by every task.
- **Placeholder scan:** no TBD/TODO; every step has complete code.
- **Type consistency:** `ProductModel::create(array $data): int` / `update(int $id, array $data): void` signatures are unchanged (still take the same `$data` array shape, just read two more optional keys) — consistent between Task 1's implementation and Task 2's controller call sites. `stock_type`/`stock_qty` key names match exactly across the model, controller, and template's `name="stock_type"` / `name="stock_qty"` form fields.
