# Auto-generated product SKU — design

Date: 2026-07-10

## Problem

`products.sku` is manually typed by the admin and used directly as the public product
URL (`/{lang}/shop/{sku}`). Free-form entry produces messy URLs (spaces, mixed case,
e.g. `Latex balloons kitten 50 pcs` → `%20`-encoded in links) and a duplicate SKU
currently crashes with a raw DB error (no uniqueness handling in the controller).

## Scope

Create form only. Editing an existing product's SKU stays exactly as it is today —
free-form, always editable — since changing a live product's SKU changes its public
URL and would regress SEO for already-indexed pages.

## Model — `ProductModel`

Two new static methods, both pure/DB-simple and unit-testable:

- `slugify(string $name): string` — lowercases, collapses runs of non-`[a-z0-9]`
  characters to a single `-`, trims leading/trailing `-`. Returns `"product"` if the
  result is empty (blank or all-symbol input).
- `uniqueSku(string $candidate): string` — returns `$candidate` unchanged if no
  `products` row has that `sku`; otherwise appends `-2`, `-3`, ... (checking each) until
  free. Used for both auto-generated and manually-typed SKUs, so any collision is
  resolved automatically instead of throwing a DB integrity error (fixes an existing
  latent bug as a side effect).

## Controller — `ProductController::createSubmit`

```
sku = trim(body['sku'])
if sku is empty:
    name = trim(body['t']['en']['name'])
    if name is empty:
        name = first non-empty body['t'][{lang}]['name'] across LANGS, in order
    sku = ProductModel::slugify(name)
sku = ProductModel::uniqueSku(sku)
```

Manually-typed SKUs skip `slugify()` — only `uniqueSku()` runs on them — so an admin's
intentional custom value (e.g. matching a physical barcode) keeps its exact
casing/format and only gains a suffix if it collides.

`editSubmit` is unchanged.

## Form — `templates/admin/products/form.twig` (create form only)

- SKU `<input>` starts with the `readonly` attribute (not `disabled` — `disabled`
  inputs aren't submitted with the form; `readonly` ones are) and a `.sku-input` class
  for a muted background style indicating it's locked.
- A new small `.btn-link`-style "Edit manually" button removes `readonly` from the
  field and stops the live-sync described below.
- New vanilla JS in the existing `{% block scripts %}`: on every `input` event on the
  `t[en][name]` field, re-slugify its value (simple ASCII version — lowercase,
  non-alphanumeric → `-`, trim; no diacritic transliteration table needed since EN
  names are already Latin script) and write it into the SKU field, for as long as the
  field is still `readonly`.
- A hint line under the field, reusing the existing `.audit-meta` muted-text style:
  "Auto-generated from the English product name — click Edit to set it manually."
- New admin translation keys (all 5 `lang/admin/*.json`): `products.form.sku_hint`,
  `products.form.sku_edit_manually`.

## CSS — `www/assets/css/admin.css`

```css
.sku-input[readonly] { background: #f5f5f5; color: #666; }
```

## Testing

`ProductModelTest` gains:
- `slugify()`: plain name → kebab-case; punctuation/symbols → collapsed hyphens;
  empty/all-symbol input → `"product"`.
- `uniqueSku()`: no existing match → unchanged; one collision → `-2` suffix; two
  collisions → `-3` suffix.

Controller logic itself is not unit-tested, per this repo's existing convention
(controllers untested; logic that matters lives in the model).

## Out of scope

- No AJAX-based live uniqueness check — the server-side suffix approach makes one
  unnecessary.
- No separate `slug` column decoupled from `sku` (the larger idea discussed earlier of
  giving products a dedicated SEO slug independent of their inventory code) — not part
  of this request.
- No change to existing products' SKUs or URLs.
