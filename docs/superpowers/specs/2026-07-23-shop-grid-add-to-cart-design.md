# Shop Grid Quick Add-to-Cart — Design

## Purpose

The shop grid (`/​{lang}/shop`) currently shows a wishlist heart and an "Add to
compare" button per product card, but no way to add to cart without opening the
product page. Add a quick "Add to cart" action to each grid card, matching the
action that already exists on the product detail page.

The product detail page (`templates/public/shop/product.twig`) already has both
"Add to compare" and "Add to cart" (added in `b96007d`) — confirmed live on
production. No changes needed there; this design is scoped to the grid only.

## Constraint: products with subtypes

Grid cards only carry `min_subtype_price` (`ProductModel::allActive()`), not the
actual list of subtypes/variants. `CartController::add()` silently no-ops if
`sku` has subtypes and no valid `subtype_id` is posted (cart stays unchanged, user
is redirected to `/cart` with nothing added — no error shown). A one-click add
from the grid would hit that silent no-op for every variant product, which reads
as a broken button.

Resolution: branch per product in the grid template.
- **No subtypes** — real "Add to cart" form, POSTs `sku` + `qty=1` to the existing
  `/{lang}/cart/add` endpoint (unchanged backend).
- **Has subtypes** — a link, not a form: `<a href="/{lang}/shop/{sku}">`, labeled
  "View options" (new key), taking the user to the detail page's variant selector.

## Template changes

`templates/public/shop/index.twig`, inside the existing `product-card-wrap` loop,
insert immediately **before** the current `compare-toggle-form` block (see Layout
for why — the new button ranks above compare visually):

```twig
{% if product.min_subtype_price is not null %}
<a href="/{{ lang }}/shop/{{ product.sku }}" class="btn btn-primary btn-sm product-card-cta">
    {{ t('shop.view_options') }}
</a>
{% else %}
<form action="/{{ lang }}/cart/add" method="POST" class="add-to-cart-form add-to-cart-form--card">
    <input type="hidden" name="sku" value="{{ product.sku }}">
    <input type="hidden" name="qty" value="1">
    <button type="submit" class="btn btn-primary btn-sm product-card-cta">{{ t('shop.add_to_cart') }}</button>
</form>
{% endif %}
```

`product.min_subtype_price is not null` is the same condition the template
already uses to decide whether to show "from {price}" — reusing it keeps the
variant-detection logic in one place instead of adding a second check.

## Layout

New button sits **above** the existing "Add to compare" button so the primary
action (cart) outranks the secondary one (compare) visually:

```
┌─────────────────────┐
│      [image]         │
│  Product name         │
│  from 1.20 Kč          │
├─────────────────────┤
│  [Add to cart]         │  ← new, .btn.btn-primary.btn-sm (filled, accent)
│  [Add to compare]      │  ← existing, .compare-toggle (outlined)
└─────────────────────┘
```

Both buttons stay full-width/centered under the card, consistent with the
existing `.compare-toggle-form { display: block; text-align: center; }` pattern.

## CSS

`www/assets/css/style.css`, near the existing `.compare-toggle-form` rules:

```css
.product-card-cta { display: block; width: 100%; text-align: center; padding: .5rem 1rem; font-size: .8rem; }
.add-to-cart-form--card { margin-top: .5rem; }
```

No new colors/tokens — `.btn.btn-primary` already provides the accent fill/hover
via existing custom properties.

## Translations

One new key, added to all five `lang/{cs,en,ru,uk,sk}.json` files:
- `shop.view_options` — "View options" (en); translated equivalents for the other
  four via the existing admin auto-translate convention or manual translation.

`shop.add_to_cart` already exists (used by the detail page) and is reused as-is.

## Testing

- `tests/e2e/cart.spec.ts`: add a case that quick-adds a simple (no-subtype)
  product directly from `/cs/shop` via the new grid button, using the existing
  `CartPage`/`ShopPage` page objects (extend `ProductPage`-style pattern —
  likely a small addition to `ShopPage` for the grid's add-to-cart locator rather
  than a new page object, since it's one button on an existing page). Assert
  landing on `/cs/cart` with the product listed, same shape as the existing
  detail-page add-to-cart test.
- No PHPUnit coverage — `CartController::add()` is unchanged; only the template
  changed. Per `.claude/rules/unit-testing.md`, Twig/CSS changes are verified by
  rendering locally, not unit tests.
- Manual verification: `php -S localhost:8080 -t www`, check both a
  no-subtype product (e.g. `NAR-SADA-KLASIK`) and a subtype product (e.g. the
  "Latex balloons kitten" SKU) render the correct button/link and behave as
  designed.

## Out of scope

- Any change to `product.twig` (detail page) — already has both buttons.
- Quantity selection from the grid — always adds `qty=1`, matching how
  "quick add" works on comparable storefronts; full quantity control stays on
  the cart page.
- Auto-selecting a variant for subtype products — explicitly rejected in favor
  of linking to the detail page (see Constraint section).
