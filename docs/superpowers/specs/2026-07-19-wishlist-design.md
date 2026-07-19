# Wishlist — Design

## Purpose

Let visitors bookmark products they're interested in without needing an account,
mirroring how `Cart` already works session-only. Wishlist items are viewable, can be
removed, and (for products without subtypes) can be added to the cart directly.

## Constraints

- No customer account/login system exists on the public site — only a session-backed
  `Cart` and separate admin auth. Wishlist follows the same session-only model, no new
  auth or DB table.
- Products can have subtypes (variants, same SKU, different price). The wishlist saves
  by SKU only (bookmark, not a specific variant) — subtype is chosen at add-to-cart
  time on the product page.
- Site has no build step and no AJAX/JS interaction pattern for cart-like actions
  today; wishlist follows the same POST-redirect-GET convention as `Cart::add`.

## Architecture & data flow

- **`src/Services/Wishlist.php`** (new, static class, mirrors `Cart.php`): boots
  `$_SESSION['wishlist']` as a flat array of unique SKUs.
  - `toggle(string $sku): bool` — adds if absent, removes if present; returns the new
    state (`true` = now saved).
  - `has(string $sku): bool`
  - `skus(): array`
  - `items(string $lang): array` — hydrates each SKU via `ProductModel::findBySku()`;
    silently drops SKUs that no longer resolve (deleted/deactivated product),
    self-healing the session list.
  - `count(): int`
- **`src/Controllers/WishlistController.php`** (new, extends `BaseController`):
  - `index()` → renders `public/wishlist.twig` with `Wishlist::items($lang)`.
  - `toggle()` → POST handler; reads `sku` + hidden `return` field, calls
    `Wishlist::toggle()`, redirects (302) back to `return` if it's a safe same-site
    path, else to `/{lang}/wishlist`.
- **Routes** (`src/routes.php`, under `/{lang}/...`, alongside existing cart routes):
  - `GET /{lang}/wishlist`
  - `POST /{lang}/wishlist/toggle`
- **Templates**: `ShopController::index()` and `ShopController::product()` pass
  `wishlist_skus: Wishlist::skus()` so cards/detail page can render the heart
  filled/outline. New `templates/public/wishlist.twig`.

## UI components

- **Heart toggle**: `<form method="POST" action="/{{ lang }}/wishlist/toggle">` with
  hidden `sku` and `return` (= `current_path`) fields; submit button styled as a heart
  icon (`.wishlist-toggle`, `.active` when `sku in wishlist_skus`). Real `<button
  type="submit">` with `aria-label` (`t('shop.wishlist_add')` /
  `t('shop.wishlist_remove')`) — no JS, keyboard accessible.
  - Placed top-right of `.product-card` in the shop grid, and near the price on the
    product detail page.
- **Nav link**: plain text link next to the existing cart link in `base.twig` —
  `<a href="/{{ lang }}/wishlist">{{ t('nav.wishlist') }}</a>`. No count badge
  (consistent with the cart link, which has none today).
- **`/wishlist` page** (`templates/public/wishlist.twig`): grid of saved products
  (image, name, price, remove-heart toggle). Products without subtypes get an inline
  add-to-cart form (SKU + qty=1, same shape as the product page form). Products with
  subtypes get a "view product" link instead (no subtype stored to add directly).
  Empty state uses new key `wishlist.empty`, styled like `shop.no_products`.
- `noindex,nofollow` meta on the wishlist page, matching cart/checkout.

## Error handling

- `toggle()` with empty/missing `sku` → no-op, redirect back.
- `toggle()` tolerates a nonexistent SKU (no DB lookup on toggle, same tolerance as
  `Cart::add`) — it just won't render anywhere since `items()` filters through
  `ProductModel::findBySku()`.
- **Open-redirect guard**: `WishlistController::toggle()` only honors the `return`
  field if it matches `^/[a-z]{2}/` (a same-site lang-prefixed path); otherwise falls
  back to `/{lang}/wishlist`.
- `items($lang)` silently drops SKUs whose product lookup returns null.

## Translations

New keys needed in all five `lang/{cs,en,ru,uk,sk}.json` files:
- `nav.wishlist`
- `wishlist.title`
- `wishlist.empty`
- `shop.wishlist_add` (aria-label, "add to wishlist")
- `shop.wishlist_remove` (aria-label, "remove from wishlist")

## Testing

- `tests/Unit/Services/WishlistTest.php`, same shape as `CartTest.php` (real
  `$_SESSION`, no mocks): `toggle` adds/removes and returns correct new state, `has`,
  `skus`, `count`, empty-session edge cases.
- `items($lang)` needs a real product fixture (`INSERT IGNORE` pattern per
  `.claude/rules/unit-testing.md`) to verify hydration and filtering of a SKU that no
  longer resolves.
- TDD throughout: write these tests first, watch them fail, then implement
  `Wishlist.php`.
- `WishlistController` stays untested per project convention (controllers are
  currently untested); verify via `/start` + browser instead.

## Out of scope

- Persistent (cross-session/cookie) wishlist.
- Subtype-specific wishlist entries.
- Wishlist item count badge in nav.
- AJAX/no-reload toggle interaction.
