# Category/Product Legal Notice — Design

## Purpose

Move the "Notice" / "Legal information and warnings" content out of the generic
product-specifications rows (added in the prior product-specifications feature) into
its own dedicated field: a category-level default that a product can optionally
override, rendered as its own dedicated table on the public product page — separate
from the "Products specifications" table.

## Background

The original product-specifications feature used a convention where a spec row with a
blank value rendered as a section-header band, specifically so admins could reproduce
a "Notice" header followed by a "Legal information and warnings" row within the specs
table. In practice, this text is identical across most products in a category (e.g.
all latex balloons share the same safety warning) and re-typing it per product is
both tedious and error-prone. It also triggered a real bug: MyMemory (the
auto-translate API) rejects requests over ~500 characters, and — before the fix
shipped in the prior feature — a long "value" being translated together with "name" in
one batched call caused the **entire spec row** to silently vanish on every
non-source-language page.

## Constraints

- Category default + per-product override (not category-only, not per-product-only).
- The per-product override is a **dedicated field**, not a re-use of the generic
  specs-rows list — the blank-value-header convention is removed entirely from
  product specs.
- Old spec rows already containing "Notice"/"Legal information and warnings" pairs
  (e.g. product #42 and test fixtures) are left untouched in the DB; they simply stop
  rendering specially going forward. No backfill/migration of old data.
- The dedicated Notice table on the public page must visually match the reference
  screenshots exactly: a shaded "Notice" header band, then a "Legal information and
  warnings" row with the resolved text — just as its own separate table instead of
  appended to the specs table.

## Data model & architecture

- **`category_t`** gains a nullable `legal_notice` TEXT column (translatable per
  language, same pattern as the existing `description` column).
- **`product_t`** gains a nullable `legal_notice` TEXT column — empty/null means
  "inherit the category's notice"; a non-empty value overrides it for that
  product+language.
- **Resolution** (in `ProductModel::findBySku()`): effective notice = the product's
  own `legal_notice` for the requested language if non-empty, else the product's
  category's `legal_notice` for that language (via a join), else `null` (section
  doesn't render).
- **Specs table simplification**: the blank-value → header-row branch is removed from
  the public product template's specs-table Twig logic. Every remaining row in that
  table is a plain two-column render (the hex-color-swatch branch is unaffected).

## Admin UI & data flow

- **Category edit form** (`templates/admin/categories/form.twig`): a new "Legal
  information and warnings" textarea per language tab, right after Description —
  same per-language pattern as name/description, reusing the existing translate
  button.
- **`CategoryModel`**: `getTranslations()`/`setTranslations()` extended to include
  `legal_notice`, matching how `description` is already handled (`ON DUPLICATE KEY
  UPDATE`).
- **Product edit form** (`templates/admin/products/form.twig`): a new "Legal notice"
  textarea per language tab, right after Description, with hint text ("Leave blank to
  use the category's notice"). Entirely separate from the specs-rows UI.
- **`ProductModel`**: `getTranslations()`/`setTranslations()` extended to include
  `legal_notice`, same pattern as the existing meta fields.
- **Auto-translate on save**: `legal_notice` is added to `CategoryController::
  TRANSLATABLE_FIELDS` and `ProductController::TRANSLATABLE_FIELDS`, both of which
  feed into `Translator::autoFill()` — safe once `autoFill()` is fixed (below).

## Translator root-cause fix (expanded scope)

Investigation while designing this feature found the same "long text aborts sibling
fields" bug in two more places, both of which the new `legal_notice` field would
predictably trigger:

1. **`Translator::autoFill()`** itself still batches every missing field for a
   language into a single `Translator::translate()` call internally (the prior
   product-specifications fix only *worked around* this in `ProductController::
   buildSpecs()` by calling `autoFill()` twice — once per field — rather than fixing
   `autoFill()` itself). With `legal_notice` added to `TRANSLATABLE_FIELDS`, a long
   notice would once again silently blank out `name`/`description`/`meta_title`/
   `meta_desc` together for that language on product/category save.
2. **The manual "Translate" button** (shared JS in `templates/admin/products/
   form.twig` and `templates/admin/categories/form.twig`) calls `POST
   /admin/translate` — a route closure that calls `Translator::translate()` directly
   with *all* filled fields batched into one `texts` array. A single over-length
   field fails the whole request, breaking translation for every field in that
   language tab, not just the notice.

**Fix**: change `Translator::autoFill()` to call `translate()` once per field instead
of batching a language's missing fields into one call — each field's success/failure
becomes independent. Verified against all 4 existing `TranslatorTest` cases (traced
through by hand): none of their expected outcomes change, since the existing
mocked-failure tests use a transport that fails identically regardless of which text
is sent — the batching-vs-per-field distinction the tests exercise is unaffected. With
`autoFill()` fixed at the root, `ProductController::buildSpecs()` can be simplified
back to a single `autoFill()` call (removing the two-call workaround, since the root
cause is now fixed centrally) with no behavior change.

The "Translate" button JS is fixed the same way: instead of one `fetch()` sending all
filled fields in one `texts` array, it makes one `fetch()` per field, so a failure on
one field surfaces as an error against just that field's row while the rest still
translate successfully. This is scoped to the two forms actually gaining a long-text
field in this change (`products/form.twig`, `categories/form.twig`) — the same latent
weakness exists in `gallery/form.twig` and `services/form.twig`'s copies of this
script, but those aren't touched by this feature and are left as-is (pre-existing,
out of scope).

## Public rendering & translations

- **Placement**: a new `<table class="specs-table">` in `templates/public/shop/
  product.twig`, positioned directly after the existing "Products specifications"
  table, rendered only when the resolved notice text is non-empty. No extra `<h2>`
  heading above it — the "Notice" row inside the table already functions as the
  section's title, avoiding a redundant duplicate heading.
- Table contents: the same `Attribute name`/`Attribute value` header row, then a
  shaded full-width "Notice" row, then a "Legal information and warnings" row with
  the resolved text — matching the reference screenshots exactly.
- **New translation keys**, all 5 `lang/*.json` files: `shop.notice_title` ("Notice"),
  `shop.notice_legal_label` ("Legal information and warnings"). All 5
  `lang/admin/*.json` files: `products.form.legal_notice_label`,
  `products.form.legal_notice_hint`, `categories.form.legal_notice_label`.

## Migration

`V023__legal_notice.sql`:
```sql
ALTER TABLE category_t ADD COLUMN legal_notice TEXT NULL;
ALTER TABLE product_t  ADD COLUMN legal_notice TEXT NULL;
```
Additive only, no backfill — both default to `NULL` on existing rows, meaning "no
notice" until an admin fills one in. Nothing breaks for existing categories/products.

## Testing

- `tests/Unit/Services/TranslatorTest.php`: existing 4 `autoFill` tests must still
  pass unchanged after the per-field refactor (verified by hand during design; will
  be confirmed by actually running them during implementation). New test(s) covering
  the specific regression this fixes: one field failing to translate must not prevent
  a sibling field (in the same `autoFill()` call) from translating successfully.
- `tests/Unit/Models/CategoryModelTest.php` (or equivalent) gains
  `getTranslations`/`setTranslations` coverage for `legal_notice`, mirroring existing
  `description` coverage.
- `tests/Unit/Models/ProductModelTest.php` gains coverage for `legal_notice` in
  `getTranslations`/`setTranslations`, plus a `findBySku` case confirming the
  resolution order (product override wins over category default; category default
  used when product's is empty; `null` when neither is set).
- Admin controllers/templates stay untested per project convention; verify via
  `/start` + browser/curl, including the Translate-button JS behavior with a long
  notice text.

## Out of scope

- Backfilling/migrating old per-product "Notice" spec rows into the new fields.
- Fixing the same latent Translate-button weakness in `gallery/form.twig` /
  `services/form.twig` (pre-existing, unrelated to this change).
- Any UI for previewing which categories currently have no notice set.
