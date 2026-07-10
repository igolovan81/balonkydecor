# Product Subtypes — Design Spec

Date: 2026-07-10

## Problem

Some products are sold as a single package but priced differently depending
on which variant the customer picks — e.g. a 50-piece pack of latex balloons
where "Macarons" costs 1.90 Kč/pc, "Pastel" 1.80 Kč/pc, "ШДМ" 1.20 Kč/pc,
"Metallic" 1.90 Kč/pc, "Chrome" 3.40 Kč/pc. Today `products` has a single
`price` column, so this can only be modeled as separate catalog products per
color, duplicating category/image/description management. Products need an
optional set of named, individually priced **subtypes**.

## Scope

- Optional per product: a product with zero subtypes behaves exactly as
  today (single `price`, no selector).
- When a product has ≥1 subtype, its subtypes are **required** at add-to-cart
  time — the base `price`/`stock_type`/`stock_qty` become an internal
  fallback that is never charged directly once subtypes exist, and stock
  stays tracked at the product level (shared pool across subtypes — no
  per-subtype stock).
- Subtype names are fully translatable (5 languages), same convention as
  product names.
- Cart/order must record which subtype was purchased, at its own price,
  alongside the existing product snapshot.
- Admin manages subtypes inline on the existing product edit form — no new
  admin route.

## Data model

New migration `V021__product_subtypes.sql`:

```sql
CREATE TABLE product_subtypes (
  id           INT NOT NULL AUTO_INCREMENT,
  product_id   INT NOT NULL,
  price        DECIMAL(10,2) NOT NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE product_subtype_t (
  id          INT NOT NULL AUTO_INCREMENT,
  subtype_id  INT NOT NULL,
  lang_code   VARCHAR(5) NOT NULL,
  name        VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY subtype_lang (subtype_id, lang_code),
  FOREIGN KEY (subtype_id) REFERENCES product_subtypes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE order_items
  ADD COLUMN subtype_id INT NULL AFTER product_id,
  ADD COLUMN subtype_name_snapshot VARCHAR(255) NULL AFTER product_name_snapshot,
  ADD FOREIGN KEY (subtype_id) REFERENCES product_subtypes(id) ON DELETE SET NULL;
```

No `is_active` column on `product_subtypes` — subtypes are lightweight
price options managed by add/remove rows, not soft-deleted like products.

## Model changes

### `ProductModel`

- `findById(int $id): ?array` — add a `subtypes` array: each row is
  `{id, price, sort_order, t: {lang_code: name, ...}}` (all languages, for
  the admin form), ordered by `sort_order, id`.
- `findBySku(string $sku, string $lang): ?array` — add a `subtypes` array
  resolved to the requested language: each row is `{id, price, name}` via a
  plain join on `lang_code = :lang`. No `COALESCE` fallback needed: subtype
  names go through the same `Translator::autoFill()` path as product names
  (see Admin UI below), so all 5 language rows always exist by the time a
  subtype is saved — identical guarantee to `product_t`.
- `allActive(string $lang, ?int $categoryId = null): array` — add
  `min_subtype_price` via a correlated subquery
  (`(SELECT MIN(price) FROM product_subtypes WHERE product_id = p.id) AS min_subtype_price`),
  `NULL` when the product has none.
- New `setSubtypes(int $productId, array $rows): void` — delete-all-then-
  reinsert for that product: `DELETE FROM product_subtypes WHERE product_id = ?`
  then insert each row (in submitted order, `sort_order` = array index) via
  `product_subtypes` + `product_subtype_t`, skipping rows whose default-
  language name is empty. Chosen over ID-diffing/upsert because this is a
  small, admin-only, fully-replaced-on-every-save list (mirrors how
  `product_images` are managed as a flat list, not a diffed set).
- New `getSubtypes(int $productId): array` — used by `findById`; kept as a
  separate method so `ProductModelTest` can assert on it directly.

### `OrderModel::create()`

Currently:
```php
INSERT INTO order_items (order_id, product_id, quantity, unit_price, product_name_snapshot)
VALUES (?, (SELECT id FROM products WHERE sku = ? LIMIT 1), ?, ?, ?)
...
foreach ($cartItems as $sku => $item) {
    $itemStmt->execute([$id, $sku, $item['qty'], $item['price'], $item['name']]);
}
```
The `$sku` used for the lookup is the **cart array key**, which today always
equals the real SKU. Once subtypes exist, the cart key can be a composite
`"{sku}:{subtypeId}"` (see Cart below), so the lookup must switch to reading
the real SKU off the item value instead of the loop key:

```php
INSERT INTO order_items
    (order_id, product_id, subtype_id, quantity, unit_price, product_name_snapshot, subtype_name_snapshot)
VALUES (?, (SELECT id FROM products WHERE sku = ? LIMIT 1), ?, ?, ?, ?, ?)
...
foreach ($cartItems as $item) {
    $itemStmt->execute([
        $id, $item['sku'], $item['subtype_id'] ?? null,
        $item['qty'], $item['price'], $item['name'], $item['subtype_name'] ?? null,
    ]);
}
```

## Cart changes (`src/Services/Cart.php`)

- `add()` signature becomes
  `add(string $sku, int $qty, string $name, string $price, ?int $subtypeId = null, ?string $subtypeName = null): void`.
- Session line key: `$subtypeId !== null ? "{$sku}:{$subtypeId}" : $sku` —
  existing single-price products keep today's plain-SKU key, so no
  migration/back-compat concern for carts already in a live session.
- Stored item value gains `sku` and, when present, `subtype_id`/
  `subtype_name` fields (in addition to today's `qty`, `name`, `price`) so
  `OrderModel::create()` and any future cart logic can recover the real SKU
  without parsing the composite key.
- `items()`, `total()`, `count()`, `remove()`, `update()` are unchanged —
  they already treat the array key as an opaque line identifier.

## Controller changes

### `CartController::add()`

```php
$product = ProductModel::findBySku($sku, $lang);
if ($product) {
    if (!empty($product['subtypes'])) {
        $subtypeId = isset($body['subtype_id']) && $body['subtype_id'] !== ''
            ? (int) $body['subtype_id'] : null;
        $subtype = null;
        foreach ($product['subtypes'] as $st) {
            if ($st['id'] === $subtypeId) { $subtype = $st; break; }
        }
        if ($subtype) {
            Cart::add(
                $sku, $qty,
                $product['name'] . ' — ' . $subtype['name'],
                (string) $subtype['price'],
                $subtype['id'], $subtype['name']
            );
        }
        // no valid subtype selected → do nothing, redirect back to cart unchanged
    } else {
        Cart::add($sku, $qty, $product['name'], (string) $product['price']);
    }
}
```
The subtype price is always resolved server-side from `findBySku()`, never
taken from the POST body directly.

### `Admin\ProductController::createSubmit()` / `editSubmit()`

Parse `$body['subtypes'] ?? []` (array of `{price, t: {lang: name}}`). For
each row, run its `t` array through the existing
`Translator::autoFill($row['t'], $adminLang, self::LANGS, ['name'])` (same
helper `createSubmit` already applies to product-level translations) so the
admin only has to type the subtype name once, in their own language — this
avoids building a second set of per-row, per-language "Translate" buttons in
the UI. Then call `ProductModel::setSubtypes($id, $subtypes)` after the
existing `setTranslations()` call. Same handling in both `createSubmit` and
`editSubmit` (subtypes always auto-fill, unlike product-level translations
where `editSubmit` relies on the manual per-lang-tab translate buttons).

## Public UI

### `templates/public/shop/product.twig`

- When `product.subtypes` is non-empty: replace the static price with a
  `<select name="subtype_id">` listing `{{ subtype.name }} — {{ subtype.price }} Kč`
  per option, plus a price display (`<span id="selected-price">`) updated by
  a small vanilla `<script>` on `change` (no build step, consistent with
  `product-gallery.js`). The add-to-cart form's hidden `sku`/`qty` fields are
  unchanged; `subtype_id` is submitted via the select.
- When `product.subtypes` is empty: today's static price markup, unchanged.
- JSON-LD `Offer`: when subtypes exist, switch to `AggregateOffer` with
  `lowPrice`/`highPrice` computed from `product.subtypes`; otherwise keep
  today's single `Offer`/`price`.

### `templates/public/shop/index.twig`

- Product card price line: if `product.min_subtype_price` is not null, show
  `{{ t('shop.from_price', {price: product.min_subtype_price|number_format(2, '.', ' ')}) }}`
  (e.g. "od 60 Kč"); otherwise today's `{{ product.price|number_format(...) }} Kč`.
- New translation key `shop.from_price` in all 5 `lang/{cs,en,ru,uk,sk}.json`
  files, e.g. cs: `"od {price} Kč"`.

### Cart page

No structural change — `item.name` already carries the full display string
("Product — Subtype") and `item.price`/`subtotal` are already subtype-aware
via `Cart::add()`.

## Admin UI

### `templates/admin/products/form.twig`

New "Subtypes" section in `product-form-main`, after the translations block:

- A table/list of subtype rows. Each row: a price input
  (`subtypes[{i}][price]`) and, inside each existing `.lang-panel`, a name
  input (`subtypes[{i}][t][{lang}][name]`) — reusing the current lang-tab
  switcher so subtype names live in the same per-language panels as the
  product name/description, not a separate tab system.
- "+ Add subtype" button (JS) clones a hidden `<template>` row and appends it
  with the next row index; each row has a "Remove" button that removes it
  from the DOM (no server round-trip — the whole set is resubmitted on save).
- Existing subtypes (`product.subtypes` from `findById`) are rendered as
  pre-filled rows on the edit form.

### `ProductController`

- `createForm`/`editForm`: no new data needed beyond what `findById()` now
  returns (`product.subtypes`); `createForm` passes an empty list.
- `createSubmit`/`editSubmit`: call `ProductModel::setSubtypes()` as
  described above.

### New translation keys (`lang/admin/{cs,en,ru,uk,sk}.json`)

- `products.form.subtypes` — section heading.
- `products.form.subtype_price` — price field label.
- `products.form.subtype_add` — add-row button.
- `products.form.subtype_remove` — remove-row button.

## Testing

- `ProductModelTest`:
  - `test_set_subtypes_creates_and_returns_translated_rows` — create a
    product, `setSubtypes()` with 2 rows (each with cs/en names), assert
    `findById()` returns them with correct price/order/translations.
  - `test_set_subtypes_replaces_existing_rows` — call `setSubtypes()` twice
    with different row sets; assert the second call fully replaces the
    first (no leftover rows).
  - `test_find_by_sku_resolves_subtype_names_for_requested_lang`.
  - `test_all_active_reports_min_subtype_price_for_products_with_subtypes`
    and a companion asserting `min_subtype_price` is `null` for a product
    without subtypes.
- `OrderModelTest`:
  - `test_create_persists_subtype_id_and_name_snapshot` — build a
    `$cartItems` array shaped like `Cart::items()` output for a
    subtype-bearing line (composite key, `sku`/`subtype_id`/`subtype_name`
    fields present), call `OrderModel::create()`, assert the resulting
    `order_items` row has the right `product_id` (resolved from `sku`, not
    the composite key), `subtype_id`, and `subtype_name_snapshot`.
- Cart is a pure session service with no DB — covered by a lightweight test
  if `tests/Unit/Services/CartTest.php` exists (create if not): adding the
  same SKU with two different `$subtypeId` values produces two lines;
  adding the same SKU+subtype twice accumulates `qty`.
- No controller tests, per `.claude/rules/unit-testing.md` — the product
  page selector and admin subtype rows are verified manually via
  `php -S localhost:8080 -t www`.

## Out of scope

- Per-subtype stock tracking (stays at the product level, per explicit
  decision).
- Subtype-specific images.
- Bulk-editing subtypes across multiple products.
- Migrating any existing product into subtypes automatically — this is a
  net-new opt-in feature per product.
