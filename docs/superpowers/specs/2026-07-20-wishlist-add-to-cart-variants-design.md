# Wishlist: add-to-cart for variant products

## Problem

The wishlist page (`templates/public/wishlist.twig`) already lets a shopper add a
simple product straight to the cart. Products that have subtypes (variants) are
excluded from this — they only get a "View product" link, forcing a detour to the
product detail page just to pick a variant and add it to the cart.

## Change

Template-only change, no backend/model/controller work needed:
`CartController::add` already accepts an optional `subtype_id` and resolves the
matching subtype server-side (see `src/Controllers/CartController.php`).

In `templates/public/wishlist.twig`, replace the "View product" link shown when
`product.subtypes` is set with an add-to-cart form matching the pattern already used
on `templates/public/shop/product.twig`:

```twig
{% if product.subtypes %}
<form action="/{{ lang }}/cart/add" method="POST" class="wishlist-action wishlist-action--subtypes">
    <input type="hidden" name="sku" value="{{ product.sku }}">
    <input type="hidden" name="qty" value="1">
    <select name="subtype_id" class="subtype-select" aria-label="{{ t('shop.subtype') }}">
        {% for subtype in product.subtypes %}
        <option value="{{ subtype.id }}">{{ subtype.name }} — {{ subtype.price|number_format(2, '.', ' ') }} Kč</option>
        {% endfor %}
    </select>
    <button type="submit" class="btn btn-primary">{{ t('shop.add_to_cart') }}</button>
</form>
{% else %}
... (unchanged simple-product form)
{% endif %}
```

Quantity stays fixed at 1 (matching the existing simple-product row on this page,
which also hardcodes qty=1 — no qty input is added here).

## CSS

Add one modifier rule next to the existing `.wishlist-action` rule in
`www/assets/css/style.css`:

```css
.wishlist-action--subtypes { display: flex; flex-direction: column; gap: .5rem; }
```

Stacks the select above the button inside the product card. `.subtype-select` styling
is already defined globally and needs no changes.

## Translations

None needed — `shop.subtype` and `shop.add_to_cart` already exist in all 5 language
files.

## Testing

No unit test: this repo's convention (`.claude/rules/unit-testing.md`) is that Twig
templates aren't unit-tested — verified by rendering the page locally instead.

Manual verification via `/start`:
1. Add a variant product to the wishlist.
2. On `/{lang}/wishlist`, confirm the row shows a subtype select + "Add to cart"
   button instead of "View product".
3. Pick a subtype, submit, confirm the cart shows the correct subtype name/price.
4. Confirm simple (non-variant) wishlist rows are unaffected.
