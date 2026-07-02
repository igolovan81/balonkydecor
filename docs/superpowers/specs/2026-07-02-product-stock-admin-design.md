# Product Stock Management (Admin) Design

**Date:** 2026-07-02
**Scope:** Admin product form + list — expose the existing `stock_type`/`stock_qty` columns for editing
**Status:** Approved

---

## Overview

`products.stock_type` (`enum('unlimited','limited')`, default `unlimited`) and `products.stock_qty` (`int`, default `0`) have existed in the schema since `V001__schema.sql`, and the public product page's SEO JSON-LD already reads them to report `InStock`/`OutOfStock` availability (`templates/public/shop/product.twig:17`). But nothing in the application ever writes to these columns — the admin product form has no fields for them, so every product is permanently stuck at `unlimited`/`0`, and the SEO signal is always "InStock" regardless of reality.

This change adds admin UI to set and view stock status. It does **not** add any enforcement: the public product page, cart, and checkout are unchanged — customers can still order regardless of stock level. This is a deliberate scope boundary (confirmed with the user): the goal is correct SEO signaling and admin visibility, not inventory enforcement.

---

## Components

### 1. `ProductModel::create()` / `ProductModel::update()` — `src/Models/ProductModel.php`

Both methods gain two new fields, with server-side normalization (never trust the raw posted value):

```php
$stockType = ($data['stock_type'] ?? '') === 'limited' ? 'limited' : 'unlimited';
$stockQty  = $stockType === 'limited' ? max(0, (int) ($data['stock_qty'] ?? 0)) : 0;
```

- `stock_type` defaults to `'unlimited'` for any value other than the literal string `'limited'` (missing key, empty string, garbage input).
- `stock_qty` is forced to `0` whenever `stock_type` resolves to `'unlimited'`, even if a stray/stale quantity was submitted — the column is meaningless in that state and must not carry misleading data.
- `stock_qty` is clamped to a non-negative integer when `stock_type` is `'limited'` (missing/invalid/negative → `0`).

`INSERT`/`UPDATE` statements add `stock_type` and `stock_qty` to their column lists and bound parameters. No other method changes: `allActive()`, `findBySku()`, `all()`, and `findById()` already `SELECT` these columns (via `p.*` or explicit column lists), so they need no changes to start returning real values once `create`/`update` start writing them.

### 2. Admin product form — `templates/admin/products/form.twig`, `src/Controllers/Admin/ProductController.php`

New form fields placed after the existing Category field and before the Active checkbox:

```twig
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
```

A small inline script (same `{% block scripts %}`, same plain-`addEventListener` style already used for lang tabs and the translate button) toggles `#stock-qty-group`'s visibility on `#stock-type-select`'s `change` event, and runs once on page load to set the correct initial state (server-rendered `style="display:none"` already handles the no-JS/first-paint case; the script keeps it in sync on interaction).

`ProductController::createSubmit()` and `editSubmit()` add `stock_type` and `stock_qty` to the array passed into `ProductModel::create()`/`update()`:

```php
'stock_type' => $body['stock_type'] ?? 'unlimited',
'stock_qty'  => $body['stock_qty'] ?? 0,
```

(The actual normalization/clamping happens inside `ProductModel`, per Component 1 — the controller passes the raw posted values through unchanged, consistent with how it already does for every other field.)

### 3. Admin product list — `templates/admin/products/index.twig`

New "Skladem" column inserted between Price and Active:

```twig
<th>{{ t('products.col.stock') }}</th>
...
<td>{{ p.stock_type == 'limited' ? p.stock_qty ~ ' ks' : '—' }}</td>
```

`ProductModel::all()` already returns `stock_type`/`stock_qty` via `p.*` — no model change needed for the list.

### 4. Translations — `lang/admin/{cs,en,sk,ru,uk}.json`

Five new keys, added in alphabetical position within their existing groups:

| Key | cs | en |
|---|---|---|
| `products.col.stock` | Skladem | Stock |
| `products.form.stock_label` | Skladem | Stock |
| `products.form.stock_limited` | Omezeno | Limited |
| `products.form.stock_qty_label` | Množství | Quantity |
| `products.form.stock_unlimited` | Neomezeno | Unlimited |

(sk/ru/uk get natural-language equivalents in the same pattern as existing keys in those files.)

---

## Data Flow

```
Admin opens /admin/products/{id}/edit
  → ProductController::editForm → ProductModel::findById() → product.stock_type/stock_qty (already SELECT *)
  → form.twig renders dropdown at current value, qty field shown/hidden accordingly

Admin selects "Omezeno", enters qty=5, saves
  → POST /admin/products/{id}/edit → body: stock_type=limited, stock_qty=5
  → ProductController::editSubmit passes both through to ProductModel::update()
  → ProductModel::update() normalizes: stock_type='limited' (valid), stock_qty=max(0, 5)=5
  → UPDATE products SET ..., stock_type='limited', stock_qty=5 WHERE id=...

Public product page (unchanged)
  → product.stock_type/stock_qty now reflect real admin input
  → JSON-LD 'availability' correctly reports OutOfStock once stock_qty reaches 0 for a limited product
```

---

## Error Handling

| Scenario | Behavior |
|---|---|
| `stock_type` missing or garbage value in POST body | Normalized to `'unlimited'` |
| `stock_type='limited'` but `stock_qty` missing/non-numeric | Normalized to `0` |
| `stock_type='limited'` with negative `stock_qty` | Clamped to `0` |
| `stock_type='unlimited'` with a `stock_qty` value present in POST body (e.g. stale hidden field) | Ignored — always stored as `0` |

---

## Testing

- `ProductModelTest::create()`/`update()` (new tests, no existing coverage for these two methods):
  - creating/updating with `stock_type='limited'`, `stock_qty=5` persists both values exactly.
  - omitting `stock_type`/`stock_qty` entirely defaults to `unlimited`/`0`.
  - `stock_type='limited'` with a negative `stock_qty` clamps to `0`.
  - `stock_type='unlimited'` with a non-zero `stock_qty` in the input is stored as `0`.
- No changes to `allActive()`/`findBySku()`/`all()`/`findById()` tests — those methods are unchanged; existing tests continue to pass since `SELECT *` / explicit column lists already include the stock columns.

---

## Out of Scope

- Public product page / shop listing stock display or "out of stock" states.
- Cart quantity limits based on stock.
- Checkout blocking or validation based on stock.
- Automatic stock decrement on order placement.
- Category-level or bulk stock editing.
