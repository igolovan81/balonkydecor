# Product Image Split — Design Spec

Date: 2026-07-19

## Problem

Some products (e.g. product id 10) are photographed in several colors but
live as a single product with multiple `product_images` rows and one shared
name/price. The admin wants each color to become its own dedicated product —
its own listing, its own SEO title, its own image — so customers pick a
color from the shop grid instead of from a photo gallery on one page.

There's no bulk-import path for this: the source product's data only exists
on production (it isn't in the local dev DB), so the admin will perform the
split manually, once per image, through the admin UI. This spec covers the
tooling that makes that practical, building on the existing
`ProductModel::clone()` (see `2026-07-10-product-clone-design.md`), which
today copies core fields and translations but not images or subtypes.

## Scope

- Admin-only feature: one new action per existing image on the product edit
  form ("Split into new product").
- Splitting an image creates a new product (via the existing clone
  mechanism) and **moves** that one image onto it — the image no longer
  appears on the source product.
- Also copies the source's `product_subtypes` (price variants) to the new
  product, if any exist, so pricing options aren't silently lost.
- If the image being split was the source's primary image, and other images
  remain on the source, one of them is promoted to primary.
- If splitting removes the source's *last* remaining image, the source
  product is automatically deactivated (`is_active = 0`) so an empty,
  image-less listing never appears in the shop.
- The admin lands directly on the new product's edit page after each split,
  to set its color-specific name/SKU (auto-translate/naming is out of scope
  — see below).
- The existing plain "Clone" button/route (`POST
  /admin/products/{id}/clone`, no image involved) is unchanged — this spec
  only adds a new, separate action.

## Design

### Model — extend `ProductModel::clone()`

New signature: `clone(int $id, int $userId, ?int $imageId = null): ?int`.

1. Load `$source = self::findById($id)`. Return `null` if it doesn't exist
   (unchanged).
2. **New:** if `$imageId !== null`, confirm it's present in
   `$source['images']` (i.e. actually belongs to this product). Return
   `null` if not — this guards against a tampered `image_id` reassigning
   an image that belongs to a different product. This check happens before
   any writes, so a rejected request never opens a transaction.
3. `$pdo->beginTransaction()`.
4. Unchanged: generate a unique SKU via `uniqueSku()`, insert the new
   product (`is_active = 0`, core fields copied, `created_by`/`updated_by`
   = `$userId`), copy translations via `getTranslations()` /
   `setTranslations()`.
5. **New — only when `$imageId` is given:**
   - `UPDATE product_images SET product_id = ?, is_primary = 1, sort_order = 0
     WHERE id = ?` with `(newId, imageId)`. Filenames are UUID-based and not
     product-scoped, so this is a pure DB move — no file rename/copy needed.
   - If the moved image's `is_primary` (captured from `$source['images']`
     before the update) was `1`, promote the source's next remaining image:
     `UPDATE product_images SET is_primary = 1 WHERE product_id = ? ORDER BY
     sort_order, id LIMIT 1` (same pattern already used in `deleteImage()`
     and `cleanupOrphans()`; a no-op if none remain).
   - If `$source['subtypes']` is non-empty, copy them to the new product:
     `self::setSubtypes($newId, $source['subtypes'])` — the shape returned
     by `getSubtypes()` (`price` + `t` map) already matches what
     `setSubtypes()` expects.
   - Count remaining images on the source
     (`SELECT COUNT(*) FROM product_images WHERE product_id = ?`); if `0`,
     `UPDATE products SET is_active = 0 WHERE id = ?` (source id).
6. `$pdo->commit()`.
7. Return `$newId`.

When `$imageId` is `null` (the existing plain-clone call site), step 5 is
skipped entirely — behavior is byte-for-byte unchanged from today.

### Controller — new `ProductController::cloneWithImage()`

- Route: `POST /admin/products/{id:[0-9]+}/clone-image/{image_id:[0-9]+}`,
  registered inside the existing `$app->group('/admin', ...)` block, next to
  the plain `/clone` and `/image/{image_id}/delete` routes.
- Behavior (mirrors the existing `clone()` controller method):
  1. `$newId = ProductModel::clone((int) $args['id'], $userId, (int)
     $args['image_id'])`. If `null`, return `$response->withStatus(404)`.
  2. Fetch the new product for its SKU, call `Notifier::notify('product',
     $newId, $sku, 'created', $userId, $actorEmail)` — reuses the existing
     `product_created` message, same as plain clone.
  3. `$this->flash('success', 'products.flash.split')`.
  4. Redirect to `/admin/products/{newId}/edit`.

### UI & translations

- `templates/admin/products/form.twig`: next to each existing image's
  delete button, add a "Split into new product" button using the same
  `fetch()`-button convention as `.delete-image-btn` (a nested `<form>`
  isn't valid inside the surrounding edit form):
  ```html
  <button type="button" class="btn-link split-image-btn" style="font-size:0.8rem"
          data-url="/admin/products/{{ product.id }}/clone-image/{{ img.id }}">
    {{ t('products.form.split_image') }}
  </button>
  ```
  JS handler: `confirm()` first (this mutates data and moves a real asset,
  unlike delete-image's existing unprompted fetch), then `fetch(...,
  {method:'POST'})`. Unlike the delete-image handler (which just
  `location.reload()`s), this one navigates to wherever the request
  redirected: `location.href = res.url` (fetch follows redirects, so
  `res.url` is already the new product's edit page).
- New translation keys, added to all 5 admin files
  (`lang/admin/{cs,en,ru,uk,sk}.json`):
  - `products.form.split_image` — button label.
  - `products.form.confirm_split` — confirm-dialog text.
  - `products.flash.split` — flash message shown after redirect.

### Testing

`tests/Unit/Models/ProductModelTest.php`, real Docker MySQL, TDD:

1. `test_clone_without_image_id_behaves_as_before` — regression guard;
   clone with no `$imageId` still produces an image-less, subtype-less
   clone exactly as the existing tests already assert.
2. `test_clone_with_image_id_moves_image_and_makes_it_primary` — fixture
   product with 2 images; clone with one `image_id`; assert the new
   product has exactly that image with `is_primary = 1`, and the source
   product no longer has it.
3. `test_clone_with_image_id_promotes_new_primary_on_source_when_primary_moved`
   — fixture with 2 images, the first one primary; split the primary one;
   assert the source's remaining image becomes primary.
4. `test_clone_with_image_id_deactivates_source_when_last_image_removed` —
   fixture with exactly 1 image and `is_active = 1`; split it; assert the
   source's `is_active` becomes `0`.
5. `test_clone_with_image_id_keeps_source_active_when_images_remain` —
   fixture with 2 images; split one; assert the source's `is_active` is
   unchanged.
6. `test_clone_with_image_id_copies_subtypes` — fixture with subtypes set
   via `setSubtypes()`; split an image; assert the new product's
   `getSubtypes()` matches the source's (price + all language names), and
   the source's subtypes are untouched.
7. `test_clone_with_image_id_not_belonging_to_product_returns_null` — call
   `clone()` with an `image_id` that belongs to a different fixture
   product; assert `null` and that no new product row was created.

No controller test, per `.claude/rules/unit-testing.md` — the route and UI
are verified manually via `php -S localhost:8080 -t www`.

## Out of scope

- Auto-deriving a color name for the new product (manual naming, per
  product-owner decision — the admin edits the name/SKU on the landing
  page after each split).
- Batch "split all images at once" (rejected — incompatible with landing on
  each new product's edit page to name it individually).
- Changing `deleteImage()`'s behavior — the existing per-image delete
  button on the edit form does **not** gain the auto-deactivate-on-zero-
  images behavior; that's scoped to this new split action only.
- Actually performing the split on product 10 — that's a manual, one-time
  admin action taken after this feature ships, not part of this spec.
