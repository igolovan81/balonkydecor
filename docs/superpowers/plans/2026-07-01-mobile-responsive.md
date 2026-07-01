# Public Site Mobile/Tablet Responsive Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the public BalonkyDecor site (currently zero media queries) usable and attractive on phones (≤480px) and tablets (≤768px) without changing anything above 768px.

**Architecture:** Additive-only CSS — append `@media (max-width: 768px)` and `@media (max-width: 480px)` blocks to the existing `www/assets/css/style.css`, no rewrite of desktop rules. One new vanilla-JS file (`www/assets/js/nav.js`, the site's first JS) toggles the mobile nav. Two templates get minor markup additions (`data-label` attributes for the responsive cart-table pattern, a nav-toggle button + reordered header children for the hamburger).

**Tech Stack:** Twig 3 templates, plain CSS (custom properties already defined in `:root`), vanilla JS (no build step, no framework, no libraries — matches existing project constraints).

## Global Constraints

- No build step — CSS/JS are static files served directly from `www/assets/`.
- Desktop (`>768px`) must render pixel-identical to before this work (spec requirement).
- Two breakpoints only: `≤768px` (tablet) and `≤480px` (phone). Touch targets stay ≥44px tall below 768px.
- This is a CSS/markup-only project — there is no PHPUnit test suite for frontend layout. Each task is verified with real Chrome-headless screenshots of the running local site, not automated assertions. "Passing" a task means the screenshot shows the expected layout with no horizontal overflow and no desktop regression.
- Local environment: `docker compose up -d` (MySQL) + `php -S localhost:8080 -t www` (app). Every task's verification step starts by making sure both are up (idempotent — skip if already running).
- Admin panel (`/admin/*`) is explicitly out of scope.

---

### Task 1: Hamburger nav for header

**Files:**
- Modify: `templates/layout/base.twig` (header markup + scripts block)
- Create: `www/assets/js/nav.js`
- Modify: `www/assets/css/style.css` (append new rules; also add `order` to 4 existing selectors)

**Interfaces:**
- Produces: `.nav-toggle` button, `.header-inner.is-open` state class (toggled by `nav.js`) — later tasks don't depend on this, but don't remove/rename these once created.

- [ ] **Step 1: Ensure local environment is running**

```bash
docker compose up -d
(curl -sf http://localhost:8080/cs >/dev/null) || (php -S localhost:8080 -t www > /tmp/balonky-php-server.log 2>&1 &)
sleep 1
curl -sI http://localhost:8080/cs | head -1
```
Expected: `HTTP/1.1 200 OK`

- [ ] **Step 2: Capture desktop baseline screenshot**

```bash
mkdir -p /tmp/mobile-responsive-shots
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --no-sandbox \
  --window-size=1280,900 --screenshot=/tmp/mobile-responsive-shots/task1-desktop-before.png \
  --virtual-time-budget=2000 "http://localhost:8080/cs"
```
Read `/tmp/mobile-responsive-shots/task1-desktop-before.png` — this is the "before" reference for the no-desktop-regression check in Step 6.

- [ ] **Step 3: Update header markup in `templates/layout/base.twig`**

Replace the `<header class="site-header">...</header>` block (lines 12-31) with:

```twig
    <header class="site-header">
        <div class="container header-inner">
            <a href="/{{ lang }}/" class="logo">{{ t('site.name') }}</a>
            <nav class="main-nav">
                <a href="/{{ lang }}/">{{ t('nav.home') }}</a>
                <a href="/{{ lang }}/shop">{{ t('nav.shop') }}</a>
                <a href="/{{ lang }}/services">{{ t('nav.services') }}</a>
                <a href="/{{ lang }}/gallery">{{ t('nav.gallery') }}</a>
                <a href="/{{ lang }}/blog">{{ t('nav.blog') }}</a>
                <a href="/{{ lang }}/contact">{{ t('nav.contact') }}</a>
            </nav>
            <a href="/{{ lang }}/cart" class="cart-link">{{ t('nav.cart') }}</a>
            <div class="lang-switcher">
                {% for code, label in {'cs': 'CZ', 'sk': 'SK', 'en': 'EN', 'uk': 'UA', 'ru': 'RU'} %}
                    <a href="/{{ code }}{{ current_path }}"
                       class="{{ code == lang ? 'active' : '' }}">{{ label }}</a>
                {% endfor %}
            </div>
            <button type="button" class="nav-toggle" aria-label="Menu" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>
        </div>
    </header>
```

Note: `cart-link` moves from being the last item inside `.main-nav` to being its own sibling — this is required so it can stay visible while `.main-nav` collapses on mobile. CSS `order` (Step 5) restores the exact original visual sequence on desktop.

Add before `</body>` (replacing the empty `{% block scripts %}{% endblock %}` on the last line):

```twig
    {% block scripts %}
    <script src="/assets/js/nav.js" defer></script>
    {% endblock %}
```

- [ ] **Step 4: Create `www/assets/js/nav.js`**

```js
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.querySelector('.nav-toggle');
    var headerInner = document.querySelector('.header-inner');
    if (!toggle || !headerInner) return;
    toggle.addEventListener('click', function () {
        var isOpen = headerInner.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
});
```

- [ ] **Step 5: Add CSS — `order` on existing selectors + new mobile block**

In `www/assets/css/style.css`, edit these 4 existing lines to append an `order` declaration (keeps desktop visual sequence identical after `cart-link` becomes a sibling):

```css
.logo { font-size: 1.4rem; color: var(--accent); text-decoration: none; letter-spacing: .04em; font-weight: bold; order: 1; }
.main-nav { display: flex; gap: 1.5rem; flex: 1; order: 2; }
.lang-switcher { display: flex; gap: .5rem; font-family: var(--ui-font); font-size: .8rem; order: 4; }
```

Append this new block right after the `.lang-switcher a.active` rule:

```css
/* Cart link (extracted from .main-nav so it stays visible when nav collapses) */
.cart-link { order: 3; color: var(--text); text-decoration: none; font-family: var(--ui-font); font-size: .9rem; letter-spacing: .03em; }
.cart-link:hover { color: var(--accent); }

/* Hamburger toggle (hidden above 768px) */
.nav-toggle { display: none; order: 5; }
.nav-toggle span { display: block; width: 100%; height: 2px; background: var(--text); }

/* Responsive: header nav (tablet & phone) */
@media (max-width: 768px) {
    .header-inner { position: relative; flex-wrap: wrap; }
    .cart-link { order: 2; margin-left: auto; }
    .nav-toggle {
        order: 3; display: flex; flex-direction: column; justify-content: center; gap: 5px;
        width: 40px; height: 40px; padding: 0; background: none; border: none; cursor: pointer;
    }
    .main-nav, .lang-switcher { display: none; flex-basis: 100%; }
    .main-nav { order: 4; flex-direction: column; gap: 0; }
    .main-nav a { padding: .85rem 0; border-top: 1px solid var(--border); font-size: 1rem; }
    .lang-switcher { order: 5; gap: .75rem; padding: .75rem 0 0; border-top: 1px solid var(--border); }
    .header-inner.is-open .main-nav,
    .header-inner.is-open .lang-switcher { display: flex; }
}
```

- [ ] **Step 6: Verify — desktop unchanged, phone shows hamburger, phone menu opens**

```bash
# Desktop regression check (compare visually to task1-desktop-before.png)
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --no-sandbox \
  --window-size=1280,900 --screenshot=/tmp/mobile-responsive-shots/task1-desktop-after.png \
  --virtual-time-budget=2000 "http://localhost:8080/cs"

# Phone, menu closed
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --no-sandbox \
  --window-size=375,812 --screenshot=/tmp/mobile-responsive-shots/task1-phone-closed.png \
  --virtual-time-budget=2000 "http://localhost:8080/cs"
```

Read both `task1-desktop-before.png` and `task1-desktop-after.png` — confirm they look identical (logo, nav links, cart, lang switcher in the same order/position, no hamburger visible).

Read `task1-phone-closed.png` — confirm: logo top-left, cart link + hamburger icon top-right, no nav links or language codes visible, no horizontal overflow.

To verify the open state (Chrome's `--screenshot` flag can't click elements), build a static preview with the class pre-applied:

```bash
curl -s http://localhost:8080/cs -o /tmp/mobile-responsive-shots/cs.html
sed -i '' 's/class="container header-inner"/class="container header-inner is-open"/' /tmp/mobile-responsive-shots/cs.html
cp /tmp/mobile-responsive-shots/cs.html www/_preview_nav_open.html
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --no-sandbox \
  --window-size=375,812 --screenshot=/tmp/mobile-responsive-shots/task1-phone-open.png \
  --virtual-time-budget=2000 "http://localhost:8080/_preview_nav_open.html"
rm www/_preview_nav_open.html
```

Read `task1-phone-open.png` — confirm: nav links stacked full-width below the header row, language codes visible in a row below the nav links, no overflow.

- [ ] **Step 7: Commit**

```bash
git add templates/layout/base.twig www/assets/js/nav.js www/assets/css/style.css
git commit -m "feat: add hamburger nav for mobile/tablet header"
```

---

### Task 2: Shop sidebar (horizontal pills) + product/gallery grid density

**Files:**
- Modify: `www/assets/css/style.css` (append new rules)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: nothing consumed by later tasks (independent CSS section).

- [ ] **Step 1: Ensure local environment is running** (same check as Task 1 Step 1)

- [ ] **Step 2: Add CSS**

Append to `www/assets/css/style.css`:

```css
/* Responsive: shop sidebar & product/gallery grids */
@media (max-width: 768px) {
    .shop-layout { grid-template-columns: 1fr; gap: 1.5rem; padding: 1.5rem 1rem; }
    .shop-sidebar {
        flex-direction: row; overflow-x: auto; gap: .5rem; padding-bottom: .5rem;
        -webkit-overflow-scrolling: touch;
    }
    .cat-filter {
        white-space: nowrap; flex-shrink: 0; border: 1px solid var(--border); border-radius: 999px;
    }
    .product-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 1rem; }
    .gallery-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1rem; }
    .photo-grid { columns: 2; }
}

@media (max-width: 480px) {
    .photo-grid { columns: 1; }
}
```

- [ ] **Step 3: Verify**

Find a real album slug to screenshot the photo grid:

```bash
docker exec balonkydecor_db mysql -ubalonky -pbalonky balonkydecor -e "select slug from gallery_albums limit 1;"
```

```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --no-sandbox \
  --window-size=375,900 --screenshot=/tmp/mobile-responsive-shots/task2-shop-phone.png \
  --virtual-time-budget=2000 "http://localhost:8080/cs/shop"

"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --no-sandbox \
  --window-size=375,900 --screenshot=/tmp/mobile-responsive-shots/task2-gallery-phone.png \
  --virtual-time-budget=2000 "http://localhost:8080/cs/gallery"

"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --no-sandbox \
  --window-size=375,900 --screenshot=/tmp/mobile-responsive-shots/task2-album-phone.png \
  --virtual-time-budget=2000 "http://localhost:8080/cs/gallery/<SLUG_FROM_ABOVE>"
```

Read `task2-shop-phone.png` — confirm categories render as a horizontally-scrollable row of pill buttons above a 2-column product grid, no vertical sidebar column.

Read `task2-gallery-phone.png` — confirm gallery album grid shows at least 2 columns, no oversized single-column cards.

Read `task2-album-phone.png` — confirm individual photos render in a single column (this album is empty/has photos, either way confirm no 3-column squeeze).

- [ ] **Step 4: Commit**

```bash
git add www/assets/css/style.css
git commit -m "style: responsive shop sidebar pills and denser product/gallery/photo grids"
```

---

### Task 3: Product detail & checkout single-column collapse

**Files:**
- Modify: `www/assets/css/style.css` (append new rules)

**Interfaces:**
- Consumes: nothing from Tasks 1-2.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Ensure local environment is running**

- [ ] **Step 2: Add CSS**

Append to `www/assets/css/style.css`:

```css
/* Responsive: product detail & checkout */
@media (max-width: 768px) {
    .product-detail { grid-template-columns: 1fr; gap: 2rem; padding: 2rem 1rem; }
    .checkout-layout { grid-template-columns: 1fr; gap: 2rem; padding: 2rem 1rem; }
}
```

- [ ] **Step 3: Verify**

Find a real product SKU to screenshot:

```bash
docker exec balonkydecor_db mysql -ubalonky -pbalonky balonkydecor -e "select sku from products limit 1;"
```

```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --no-sandbox \
  --window-size=375,1000 --screenshot=/tmp/mobile-responsive-shots/task3-product-phone.png \
  --virtual-time-budget=2000 "http://localhost:8080/cs/shop/<SKU_FROM_ABOVE>"
```

Read `task3-product-phone.png` — confirm gallery image is full-width on top, product info (title/price/qty/add-to-cart) stacks below it, no side-by-side squeeze.

Checkout requires a non-empty cart session, which headless Chrome's `--screenshot` flag can't carry cookies into directly. Add an item via `curl` with a cookie jar, fetch the rendered HTML through that same session, then screenshot the saved HTML as a local static file (same technique as Task 4 Step 7):

```bash
curl -s -c /tmp/checkout-cookie.txt -b /tmp/checkout-cookie.txt \
  -X POST http://localhost:8080/cs/cart/add -d "sku=<SKU_FROM_ABOVE>&qty=1" -o /dev/null
curl -s -b /tmp/checkout-cookie.txt http://localhost:8080/cs/checkout -o /tmp/mobile-responsive-shots/checkout.html
cp /tmp/mobile-responsive-shots/checkout.html www/_preview_checkout.html
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --no-sandbox \
  --window-size=375,1200 --screenshot=/tmp/mobile-responsive-shots/task3-checkout-phone.png \
  --virtual-time-budget=2000 "http://localhost:8080/_preview_checkout.html"
rm www/_preview_checkout.html
```

Read `task3-checkout-phone.png` — confirm the form renders full-width on top and the order summary box renders below it, single column, no horizontal overflow.

- [ ] **Step 4: Commit**

```bash
git add www/assets/css/style.css
git commit -m "style: single-column layout for product detail and checkout on mobile/tablet"
```

---

### Task 4: Cart table → stacked cards on phone

**Files:**
- Modify: `www/assets/css/style.css` (append new rules)
- Modify: `templates/public/cart.twig` (add `data-label` to `<td>`s)
- Modify: `templates/public/checkout/index.twig` (add `data-label` to summary `<td>`s)
- Modify: `templates/public/order/status.twig` (add `data-label` to `<td>`s)
- Modify: `templates/public/checkout/confirm.twig` (add `data-label` to `<td>`s)

**Interfaces:**
- Consumes: nothing from Tasks 1-3.
- Produces: nothing consumed by later tasks.

All four templates share the `.cart-table` class (defined once in CSS) — the stacked-card treatment at ≤480px applies to every usage, so every usage needs matching `data-label` attributes or its stacked rows will show blank labels.

- [ ] **Step 1: Ensure local environment is running**

- [ ] **Step 2: Add CSS**

Append to `www/assets/css/style.css`:

```css
/* Responsive: cart-table stacked cards (phone only) */
@media (max-width: 480px) {
    .cart-table thead { display: none; }
    .cart-table, .cart-table tbody, .cart-table tr, .cart-table td { display: block; width: 100%; }
    .cart-table tr { border: 1px solid var(--border); border-radius: 4px; margin-bottom: 1rem; padding: .5rem .75rem; }
    .cart-table td {
        display: flex; justify-content: space-between; align-items: center;
        padding: .5rem 0; border-bottom: 1px solid var(--border);
    }
    .cart-table td:last-child { border-bottom: none; }
    .cart-table td::before {
        content: attr(data-label); font-family: var(--ui-font); font-size: .8rem; color: var(--muted); margin-right: 1rem;
    }
}
```

- [ ] **Step 3: Update `templates/public/cart.twig`**

Replace the `<tr>` inside `{% for sku, item in items %}` (lines 24-36) with:

```twig
                <tr>
                    <td class="cart-name" data-label="{{ t('cart.product') }}">{{ item.name }}</td>
                    <td class="cart-price" data-label="{{ t('cart.price') }}">{{ item.price|number_format(2, '.', ' ') }} Kč</td>
                    <td class="cart-qty" data-label="{{ t('cart.qty') }}">
                        <input type="number" name="items[{{ sku }}]"
                               value="{{ item.qty }}" min="0" class="qty-input">
                    </td>
                    <td class="cart-subtotal" data-label="{{ t('cart.subtotal') }}">{{ item.subtotal|number_format(2, '.', ' ') }} Kč</td>
                    <td class="cart-actions" data-label="">
                        <button type="submit" name="items[{{ sku }}]" value="0"
                                class="btn-remove" title="{{ t('cart.remove') }}">×</button>
                    </td>
                </tr>
```

- [ ] **Step 4: Update `templates/public/checkout/index.twig`**

Replace the `<tr>` inside `{% for sku, item in items %}` (the summary table) with:

```twig
                <tr>
                    <td data-label="{{ t('cart.product') }}">{{ item.name }}</td>
                    <td data-label="{{ t('cart.qty') }}">{{ item.qty }}</td>
                    <td data-label="{{ t('cart.subtotal') }}">{{ item.subtotal|number_format(2, '.', ' ') }} Kč</td>
                </tr>
```

- [ ] **Step 5: Update `templates/public/order/status.twig`**

Replace the `<tr>` inside `{% for item in order.items %}` with:

```twig
            <tr>
                <td data-label="{{ t('order.product') }}">{{ item.product_name_snapshot }}</td>
                <td data-label="{{ t('order.qty') }}">{{ item.quantity }}</td>
                <td data-label="{{ t('order.unit_price') }}">{{ item.unit_price|number_format(2, '.', ' ') }} Kč</td>
                <td data-label="{{ t('order.total') }}">{{ (item.unit_price * item.quantity)|number_format(2, '.', ' ') }} Kč</td>
            </tr>
```

- [ ] **Step 6: Update `templates/public/checkout/confirm.twig`**

Replace the `<tr>` inside `{% for item in order.items %}` with the identical block from Step 5.

- [ ] **Step 7: Verify**

```bash
docker exec balonkydecor_db mysql -ubalonky -pbalonky balonkydecor -e "select sku from products limit 1;"
curl -s -c /tmp/cart-cookie.txt -b /tmp/cart-cookie.txt -X POST http://localhost:8080/cs/cart/add -d "sku=<SKU_FROM_ABOVE>&qty=2" -o /dev/null
curl -s -b /tmp/cart-cookie.txt http://localhost:8080/cs/cart -o /tmp/mobile-responsive-shots/cart.html
cp /tmp/mobile-responsive-shots/cart.html www/_preview_cart.html
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --no-sandbox \
  --window-size=375,900 --screenshot=/tmp/mobile-responsive-shots/task4-cart-phone.png \
  --virtual-time-budget=2000 "http://localhost:8080/_preview_cart.html"
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --no-sandbox \
  --window-size=768,900 --screenshot=/tmp/mobile-responsive-shots/task4-cart-tablet.png \
  --virtual-time-budget=2000 "http://localhost:8080/_preview_cart.html"
rm www/_preview_cart.html
```

Read `task4-cart-phone.png` — confirm each cart line renders as a bordered card with label/value pairs (e.g. "Produkt  ProductName", "Cena  X Kč"), remove button visible, no raw table grid lines.

Read `task4-cart-tablet.png` — confirm it still renders as a normal table (stacking only triggers ≤480px).

- [ ] **Step 8: Commit**

```bash
git add www/assets/css/style.css templates/public/cart.twig templates/public/checkout/index.twig templates/public/order/status.twig templates/public/checkout/confirm.twig
git commit -m "style: stack cart-table rows as cards on phone widths"
```

---

### Task 5: Typography scale-down, form widths, container padding (phone)

**Files:**
- Modify: `www/assets/css/style.css` (append new rules)

**Interfaces:**
- Consumes: nothing from Tasks 1-4.
- Produces: nothing consumed by later tasks. Last task in this plan.

- [ ] **Step 1: Ensure local environment is running**

- [ ] **Step 2: Add CSS**

Append to `www/assets/css/style.css`:

```css
/* Responsive: typography, forms, spacing (phone only) */
@media (max-width: 480px) {
    .container { padding: 0 1rem; }
    .hero { padding: 3rem 0; }
    .hero h1 { font-size: 2rem; }
    .hero-subtitle { font-size: 1rem; }
    .page-hero { padding: 2rem 0 1.5rem; }
    .page-hero h1 { font-size: 1.5rem; }
    .product-detail-info h1 { font-size: 1.4rem; }
    .product-price-lg { font-size: 1.3rem; }
    .blog-post-full h1 { font-size: 1.5rem; }
    .btn { width: 100%; text-align: center; }
    .contact-form input, .contact-form textarea, .add-to-cart-form .qty-input { font-size: 16px; }
    .qty-input { width: 100%; }
    .cart-total-block { flex-wrap: wrap; width: 100%; }
    .cart-footer { flex-direction: column; align-items: stretch; }
}
```

(`font-size: 16px` on form inputs prevents iOS Safari's automatic zoom-on-focus, a common mobile-web papercut.)

- [ ] **Step 3: Verify**

```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --no-sandbox \
  --window-size=375,700 --screenshot=/tmp/mobile-responsive-shots/task5-home-phone.png \
  --virtual-time-budget=2000 "http://localhost:8080/cs"

"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --no-sandbox \
  --window-size=375,900 --screenshot=/tmp/mobile-responsive-shots/task5-contact-phone.png \
  --virtual-time-budget=2000 "http://localhost:8080/cs/contact"
```

Read `task5-home-phone.png` — confirm hero heading fits without wrapping awkwardly, CTA button spans full width, no text touches the screen edge.

Read `task5-contact-phone.png` — confirm form inputs/textarea/submit button span full width with comfortable edge padding.

- [ ] **Step 4: Full regression sweep across all in-scope pages**

```bash
for path in "/cs" "/cs/shop" "/cs/services" "/cs/gallery" "/cs/blog" "/cs/contact" "/cs/cart"; do
  name=$(echo "$path" | tr '/' '_')
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --no-sandbox \
    --window-size=375,1000 --screenshot="/tmp/mobile-responsive-shots/final${name}_phone.png" \
    --virtual-time-budget=2000 "http://localhost:8080${path}"
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --no-sandbox \
    --window-size=768,1000 --screenshot="/tmp/mobile-responsive-shots/final${name}_tablet.png" \
    --virtual-time-budget=2000 "http://localhost:8080${path}"
done
```

Read each generated screenshot. Confirm no page has horizontal scroll/overflow, all text is readable, and the desktop screenshot from Task 1 Step 6 still matches (spot-check `/cs` at 1280px one more time).

- [ ] **Step 5: Tear down local environment**

```bash
pkill -f "php -S localhost:8080" 2>/dev/null
docker compose down
rm -rf /tmp/mobile-responsive-shots /tmp/cart-cookie.txt
```

- [ ] **Step 6: Commit**

```bash
git add www/assets/css/style.css
git commit -m "style: phone typography scale-down and full-width form controls"
```
