# Public Site Mobile/Tablet Responsive Layout — Design Spec

**Date:** 2026-07-01

## Problem

`www/assets/css/style.css` has zero media queries. The header nav (logo + 6 links + 5-language switcher + cart link, all in one flex row) and several grid layouts (shop sidebar+grid, product detail, checkout) only work at desktop widths. On a phone the nav overflows/wraps badly and multi-column grids get crushed.

## Scope

All public templates under `templates/public/` and the shared `templates/layout/base.twig` header/footer. Admin (`/admin/*`) is out of scope — it already renders acceptably on a sidebar+content layout and wasn't part of this request.

## Breakpoints

Two breakpoints, added as `@media (max-width: ...)` blocks appended to the existing `www/assets/css/style.css` (no rewrite of existing desktop rules, mobile-first overrides only):
- `≤768px` — tablet: nav collapses to hamburger, two-column grids go single-column, spacing tightens.
- `≤480px` — phone: further font-size/padding reduction, cart table becomes stacked cards.

Touch targets (nav links, buttons, qty input, pagination links) stay ≥44px tall below 768px.

## Header / Nav — hamburger menu

New file `www/assets/js/nav.js` — the site's first and only JS file. Toggles a `.is-open` class on `.main-nav` when a new `.nav-toggle` button (hamburger icon, pure CSS via 3 spans, no image asset) is clicked. Loaded via `{% block scripts %}` in `base.twig` (deferred, no framework).

Markup changes to `templates/layout/base.twig`:
- Add `<button class="nav-toggle" aria-label="Menu" aria-expanded="false">` (three `<span>` bars) between logo and nav.
- Wrap nav links + lang switcher in a container that becomes the collapsible panel below 768px.

Behavior below 768px:
- Logo + cart link + hamburger button stay in the visible header row (cart always one tap away).
- Nav links + lang switcher move into a full-width panel, hidden by default (`display:none`), shown via `.is-open` (`display:flex; flex-direction:column`), sliding down below the header row (`position:absolute` under header, full width, `background:#fff`, `border-bottom`).
- Toggling sets `aria-expanded` via JS for accessibility; no other JS behavior (no focus trap, no ESC handling — YAGNI for this small site).

Above 768px: hamburger button is hidden (`display:none`), nav renders exactly as it does today — zero visual change on desktop.

## Shop sidebar → horizontal scroll pills

Below 768px:
- `.shop-layout` grid: `grid-template-columns: 1fr` (single column), sidebar first, grid second (DOM order unchanged since sidebar already comes first in the markup).
- `.shop-sidebar` becomes a horizontal flex row: `display:flex; flex-direction:row; overflow-x:auto; gap:.5rem; padding-bottom:.5rem;` with `-webkit-overflow-scrolling:touch`.
- `.cat-filter` items get pill styling in this mode: `white-space:nowrap; flex-shrink:0; border:1px solid var(--border); border-radius:999px;` (border-radius only applied in the mobile media query, desktop keeps square `2px`).

## Other page-specific collapses

All standard `grid-template-columns` → `1fr` swaps inside the `≤768px` media query, no new mechanism:

- **Product grid / gallery grid / blog list**: already `auto-fill,minmax(...)`, reflows naturally — just lower the `minmax` floor (e.g. product grid `220px`→`140px`, gallery `240px`→`150px`) so at least 2 columns fit on a 375px-wide phone instead of 1 oversized card.
- **Product detail** (`.product-detail`, currently `1fr 1fr`): → `1fr`, gallery block above info block (DOM order already gallery-first).
- **Checkout** (`.checkout-layout`, currently `1fr 360px`): → `1fr`, summary renders below the form (DOM order already form-first, summary second).
- **Gallery `photo-grid`** (currently `columns:3`): → `columns:2` at ≤768px, `columns:1` at ≤480px.
- **Cart table** (`.cart-table`): at ≤480px only, switch from `<table>` layout to stacked cards — `thead` hidden (`display:none`), each `tr` becomes a bordered block (`display:block; margin-bottom:1rem`), each `td` becomes `display:flex; justify-content:space-between` with a `::before { content: attr(data-label); }` label. Requires adding `data-label="..."` attributes to the `<td>` cells in `templates/public/cart.twig` (values: product name / price / qty / subtotal / remove, translated via existing `t()` keys already used for the `<th>` headers).
- **Forms** (contact / checkout / cart qty): inputs/selects/buttons go `width:100%` below 480px; `.qty-row`, `.cart-footer`, `.form-actions`-style flex rows wrap (`flex-wrap:wrap`) with full-width children.
- **`.container` / page paddings**: reduce horizontal padding from `1.5rem` to `1rem` at ≤480px so text doesn't feel cramped against the viewport edge.

## Testing

Local server (`docker compose up -d` + `php -S localhost:8080 -t www`), Chrome headless screenshots at three widths — 375×812 (phone), 768×1024 (tablet), 1280×800 (desktop, regression check) — for: home, shop grid, product detail, cart (empty + with items), checkout, gallery album list + photo grid, blog list + post, services, contact. Confirm no horizontal overflow, nav opens/closes correctly, and desktop appearance is pixel-identical to before (diff the `>768px` styles visually).

## Out of scope

- Admin panel responsive work (separate area, not requested).
- Any JS beyond the nav toggle (no carousel libs, no lazy-load, no framework).
- Changing desktop (`>768px`) visual design — this is additive-only for desktop.
