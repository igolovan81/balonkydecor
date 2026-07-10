# Auto-generated category slug — design

Date: 2026-07-10

## Problem

Same underlying issue as the recently-shipped auto-generated product SKU: `categories.slug`
is used directly in public URLs and must stay unique, but today it's either typed by hand
or auto-filled by a JS helper that syncs from whichever language tab the admin currently has
open (with a Czech/Slovak accent-transliteration table) until the admin types into the field
directly. There's no server-side handling for a duplicate slug — `CategoryModel::create()`
would throw a raw DB error on collision, same latent bug class SKU had.

This is a direct port of the SKU design onto categories, per two explicit decisions: use the
same readonly-input + "Edit manually" button mechanism (not the existing type-to-override
JS), and always source the live-preview from the English (`en`) name tab specifically,
not the admin's current-language tab.

## Scope

Create form only. Editing an existing category's slug stays exactly as it is today — a
plain, always-editable input — since changing a live category's slug changes its public URL.

## Model — `CategoryModel`

Two new static methods, mirroring `ProductModel::slugify()` / `ProductModel::uniqueSku()`:

- `slugify(string $name): string` — lowercases, collapses runs of non-`[a-z0-9]` characters
  to a single `-`, trims leading/trailing `-`. Returns `"category"` if the result is empty.
- `uniqueSlug(string $candidate): string` — returns `$candidate` unchanged if no `categories`
  row has that `slug`; otherwise appends `-2`, `-3`, ... until free. Used for both
  auto-generated and manually-typed slugs.

## Controller — `CategoryController::createSubmit`

```
slug = trim(body['slug'])
if slug is empty:
    name = trim(body['t']['en']['name'])
    if name is empty:
        name = first non-empty body['t'][{lang}]['name'] across LANGS (['cs','sk','en','uk','ru']), in order
    slug = CategoryModel::slugify(name)
slug = CategoryModel::uniqueSlug(slug)
```

Manually-typed slugs skip `slugify()` — only `uniqueSlug()` runs on them — so an admin's
intentional custom value keeps its exact casing/format and only gains a suffix if it
collides.

`editSubmit` is unchanged.

## Form — `templates/admin/categories/form.twig` (create form only)

- Remove the existing "Slug auto-generation from preferred-language name" IIFE and its
  `toSlug()` diacritic-transliteration helper — no longer needed since the source is
  always the EN tab (already Latin script) and the lock mechanism is changing.
- Slug `<input>` gains `id="slug-input"`, `class="slug-input"`, and (create only)
  `readonly`.
- New (create only) small `.btn-link` "Edit manually" button (`id="slug-edit-btn"`)
  removes `readonly` and stops the live-sync.
- New vanilla JS (same shape as the SKU version): on every `input` event on
  `t[en][name]`, simple ASCII slugify (lowercase, non-alphanumeric → `-`, trim) into the
  slug field, for as long as it's still `readonly`.
- New hint line under the field (create only), reusing `.audit-meta`.
- New admin translation keys (all 5 `lang/admin/*.json`):
  `categories.form.slug_edit_manually`, `categories.form.slug_hint`.

## CSS — `www/assets/css/admin.css`

Extend the existing rule to cover both fields:

```css
.sku-input[readonly], .slug-input[readonly] { background: #f5f5f5; color: #666; }
```

## Testing

`CategoryModelTest` gains the same 6 cases `ProductModelTest` got for its SKU helpers,
adapted for slugs: `slugify()` (plain name, punctuation/symbols, empty → `"category"`)
and `uniqueSlug()` (no collision, one collision → `-2`, two collisions → `-3`).

## Out of scope

- The edit form's slug field (stays exactly as it is today — plain, always editable).
- `CategoryController::delete()`'s `hasProducts()` block-on-delete behavior — unrelated.
- Any change to the `categoryLabel()` notifier-label helper.
