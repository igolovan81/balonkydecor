# Compare Products — Design

## Purpose

Let visitors put products side by side (name, price, specs) without an account,
mirroring the existing [[2026-07-19-wishlist-design]] session-only pattern. Capped at
4 products, matching the reference UX the request was based on.

## Constraints

- No customer account/login system exists on the public site — Compare follows the
  same session-only model as `Wishlist`/`Cart`, no new DB table.
- Products have no fixed spec columns (color/size/material); specs are generic
  key/value rows via `product_specs`/`product_spec_t` (`attribute_name`,
  `attribute_value`, per language). The comparison table must merge these dynamically
  across the compared products rather than assuming fixed columns.
- The public site currently has no flash-message mechanism (admin-only, via
  `AdminBaseController`). Surfacing "compare list is full" requires adding a minimal
  equivalent to the public `BaseController`.
- Site has no build step and no AJAX interaction pattern for cart/wishlist-like
  actions; Compare follows the same POST-redirect-GET convention.

## Architecture & data flow

- **`src/Services/Compare.php`** (new, static class, mirrors `Wishlist.php`): boots
  `$_SESSION['compare']` as a flat array of unique SKUs, capped at **4**.
  - `toggle(string $sku): array` — returns `['added' => bool, 'full' => bool]`.
    Removes if present (`added=false, full=false`). If absent and the list already has
    4 entries, does **not** modify the list and returns `added=false, full=true`.
    Otherwise appends and returns `added=true, full=false`.
  - `has(string $sku): bool`
  - `skus(): array`
  - `count(): int`
  - `items(string $lang): array` — hydrates each SKU via `ProductModel::findBySku()`;
    silently drops SKUs that no longer resolve (same self-healing behavior as
    `Wishlist::items()`).
  - `clear(): void` — empties `$_SESSION['compare']`.
- **`src/Controllers/CompareController.php`** (new, extends `BaseController`):
  - `index()` → renders `public/compare.twig` with `items: Compare::items($lang)` and
    `attributes`: the union of distinct `attribute_name` values across all compared
    products' `specs`, in first-seen order (built in the controller by iterating
    `items` in order and collecting attribute names not already seen).
  - `toggle()` → POST handler; reads `sku` + hidden `return` field (same open-redirect
    guard as Wishlist: only honors `return` matching `^/[a-z]{2}/`). Calls
    `Compare::toggle()`; if the result is `full=true`, sets a flash
    (`error`, `compare.full`) before redirecting.
  - `clear()` → POST handler; calls `Compare::clear()`, redirects to
    `/{lang}/compare`.
- **Routes** (`src/routes.php`, under `/{lang}/...`, alongside existing wishlist
  routes):
  - `GET  /{lang}/compare`
  - `POST /{lang}/compare/toggle`
  - `POST /{lang}/compare/clear`
- **`ShopController::index()`/`product()`** additionally pass `compare_skus:
  Compare::skus()` / `in_compare: Compare::has($product['sku'])`, same shape as the
  existing wishlist wiring.
- **`BaseController::render()`** additionally injects `compare_count:
  Compare::count()` into every public template's common data (nav needs it site-wide,
  not just on shop/compare pages).

## Public flash messages (new, small)

`BaseController` gains `flash()`/`getFlash()` methods identical in shape to
`AdminBaseController`'s (`$_SESSION['flash'] = ['type' => ..., 'message' => ...,
'params' => ...]`, read-and-clear on next render). `render()` reads it into a `flash`
template variable. `templates/layout/base.twig` renders it once, immediately after
`</header>` and before `<main>`, using the same markup as
`templates/layout/admin-base.twig`:
```twig
{% if flash %}
    <div class="flash-{{ flash.type }}">{{ t(flash.message, flash.params ?? []) }}</div>
{% endif %}
```
`www/assets/css/style.css` gets `.flash-success` / `.flash-error` rules matching
`admin.css`'s (literal colors — one-off status colors per `.claude/rules/css-styling.md`,
not tokens). This mechanism is generic (any future public flow can call `$this->flash()`)
but is being introduced now specifically to carry the "compare list full" message.

## UI components

- **Compare toggle**: `<form method="POST" action="/{{ lang }}/compare/toggle">` with
  hidden `sku` + `return` fields, alongside the existing wishlist toggle form on both
  the product card (`shop/index.twig`) and product detail page (`shop/product.twig`).
  Minimal markup — a text `<button type="submit" class="compare-toggle">` whose label
  swaps between `t('shop.compare_add')` / `t('shop.compare_remove')` and toggles an
  `active` class when `sku in compare_skus` — no new SVG/icon asset.
- **Nav link**: `<a href="/{{ lang }}/compare" class="compare-link">{{ t('nav.compare')
  }}{% if compare_count %} ({{ compare_count }}){% endif %}</a>` next to the existing
  wishlist/cart links in `base.twig`.
- **`/compare` page** (`templates/public/compare.twig`, table-shaped, mirrors
  `wishlist.twig` structurally): header row (product image + a `✕` remove-from-compare
  toggle form per column, matching the reference screenshot), name row, price row, then
  one row per entry in `attributes` (blank cell where a given product has no spec with
  that `attribute_name`). A "Clear list" button posts to `/compare/clear`. Empty state
  (`compare.empty`) styled like `wishlist.empty` when `items` is empty.
  `noindex,nofollow` meta, matching wishlist/cart.

## Error handling

- `toggle()` with empty/missing `sku` → no-op, redirect back.
- `toggle()` tolerates a nonexistent SKU (no DB lookup on toggle) — same tolerance as
  `Wishlist::toggle()`; it just won't render anywhere since `items()` filters through
  `ProductModel::findBySku()`.
- Toggling a 5th distinct SKU when the list already has 4 does not evict anything or
  add the new one — `Compare::toggle()` returns `full=true` and the controller sets a
  flash message; the user must remove one first.
- Same open-redirect guard as `WishlistController::toggle()`.
- `items($lang)` silently drops SKUs whose product lookup returns null (deleted/
  deactivated product) — this also shrinks the effective list below 4, freeing a slot.

## Translations

New keys needed in all five `lang/{cs,en,ru,uk,sk}.json` files:
- `nav.compare`
- `compare.title`
- `compare.empty`
- `compare.clear_list`
- `compare.full` (flash message shown when toggle is rejected for being at the cap)
- `compare.view_product`
- `shop.compare_add` (aria-label/button text, "add to compare")
- `shop.compare_remove` (aria-label/button text, "remove from compare")

## Testing

- `tests/Unit/Services/CompareTest.php`, same shape as `WishlistTest.php` (real
  `$_SESSION`, no mocks): `toggle` adds/removes and returns the correct
  `added`/`full` pair, `has`, `skus`, `count`, empty-session edge cases, plus the
  4-item cap (toggling a 5th distinct SKU returns `full=true` without growing the
  list; removing one then re-toggling succeeds).
- `items($lang)` needs a real product fixture (`INSERT IGNORE` pattern per
  `.claude/rules/unit-testing.md`) to verify hydration and filtering of a SKU that no
  longer resolves — mirrors `WishlistTest::test_items_skips_sku_that_no_longer_resolves`.
- TDD throughout: write these tests first, watch them fail, then implement
  `Compare.php`.
- `CompareController` stays untested per project convention (controllers are
  currently untested); verify via `/start` + browser instead, including the
  attribute-merging logic on `/compare` with products that have different spec sets.

## Out of scope

- Persistent (cross-session/cookie) compare list.
- AJAX/no-reload toggle interaction.
- A compare-count badge styled beyond the plain `(N)` text suffix in the nav link.
- Legal-notice row in the comparison table (available in `product_t.legal_notice` if
  wanted later, but not part of this pass).
