# Recently Viewed Products — Design

## Purpose

Let visitors see products they've looked at before, without an account or login,
across visits (not just the current browser session) — so someone who views a
product today and comes back next week still sees it. Surface this on the product
detail page, the shop listing sidebar, and the homepage.

## Constraints

- No customer account/login system exists on the public site. `Compare` and
  `Wishlist` are both session-only (`$_SESSION`), which resets when the browser
  session ends — too short-lived for "recently viewed," which is expected to persist
  across visits. This feature needs a longer-lived, cookie-backed store instead of
  `$_SESSION`.
- Site is fully server-rendered, no build step, no SPA/AJAX interaction pattern for
  this kind of state — read/write happens in PHP via a plain cookie, no JS or JSON
  endpoint.
- Must degrade the same way `Compare`/`Wishlist` do: a SKU that no longer resolves
  (deleted/deactivated product) is silently dropped when hydrating, not an error.

## Architecture & data flow

- **`src/Services/RecentlyViewed.php`** (new, static class, same shape as
  `Compare.php`/`Wishlist.php` but cookie-backed instead of session-backed):
  - Cookie name `recently_viewed`, value is a JSON array of SKUs, most-recently-viewed
    first, deduped, capped at 8 entries, 90-day expiry, `path=/`, `httponly`,
    `samesite=Lax`.
  - `track(string $sku): void` — reads the current list, removes `$sku` if already
    present (so re-viewing moves it back to the front instead of duplicating),
    prepends it, truncates to 8, writes the cookie.
  - `skus(?string $exclude = null): array` — reads and JSON-decodes the cookie,
    optionally filtering out one SKU (the product currently being viewed).
  - `items(string $lang, ?string $exclude = null): array` — hydrates each SKU via
    `ProductModel::findBySku()`; silently drops SKUs that no longer resolve.
  - Every write updates `$_COOKIE` in-process in addition to calling `setcookie()` —
    `setcookie()` alone only takes effect on the *next* request, and `track()` +
    `items()` both run within the same request (`ShopController::product()`), so the
    in-memory mirror keeps that single request consistent, the same role
    `$_SESSION` plays for `Compare`/`Wishlist`.
- **Wiring** (no new controller/routes — this is read/track only, no toggle UI):
  - `ShopController::product()` — calls `RecentlyViewed::track($product['sku'])`
    after the product is found, and passes
    `recently_viewed: RecentlyViewed::items($lang, $product['sku'])` (current product
    excluded from its own "recently viewed" list).
  - `ShopController::index()` — passes
    `recently_viewed: RecentlyViewed::items($lang)` for the sidebar box.
  - `HomeController::index()` — passes
    `recently_viewed: RecentlyViewed::items($lang)`.

## UI components

- **`templates/public/partials/recently-viewed-row.twig`** (new partial, self-guarding
  on `{% if recently_viewed %}` so call sites just `{% include %}` unconditionally):
  horizontal row of cards — image, name, price, linking to `/{{ lang }}/shop/{{
  product.sku }}` — reusing the `.product-img` image/placeholder markup from
  `shop/index.twig`. No wishlist/compare toggles on these cards (view-only,
  re-navigates to the product page). Included in:
  - `shop/product.twig`, below the specs/legal-notice sections.
  - `home.twig`, below the hero section.
- **`templates/public/partials/recently-viewed-sidebar.twig`** (new partial, same
  self-guarding rule): compact stacked list — small thumbnail + name, no price —
  matching the existing `.shop-sidebar`/`.cat-filter` box style. Included in
  `shop/index.twig`'s `<aside class="shop-sidebar">`.
  - The aside's existing wrapper condition (`{% if categories %}`) becomes
    `{% if categories or recently_viewed %}` so the sidebar still renders when there's
    viewing history but the category list would otherwise be empty; the category
    links themselves stay behind their own `{% if categories %}` inside.
- **CSS** (`www/assets/css/style.css`): `.recently-viewed-section` (heading + row
  wrapper), `.recently-viewed-row` (flex, horizontal scroll on narrow widths —
  `overflow-x: auto`, matching the two-breakpoint convention), `.recently-viewed-card`
  (reuses `.product-img` sizing); `.recently-viewed-sidebar-item` (thumb + name row)
  for the sidebar box. Existing design tokens only, no new colors.

## Error handling

- `track()` on an unresolvable/garbage cookie value (corrupt JSON, non-array) treats
  it as an empty list rather than erroring — `json_decode` result is checked with
  `is_array()` before use.
- `items()` silently drops SKUs whose `ProductModel::findBySku()` lookup returns null
  (deleted/deactivated product), self-healing the list over time as `track()` keeps
  writing only the SKUs still being viewed.
- No open-redirect surface — this feature has no POST/redirect action, only reads and
  one internal write triggered by a normal GET page view.

## Translations

One new key needed in all five `lang/{cs,en,ru,uk,sk}.json` files:
- `shop.recently_viewed_title`

## Testing

- `tests/Unit/Services/RecentlyViewedTest.php`, same shape as `CompareTest.php`/
  `WishlistTest.php` (real `$_COOKIE` manipulation, no mocks): `setUp()` clears
  `$_COOKIE['recently_viewed']` before each test.
  - `track()` adds a SKU; re-tracking the same SKU moves it to the front instead of
    duplicating; list is capped at 8 (oldest dropped); `skus()` excludes a given SKU
    when asked.
  - `items($lang)` hydrates a real product fixture (`INSERT IGNORE` pattern per
    `.claude/rules/unit-testing.md`) and skips a SKU that no longer resolves.
- TDD throughout: write these tests first, watch them fail, then implement
  `RecentlyViewed.php`.
- Controllers/templates stay untested per project convention; verify via `/start` +
  browser — product page (own SKU excluded, list grows/dedupes/reorders across
  repeated visits), shop sidebar, and homepage.

## Out of scope

- Any toggle/remove UI or "clear history" action.
- A view-count or "most viewed" ranking — this is purely last-viewed-first order.
- Cross-device sync (cookie is per-browser, as expected).
- A nav count badge (consistent with `Wishlist`, which also has none).
