# Product Clone — Design Spec

Date: 2026-07-10

## Problem

Admins frequently need near-duplicate products that differ only by a small
detail (e.g. color). Today they must re-enter the SKU, price, category,
stock settings, and all 5 language translations from scratch. A "Clone"
action in the admin product list should let them start from an existing
product instead.

## Scope

- Admin-only feature: one new action on the products list.
- Clones product core fields, stock settings, and all translations.
- Does not clone images — the cloned product starts with no images so the
  admin uploads fresh photos for the new variant.
- Cloned product is created inactive so it never briefly appears duplicated
  on the live storefront before the admin finishes editing it.

## Design

### Model — `ProductModel::clone(int $id, int $userId): ?int`

1. Load the source product via `findById($id)`. Return `null` if it doesn't
   exist.
2. Generate a unique SKU from the source SKU using the existing
   `uniqueSku()` helper (same suffixing behavior as the name-derived SKU
   path in `createSubmit`, e.g. `balloon-arch` → `balloon-arch-2`).
3. Insert the new product via `create()`:
   - `category_id`, `price`, `stock_type`, `stock_qty` copied verbatim.
   - `is_active` forced to `0` regardless of the source's value.
   - `created_by` / `updated_by` set to the acting admin (`$userId`).
4. Copy translations verbatim: `getTranslations($id)` →
   `setTranslations($newId, $translations)` for all languages present. No
   "(Copy)" suffix — admin edits the name/color after cloning.
5. Do not copy `product_images` rows or files.
6. Return the new product's ID.

### Controller — `ProductController::clone()`

- Route: `POST /admin/products/{id:[0-9]+}/clone`, registered inside the
  existing `$app->group('/admin', ...)` block (protected by
  `AuthMiddleware`), placed after the `/edit` routes and before `/delete`.
- Behavior:
  1. `$newId = ProductModel::clone($id, $userId)`. If `null`, return
     `$response->withStatus(404)`.
  2. Fetch the new product (`ProductModel::findById($newId)`) to get its
     SKU for the notification label.
  3. `Notifier::notify('product', $newId, $newSku, 'created', $userId, $actorEmail)`
     — reuses the existing `product_created` notification message; a clone
     is a newly created product, so no new notification key/translations
     are needed.
  4. `$this->flash('success', 'products.flash.cloned')`.
  5. Redirect to `/admin/products/{newId}/edit` so the admin lands directly
     on the new product to adjust color/name/price and upload images.

### UI & translations

- `templates/admin/products/index.twig`: add a "Clone" action in the
  actions column, alongside Edit/Delete. Implemented as an inline
  `<form method="POST" action="/admin/products/{{ p.id }}/clone">` with a
  `btn-link` button — same shape as the existing Delete form, but with no
  `confirm()` prompt since cloning isn't destructive.
- New translation keys added to all 5 admin files
  (`lang/admin/{cs,en,ru,uk,sk}.json`):
  - `products.clone` — action label.
  - `products.flash.cloned` — flash message shown after redirect.

### Testing

- `tests/Unit/Models/ProductModelTest.php`:
  - `test_clone_copies_translations_and_creates_inactive_product` — clone a
    fixture product that has translations set; assert the new row exists,
    `is_active === 0`, translations match the source, SKU differs from the
    source, and `images` is empty.
  - `test_clone_generates_unique_sku_on_collision` — clone the same source
    twice; assert the second clone's SKU carries the `-2`/`-3` style
    suffix, confirming `uniqueSku()` is wired into `clone()`.
  - `test_clone_returns_null_for_missing_product`.
- No controller test — per `.claude/rules/unit-testing.md`, controllers are
  untested; the route and UI are verified manually via
  `php -S localhost:8080 -t www`.

## Out of scope

- Cloning images (explicitly decided against — clone starts image-free).
- Bulk/multi-select cloning.
- A distinct "cloned" notification message (reuses "created").
