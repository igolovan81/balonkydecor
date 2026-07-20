# Wishlist Add-to-Cart for Variant Products Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let shoppers add a variant (subtype) product straight to the cart from the wishlist page, instead of being routed to the product detail page.

**Architecture:** Template-only change. `templates/public/wishlist.twig` currently renders a "View product" link for any wishlist item with `product.subtypes` set; replace it with an inline `<form>` posting to the existing `/{{ lang }}/cart/add` route, carrying a `subtype_id` select — the same fields `templates/public/shop/product.twig` already sends and `CartController::add` already handles. One new CSS modifier class stacks the select above the button.

**Tech Stack:** Twig 3, plain CSS (no build step).

## Global Constraints

- No hardcoded user-facing strings — reuse existing keys `shop.subtype` and `shop.add_to_cart` (already present in all 5 `lang/*.json` files); do not add new keys.
- All public links stay `{{ lang }}`-prefixed; this change only touches a POST form action, not a link.
- CSS: flat kebab-case, `--modifier` suffix for variants, no `!important`, no IDs (per `.claude/rules/css-styling.md`).
- No PHP/model/controller/migration changes — `CartController::add` (`src/Controllers/CartController.php:19-55`) already resolves `subtype_id` server-side.
- Per `.claude/rules/unit-testing.md`, Twig templates are verified by rendering locally, not by PHPUnit — no test file for this task.

---

### Task 1: Add-to-cart form for variant wishlist items

**Files:**
- Modify: `templates/public/wishlist.twig:42-50`
- Modify: `www/assets/css/style.css` (near line 135, next to the existing `.wishlist-action` rule)

**Interfaces:**
- Consumes: `product.subtypes` (array of `{id, name, price}`, already loaded by `Wishlist::items()` → `ProductModel::findBySku()`); existing route `POST /{lang}/cart/add` handled by `CartController::add()`, which reads `sku`, `qty`, and optional `subtype_id` from the parsed body.
- Produces: no new interfaces — this is a leaf template change.

- [ ] **Step 1: Replace the "View product" branch with an add-to-cart form**

In `templates/public/wishlist.twig`, replace lines 42-50:

```twig
            {% if product.subtypes %}
            <a href="/{{ lang }}/shop/{{ product.sku }}" class="btn btn-primary wishlist-action">{{ t('wishlist.view_product') }}</a>
            {% else %}
            <form action="/{{ lang }}/cart/add" method="POST" class="wishlist-action">
                <input type="hidden" name="sku" value="{{ product.sku }}">
                <input type="hidden" name="qty" value="1">
                <button type="submit" class="btn btn-primary">{{ t('shop.add_to_cart') }}</button>
            </form>
            {% endif %}
```

with:

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
            <form action="/{{ lang }}/cart/add" method="POST" class="wishlist-action">
                <input type="hidden" name="sku" value="{{ product.sku }}">
                <input type="hidden" name="qty" value="1">
                <button type="submit" class="btn btn-primary">{{ t('shop.add_to_cart') }}</button>
            </form>
            {% endif %}
```

- [ ] **Step 2: Add the CSS modifier**

In `www/assets/css/style.css`, directly after the existing line:

```css
.wishlist-action { display: block; margin-top: .5rem; text-align: center; }
```

add:

```css
.wishlist-action--subtypes { display: flex; flex-direction: column; gap: .5rem; }
```

- [ ] **Step 3: Start the local server**

Run: `docker compose up -d && php -S localhost:8080 -t www`

(If MySQL is already running from a prior session, skip `docker compose up -d`.)

- [ ] **Step 4: Manually verify variant products**

1. In a browser, go to `http://localhost:8080/cs/shop`, open any product that has subtypes (check `product_subtypes` table, or look for a product page showing a "Varianta" `<select>`), and click the wishlist heart to add it.
2. Go to `http://localhost:8080/cs/wishlist`.
3. Confirm the row now shows a subtype `<select>` + "Přidat do košíku" button (no more "Zobrazit produkt" link).
4. Pick a subtype from the dropdown, click the button.
5. Confirm you land on `/cs/cart` and the line item shows the product name with the chosen subtype's name and price (matching `CartController::add`'s `$product['name'] . ' — ' . $subtype['name']` format).

Expected: cart row matches the selected subtype, not the first/default one — pick a non-default subtype in step 4 to prove the select value is actually being read.

- [ ] **Step 5: Manually verify simple products are unaffected**

1. Add a product with no subtypes to the wishlist.
2. On `/cs/wishlist`, confirm that row still shows the plain "Přidat do košíku" button with no select, and clicking it adds the product to the cart as before.

- [ ] **Step 6: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests pass (no test file was added or should need changing for this task — this just confirms the template edit didn't break anything else).

- [ ] **Step 7: Commit**

```bash
git add templates/public/wishlist.twig www/assets/css/style.css
git commit -m "$(cat <<'EOF'
feat: add-to-cart with subtype select on wishlist page

EOF
)"
```
