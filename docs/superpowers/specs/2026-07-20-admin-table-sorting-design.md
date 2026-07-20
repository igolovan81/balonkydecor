# Admin Table Column Sorting — Design Spec

Date: 2026-07-20

## Problem

Admin list tables (Products, Categories, Gallery albums, Services, Users)
render every row unsorted apart from a fixed `ORDER BY` baked into the
model query (usually `id DESC` or `updated_at`). An admin who wants to,
say, find the cheapest product or the oldest unedited category has no way
to reorder the table — they have to scan the whole list by eye.

## Scope

- Tables getting sortable columns: **Products, Categories, Gallery
  (albums), Services, Users**. All five render their *entire* dataset with
  no server-side pagination, so sorting purely in the browser is safe —
  the visible rows are the whole dataset.
- **Excluded — paginated tables:** Orders, Notifications, Page-views. Each
  paginates via `LIMIT`/`OFFSET` in its model method, so a client-side sort
  would only reorder the current page and silently hide the fact that rows
  on other pages aren't part of the sort — worse than no sorting at all.
  Sorting these would need a server-side `ORDER BY` param instead; that's a
  separate feature if ever wanted.
- **Excluded — Pages:** the list is just a slug + an edit link, one data
  column, nothing meaningful to reorder.
- **Excluded — Settings:** it's a settings form, not a list table.
- No sort-state persistence (URL, localStorage, or session) — sort resets
  to the default order on reload or navigation. No pagination exists to
  interact badly with this, and it keeps the feature to plain client-side
  UI state.
- Single-column sort only, ascending/descending toggle — no multi-column
  sort.
- No backend, route, or model changes. No new translation keys (column
  headers already have `t()` labels; no new user-facing strings are
  introduced).

## Design

### Mechanism — client-side, vanilla JS

One new file, `www/assets/js/admin-sortable-table.js`, included once,
unconditionally, from `templates/layout/admin-base.twig` (with the
existing `?v={{ asset_v(...) }}` cache-busting convention). It self-guards
— on `DOMContentLoaded` it queries `table[data-sortable]`; if none exist on
the page, it does nothing.

For each `table[data-sortable]`:

- Every sortable `<th>` has its label text wrapped in
  `<button type="button" class="sort-btn" data-sort-type="text|number">`
  — a real `<button>`, not a clickable `<span>`/`<div>`, per the
  accessibility rule in `.claude/rules/frontend.md`. Non-sortable columns
  (checkboxes, images, actions) keep a plain `<th>` with no button.
- Clicking a `.sort-btn`:
  1. Reads `data-sort-type` (`"text"` or `"number"`) off the button.
  2. Determines direction: ascending if this column wasn't the active
     sort column, or if it was active and currently descending;
     descending if it was active and currently ascending. (First click on
     a column is always ascending.)
  3. Reads all `<tr>` from the table's single `<tbody>`, sorts them with a
     comparator based on type, and re-appends them in the new order
     (`Array.prototype.sort` + `tbody.append(...sortedRows)` — a stable,
     in-place DOM reorder, no re-render from Twig).
  4. Sets `aria-sort="ascending"|"descending"` on the active `<th>` and
     `aria-sort="none"` on every other sortable `<th>` in the table, so
     screen readers announce the current state.
  5. Toggles a `sort-asc`/`sort-desc` class on the active `<th>` (removed
     from all others) — CSS uses this to show a directional arrow via
     `::after`, styled with existing design tokens, no new images.
- **Value extraction per cell:** the sort reads
  `td.dataset.sortValue ?? td.textContent.trim()`. Columns whose displayed
  text is already directly sortable (name, category, slug, email, role,
  ISO-formatted date strings) need no `data-sort-value` — plain text
  comparison (or `localeCompare` for `text` type) works, and
  `YYYY-MM-DD HH:MM:SS` timestamps sort correctly as strings. Columns
  where display text isn't the raw value get an explicit
  `data-sort-value` on the `<td>`:
  - Price cells (`"59.99 Kč"`) → `data-sort-value="{{ p.price }}"`.
  - Product stock (`"50 ks"` or `"—"` for unlimited) →
    `data-sort-value="{{ p.stock_type == 'limited' ? p.stock_qty : -1 }}"`
    (unlimited-stock rows sort as lowest).
  - Active checkmark (`"✓"`/`"—"`) →
    `data-sort-value="{{ p.is_active ? 1 : 0 }}"`.
  - The audit "updated" cell mixes an email and a date on two lines
    (`{{ updated_by_email }}<br>{{ updated_at }}`) →
    `data-sort-value="{{ p.updated_at }}"` so it sorts purely by date, not
    by the concatenated text.
- Numeric comparison parses `parseFloat(value)`, treating a missing/blank
  value as `-Infinity` on ascending sort (sorts to the bottom) — mirrors
  how blank/`—` cells behave in a spreadsheet sort.

### Per-table column sortability

| Table | Sortable columns | Not sortable |
|---|---|---|
| Products | Name, Category, Price, Stock, Active, Updated | checkbox, image, actions |
| Categories | ID, Name, Slug, Order, Updated | actions |
| Gallery | ID, Name, Order, Updated | actions |
| Services | ID, Name, Price, Order, Updated | actions |
| Users | ID, Email, Role, Created | actions |

`ID`/`Order` columns use `data-sort-type="number"`; `Updated`/`Created`
use `data-sort-type="text"` (ISO strings sort correctly lexically); all
others per the table above.

### CSS — `www/assets/css/admin.css`

- `.sort-btn`: reset to look like the existing plain `<th>` text (no
  button chrome — transparent background, inherit font/color/weight),
  `cursor: pointer`, full-width/height of the header cell so the whole
  header area is clickable, keeps the global `:focus-visible` outline
  (no outline removal).
- `.sort-btn::after`: a small arrow glyph, hidden (`opacity: 0`) by
  default; `th[aria-sort="ascending"] .sort-btn::after` /
  `th[aria-sort="descending"] .sort-btn::after` show `▲`/`▼` using
  `--muted`/`--text` tokens — no new image assets, matches the "inline SVG
  or CSS, no extra files" convention for small decorative marks.

### Testing

None — per `.claude/rules/unit-testing.md`, Twig/CSS/JS behavior is
excluded from the PHPUnit suite and verified by running the app locally
(`php -S localhost:8080 -t www`) and clicking through sort ascending/
descending on at least one text, one number, and one date column across
the five affected tables.

## Out of scope

- Sorting the paginated tables (Orders, Notifications, Page-views) — would
  require server-side `ORDER BY` params threaded through the controller,
  model, and pagination links; a separate feature if wanted later.
- Persisting sort choice (URL query param, localStorage) across reloads.
- Multi-column sort.
- A generic/reusable "DataTable" abstraction beyond the one small JS file
  — five templates get a few `data-*` attributes each; no shared Twig
  macro or component is introduced for this.
