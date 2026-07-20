# Product Specifications — Design

## Purpose

Let admins attach a table of attribute name/value rows to a product (material, size,
color, legal/safety notices, etc.), shown on the public product page below the
description — matching the "Products specifications" table pattern seen on reference
competitor sites (Shoptet-based storefronts).

## Constraints

- Reference screenshots (5 different products) show the notice/legal-warning text
  varies per product (latex balloon vs. foil balloon vs. plastic accessory each have
  different wording) — it must be admin-typed per product, not a shared/global field.
- Two of the reference screenshots render a "Color" attribute as a solid swatch box
  instead of text, with no visible text next to it.
- Must follow the existing translatable-child-row pattern already used for product
  subtypes (`product_subtypes` / `product_subtype_t`), including the admin
  add/remove-row UI and MyMemory auto-translate flow.

## Architecture & data model

Two new tables, mirroring `product_subtypes` / `product_subtype_t`:

- **`product_specs`**: `id`, `product_id`, `sort_order` — one row per spec, ordered as
  entered.
- **`product_spec_t`**: `id`, `spec_id`, `lang_code`, `attribute_name` (varchar),
  `attribute_value` (text, nullable) — translatable per language, unique on
  `(spec_id, lang_code)`.

**Row convention** (avoids any extra "row type" column): a spec row with a **blank**
`attribute_value` renders as a full-width bold section-header band (e.g. "Notice" /
"Upozornění"); a row with both name and value renders as a normal two-column row (e.g.
"Material" / "Latex - EKO"). Admins reproduce the exact screenshot layouts — plain
rows, then a header-only row, then a name+long-text row — just by leaving one row's
value empty.

**Color swatch convention**: if a row's `attribute_value` (trimmed) matches a strict
hex-color pattern (`^#[0-9a-fA-F]{3}$` or `^#[0-9a-fA-F]{6}$`), the public page renders
a small colored swatch box instead of text (no visible text, matching the reference
screenshots). Any other value renders as plain text. The strict regex is also the
safety boundary — no value that fails it is ever used as CSS, so there's no injection
surface via `style="background:...`.

## Admin UI & data flow

- **Admin form** (`templates/admin/products/form.twig`): a new "Product
  specifications" section below subtypes, using the same dynamic add/remove-row
  pattern (`<template>` clone + remove button, JS already in the file for subtypes).
  Each row has two plain text inputs: **Attribute name** and **Attribute value**. Order
  = entry order, no drag-reorder (matches subtypes).
- **Save flow** (`Admin\ProductController`): new `buildSpecs()` mirroring
  `buildSubtypes()` — reads `$body['specs']`, skips rows with an empty name, and runs
  both `attribute_name` and `attribute_value` through `Translator::autoFill()` (the
  same MyMemory auto-translate already used for subtype names and product
  name/description) so the admin types once in their current admin language and all 5
  languages get filled automatically.
- **`ProductModel`**:
  - `getSpecs(int $productId): array` — nested `t[lang]` per row, for the edit form
    (mirrors `getSubtypes`).
  - `setSpecs(int $productId, array $rows): void` — delete-all-and-reinsert on save
    (mirrors `setSubtypes`). A row is skipped if its name is empty in every language
    after auto-translate.
  - `findById()` gains `$product['specs'] = self::getSpecs($id)` alongside subtypes.
  - `findBySku()` gains a specs query resolved for the current `$lang` (flat
    `attribute_name`/`attribute_value` per row, same shape as the existing subtypes
    query there) — `$product['specs']`.
  - `clone()` copies specs alongside subtypes, in the same code path.

## Public rendering & translations

- **Placement**: `templates/public/shop/product.twig`, a new full-width section after
  the existing `.product-detail` grid (below gallery/description, not inside the
  two-column layout, matching the reference screenshots). Renders only if
  `product.specs` is non-empty — a product with no spec rows shows nothing.
- **Row rendering**:
  - Blank `attribute_value` → full-width header row (`colspan="2"`, bold, shaded like
    a table header).
  - `attribute_value` matches the hex-color regex → `<span class="spec-swatch"
    style="background:{{ value }}">` with no visible text, plus an `aria-label`
    stating the hex value for screen readers.
  - Otherwise → plain (auto-escaped) text.
- **New translation keys**, all 5 `lang/*.json` files: `shop.specs_title`,
  `shop.specs_attribute_name`, `shop.specs_attribute_value`. All 5 `lang/admin/*.json`
  files: `products.form.specs`, `products.form.spec_name_label`,
  `products.form.spec_value_label`, `products.form.spec_add`,
  `products.form.spec_remove`.

## Error handling

- `setSpecs()` skips a row entirely if `attribute_name` is empty in every language
  after auto-translate — never saves a nameless spec.
- A row with a name but blank value is valid and intentional (renders as a header).
- Swatch detection is strict-regex-only; any non-matching value falls back to normal
  escaped text, so admin-entered values can never inject CSS/HTML via the swatch path.

## Testing

- `tests/Unit/Models/ProductModelTest.php` gains cases for `getSpecs`/`setSpecs`
  (mirroring the existing subtype tests: save rows, read them back, confirm order and
  `t[lang]` nesting) and a `findBySku` case confirming specs resolve correctly for the
  requested language, including a blank-value row and a hex-color row.
- `Admin\ProductController` stays untested per project convention; verify via `/start`
  + browser — including the swatch rendering and the blank-value header-row rendering.

## Out of scope

- `additionalProperty` JSON-LD markup for specs (SEO structured-data enrichment) —
  flagged as a possible future follow-up, not part of this feature.
- Drag-to-reorder spec rows in the admin form.
- Any color-picker widget in the admin form — admins type a hex code directly, same as
  any other attribute value.
- Per-category or global shared notice text — every notice row is typed per product.
