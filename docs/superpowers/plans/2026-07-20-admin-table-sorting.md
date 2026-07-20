# Admin Table Column Sorting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins click a column header on the Products, Categories, Gallery, Services, and Users admin list tables to sort that table's rows ascending/descending, purely client-side.

**Architecture:** One small vanilla-JS module (`admin-sortable-table.js`) reorders `<tr>` elements inside a `<tbody>` in place when a header `<button>` is clicked. It's loaded once, globally, from `admin-base.twig`, and self-guards (no-ops on pages with no `table[data-sortable]`). Each of the five templates opts in by adding `data-sortable` to its `<table>`, wrapping sortable header labels in a `<button class="sort-btn" data-sort-type="text|number">`, and adding a `data-sort-value` attribute to `<td>`s whose displayed text isn't directly sortable.

**Tech Stack:** Twig 3 templates, vanilla ES5 JS (no build step), plain CSS (no preprocessor). No PHP/backend/DB changes.

## Global Constraints

- No build step, no npm, no frameworks — plain vanilla JS, matching `.claude/rules/frontend.md`.
- Interactive elements must be real `<button>`/`<a>`, never a clickable `<span>`/`<div>` (`.claude/rules/frontend.md`).
- New JS asset files load via `?v={{ asset_v('...') }}` cache-busting, same as every other asset (`CLAUDE.md`).
- `admin.css` uses literal hex colors throughout (no CSS custom properties) — match that existing convention exactly; do not introduce `var(--...)` into this file.
- No new translation keys — column headers already have `t()` labels; sorting adds no new user-facing copy.
- No PHPUnit tests for this change — Twig/CSS/JS behavior is explicitly excluded from the test suite per `.claude/rules/unit-testing.md`; every task is verified by running `php -S localhost:8080 -t www` and clicking through the UI.
- Scope is exactly these five tables: Products, Categories, Gallery, Services, Users. Orders/Notifications/Page-views (paginated) and Pages/Settings (not sortable lists) are explicitly out of scope — do not touch them.
- Full spec: `docs/superpowers/specs/2026-07-20-admin-table-sorting-design.md`.

---

## Task 1: Sortable-table JS/CSS infrastructure + Products table

**Files:**
- Create: `www/assets/js/admin-sortable-table.js`
- Modify: `templates/layout/admin-base.twig` (add script tag)
- Modify: `www/assets/css/admin.css` (add sort styles)
- Modify: `templates/admin/products/index.twig`

**Interfaces:**
- Produces (consumed by Tasks 2–5, unchanged from here on):
  - Markup contract: `<table class="admin-table" data-sortable>` opts a table in.
  - Sortable header: `<th><button type="button" class="sort-btn" data-sort-type="text|number">{{ label }}</button></th>`.
  - Non-sortable header: plain `<th>{{ label }}</th>` (no button) — the JS never touches it.
  - Cell override: `<td data-sort-value="...">` — used whenever the cell's visible text isn't the raw sortable value (currency-suffixed prices, quantity-or-em-dash stock, checkmark booleans, two-line audit cells). Omit it when the cell's own text is already sortable (plain numbers, plain strings, `YYYY-MM-DD HH:MM:SS` timestamps sort correctly as lexical text).
  - CSS: `.sort-btn` is the only class name introduced; direction state is read from the `<th>`'s `aria-sort` attribute (`"ascending"|"descending"|"none"`), not a body-level class.

- [ ] **Step 1: Create the JS module**

Create `www/assets/js/admin-sortable-table.js`:

```js
document.addEventListener('DOMContentLoaded', function () {
    var tables = document.querySelectorAll('table[data-sortable]');
    if (!tables.length) return;

    tables.forEach(function (table) {
        var tbody = table.querySelector('tbody');
        var buttons = table.querySelectorAll('th .sort-btn');
        if (!tbody || !buttons.length) return;

        buttons.forEach(function (btn) {
            var th = btn.closest('th');
            th.setAttribute('aria-sort', 'none');

            btn.addEventListener('click', function () {
                sortByColumn(table, tbody, buttons, th, btn.dataset.sortType);
            });
        });
    });

    function sortByColumn(table, tbody, buttons, th, type) {
        var headerRow = th.parentNode;
        var colIndex = Array.prototype.indexOf.call(headerRow.children, th);
        var dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';

        buttons.forEach(function (otherBtn) {
            var otherTh = otherBtn.closest('th');
            otherTh.setAttribute('aria-sort', 'none');
            otherTh.classList.remove('sort-asc', 'sort-desc');
        });
        th.setAttribute('aria-sort', dir);
        th.classList.add(dir === 'ascending' ? 'sort-asc' : 'sort-desc');

        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        rows.sort(function (rowA, rowB) {
            var valA = cellValue(rowA, colIndex, type);
            var valB = cellValue(rowB, colIndex, type);
            var cmp = type === 'number'
                ? valA - valB
                : String(valA).localeCompare(String(valB), undefined, { sensitivity: 'base' });
            return dir === 'ascending' ? cmp : -cmp;
        });

        rows.forEach(function (row) { tbody.appendChild(row); });
    }

    function cellValue(row, colIndex, type) {
        var cell = row.children[colIndex];
        if (!cell) return type === 'number' ? -Infinity : '';
        var raw = cell.dataset.sortValue !== undefined ? cell.dataset.sortValue : cell.textContent.trim();
        if (type === 'number') {
            var num = parseFloat(raw);
            return isNaN(num) ? -Infinity : num;
        }
        return raw;
    }
});
```

- [ ] **Step 2: Wire the script into `admin-base.twig`**

In `templates/layout/admin-base.twig`, find this line (currently the last line before `{% block scripts %}`):

```twig
    <script src="/assets/js/admin-notifications.js?v={{ asset_v('assets/js/admin-notifications.js') }}" defer></script>
```

Add a new line directly after it:

```twig
    <script src="/assets/js/admin-notifications.js?v={{ asset_v('assets/js/admin-notifications.js') }}" defer></script>
    <script src="/assets/js/admin-sortable-table.js?v={{ asset_v('assets/js/admin-sortable-table.js') }}" defer></script>
```

- [ ] **Step 3: Add sort styles to `admin.css`**

In `www/assets/css/admin.css`, find the "Tables" section:

```css
/* Tables */
.admin-table { width:100%; border-collapse:collapse; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.08); }
.admin-table th { background:#f0f0f5; padding:0.75rem 1rem; text-align:left; font-size:0.85rem; color:#555; }
.admin-table td { padding:0.75rem 1rem; border-top:1px solid #eee; font-size:0.9rem; }
.admin-table tr:hover td { background:#fafafa; }
```

Add these rules directly after the existing four (still inside the "Tables" section, before `.audit-meta`):

```css
.admin-table th:has(.sort-btn) { padding:0; }
.sort-btn {
    display:flex; align-items:center; gap:0.35rem; width:100%;
    padding:0.75rem 1rem; background:none; border:none; font:inherit;
    color:#555; text-align:left; cursor:pointer;
}
.sort-btn::after { content:''; font-size:0.65em; width:0.7em; opacity:0; }
th[aria-sort="ascending"] .sort-btn::after { content:'▲'; opacity:1; color:#333; }
th[aria-sort="descending"] .sort-btn::after { content:'▼'; opacity:1; color:#333; }
```

- [ ] **Step 4: Apply sorting to the Products table**

In `templates/admin/products/index.twig`, change the `<table>` tag:

```twig
<table class="admin-table">
```
to:
```twig
<table class="admin-table" data-sortable>
```

Replace the `<thead>` block:

```twig
    <thead>
        <tr>
            <th><input type="checkbox" id="select-all-checkbox" aria-label="{{ t('products.bulk.select_all') }}"></th>
            <th>{{ t('products.col.image') }}</th>
            <th>{{ t('products.col.name') }}</th>
            <th>{{ t('products.col.category') }}</th>
            <th>{{ t('products.col.price') }}</th>
            <th>{{ t('products.col.stock') }}</th>
            <th>{{ t('products.col.active') }}</th>
            <th>{{ t('products.col.updated') }}</th>
            <th>{{ t('products.col.actions') }}</th>
        </tr>
    </thead>
```

with:

```twig
    <thead>
        <tr>
            <th><input type="checkbox" id="select-all-checkbox" aria-label="{{ t('products.bulk.select_all') }}"></th>
            <th>{{ t('products.col.image') }}</th>
            <th><button type="button" class="sort-btn" data-sort-type="text">{{ t('products.col.name') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="text">{{ t('products.col.category') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="number">{{ t('products.col.price') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="number">{{ t('products.col.stock') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="number">{{ t('products.col.active') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="text">{{ t('products.col.updated') }}</button></th>
            <th>{{ t('products.col.actions') }}</th>
        </tr>
    </thead>
```

Replace the row-rendering block:

```twig
        <td>{{ p.name }}</td>
        <td>{{ p.category_name ?? '—' }}</td>
        <td>{{ p.price|number_format(2, '.', ' ') }} Kč</td>
        <td>{{ p.stock_type == 'limited' ? p.stock_qty ~ ' ks' : '—' }}</td>
        <td>{{ p.is_active ? '✓' : '—' }}</td>
        <td class="audit-meta">{{ p.updated_by_email ?? t('common.audit.unknown_user') }}<br>{{ p.updated_at }}</td>
```

with:

```twig
        <td>{{ p.name }}</td>
        <td>{{ p.category_name ?? '—' }}</td>
        <td data-sort-value="{{ p.price }}">{{ p.price|number_format(2, '.', ' ') }} Kč</td>
        <td data-sort-value="{{ p.stock_type == 'limited' ? p.stock_qty : -1 }}">{{ p.stock_type == 'limited' ? p.stock_qty ~ ' ks' : '—' }}</td>
        <td data-sort-value="{{ p.is_active ? 1 : 0 }}">{{ p.is_active ? '✓' : '—' }}</td>
        <td class="audit-meta" data-sort-value="{{ p.updated_at }}">{{ p.updated_by_email ?? t('common.audit.unknown_user') }}<br>{{ p.updated_at }}</td>
```

- [ ] **Step 5: Verify manually**

Ensure Docker MySQL is running and there are at least 2–3 products with different prices/stock/active states (the existing dev DB already has several — see the earlier screenshot showing 7+ products).

```bash
docker compose up -d
php -S localhost:8080 -t www
```

Open `http://localhost:8080/admin/products` (log in if prompted), then:
1. Click the "Название" (Name) header once → rows reorder alphabetically ascending; an "▲" appears next to the header; `aria-sort="ascending"` is present in the DOM on that `<th>` (check via browser dev tools).
2. Click it again → order reverses, arrow flips to "▼", `aria-sort="descending"`.
3. Click "Цена" (Price) → rows reorder by price ascending; the Name column's arrow disappears (`aria-sort` back to `"none"` on that `<th>`).
4. Click "На складе" (Stock) → rows with unlimited stock ("—") sort to the very bottom on ascending (they carry `data-sort-value="-1"`).
5. Click "Активный" (Active) → active (✓) and inactive (—) rows group together.
6. Click "Последнее обновление" (Updated) → rows reorder by date, most recent last on ascending.
7. Confirm the checkbox column, image column, and Actions column have no button/arrow and clicking them does nothing (they're not sortable).
8. Confirm the existing "Активировать/Деактивировать выбранные" bulk-action checkboxes and buttons still work after a sort (select two rows post-sort, activate them, confirm the flash message and row states still update correctly) — this exercises Task 1's change against the bulk-actions feature it sits alongside.

Stop the server (`Ctrl+C`) once verified.

- [ ] **Step 6: Commit**

```bash
git add www/assets/js/admin-sortable-table.js templates/layout/admin-base.twig www/assets/css/admin.css templates/admin/products/index.twig
git commit -m "feat: add client-side column sorting to admin products table"
```

---

## Task 2: Apply sorting to the Categories table

**Files:**
- Modify: `templates/admin/categories/index.twig`

**Interfaces:**
- Consumes: the markup contract from Task 1 (`data-sortable`, `.sort-btn` + `data-sort-type`, `data-sort-value`) — no JS/CSS changes needed, `admin-sortable-table.js` already runs on every admin page.

- [ ] **Step 1: Update the template**

In `templates/admin/categories/index.twig`, replace the whole `<table>` block:

```twig
<table class="admin-table">
    <thead>
        <tr>
            <th>{{ t('categories.col.id') }}</th>
            <th>{{ t('categories.col.name') }}</th>
            <th>{{ t('categories.col.slug') }}</th>
            <th>{{ t('categories.col.order') }}</th>
            <th>{{ t('categories.col.updated') }}</th>
            <th>{{ t('categories.col.actions') }}</th>
        </tr>
    </thead>
    <tbody>
    {% for cat in categories %}
    <tr>
        <td>{{ cat.id }}</td>
        <td>{{ cat.name ?? '—' }}</td>
        <td>{{ cat.slug }}</td>
        <td>{{ cat.sort_order }}</td>
        <td class="audit-meta">{{ cat.updated_by_email ?? t('common.audit.unknown_user') }}<br>{{ cat.updated_at }}</td>
        <td>
            <a href="/admin/categories/{{ cat.id }}/edit">{{ t('categories.edit') }}</a> |
            <form method="POST" action="/admin/categories/{{ cat.id }}/delete" style="display:inline" onsubmit="return confirm('{{ t('categories.confirm_delete') }}')">
                <button class="btn-link">{{ t('categories.delete') }}</button>
            </form>
        </td>
    </tr>
    {% else %}
    <tr><td colspan="6">{{ t('categories.no_categories') }}</td></tr>
    {% endfor %}
    </tbody>
</table>
```

with:

```twig
<table class="admin-table" data-sortable>
    <thead>
        <tr>
            <th><button type="button" class="sort-btn" data-sort-type="number">{{ t('categories.col.id') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="text">{{ t('categories.col.name') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="text">{{ t('categories.col.slug') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="number">{{ t('categories.col.order') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="text">{{ t('categories.col.updated') }}</button></th>
            <th>{{ t('categories.col.actions') }}</th>
        </tr>
    </thead>
    <tbody>
    {% for cat in categories %}
    <tr>
        <td>{{ cat.id }}</td>
        <td>{{ cat.name ?? '—' }}</td>
        <td>{{ cat.slug }}</td>
        <td>{{ cat.sort_order }}</td>
        <td class="audit-meta" data-sort-value="{{ cat.updated_at }}">{{ cat.updated_by_email ?? t('common.audit.unknown_user') }}<br>{{ cat.updated_at }}</td>
        <td>
            <a href="/admin/categories/{{ cat.id }}/edit">{{ t('categories.edit') }}</a> |
            <form method="POST" action="/admin/categories/{{ cat.id }}/delete" style="display:inline" onsubmit="return confirm('{{ t('categories.confirm_delete') }}')">
                <button class="btn-link">{{ t('categories.delete') }}</button>
            </form>
        </td>
    </tr>
    {% else %}
    <tr><td colspan="6">{{ t('categories.no_categories') }}</td></tr>
    {% endfor %}
    </tbody>
</table>
```

- [ ] **Step 2: Verify manually**

With the local server still running (`php -S localhost:8080 -t www`), open `http://localhost:8080/admin/categories`:
1. Click "ID" → rows sort numerically ascending, then descending on a second click.
2. Click "Название" (Name) → alphabetical sort; categories with no translation (showing "—") sort with the em-dash text.
3. Click "Обновлено" (Updated) → sorts by the date, not the mixed email+date text (verify newest/oldest lands where expected, matching what you saw in the un-sorted list's timestamps).
4. Confirm "Действия" (Actions) has no arrow/button and edit/delete links still work after sorting.

- [ ] **Step 3: Commit**

```bash
git add templates/admin/categories/index.twig
git commit -m "feat: add client-side column sorting to admin categories table"
```

---

## Task 3: Apply sorting to the Gallery (albums) table

**Files:**
- Modify: `templates/admin/gallery/index.twig`

**Interfaces:**
- Consumes: same markup contract as Task 2 (identical column shape: id/name/order/updated/actions).

- [ ] **Step 1: Update the template**

In `templates/admin/gallery/index.twig`, replace:

```twig
<table class="admin-table">
    <thead><tr><th>{{ t('gallery.col.id') }}</th><th>{{ t('gallery.col.name') }}</th><th>{{ t('gallery.col.order') }}</th><th>{{ t('gallery.col.updated') }}</th><th>{{ t('gallery.col.actions') }}</th></tr></thead>
    <tbody>
    {% for a in albums %}
    <tr>
        <td>{{ a.id }}</td>
        <td>{{ a.name }}</td>
        <td>{{ a.sort_order }}</td>
        <td class="audit-meta">{{ a.updated_by_email ?? t('common.audit.unknown_user') }}<br>{{ a.updated_at }}</td>
        <td>
            <a href="/admin/gallery/{{ a.id }}/edit">{{ t('gallery.edit') }}</a> |
            <form method="POST" action="/admin/gallery/{{ a.id }}/delete" style="display:inline" onsubmit="return confirm('{{ t('gallery.confirm_delete') }}')">
                <button class="btn-link">{{ t('gallery.delete') }}</button>
            </form>
        </td>
    </tr>
    {% else %}
    <tr><td colspan="5">{{ t('gallery.no_albums') }}</td></tr>
    {% endfor %}
    </tbody>
</table>
```

with:

```twig
<table class="admin-table" data-sortable>
    <thead><tr>
        <th><button type="button" class="sort-btn" data-sort-type="number">{{ t('gallery.col.id') }}</button></th>
        <th><button type="button" class="sort-btn" data-sort-type="text">{{ t('gallery.col.name') }}</button></th>
        <th><button type="button" class="sort-btn" data-sort-type="number">{{ t('gallery.col.order') }}</button></th>
        <th><button type="button" class="sort-btn" data-sort-type="text">{{ t('gallery.col.updated') }}</button></th>
        <th>{{ t('gallery.col.actions') }}</th>
    </tr></thead>
    <tbody>
    {% for a in albums %}
    <tr>
        <td>{{ a.id }}</td>
        <td>{{ a.name }}</td>
        <td>{{ a.sort_order }}</td>
        <td class="audit-meta" data-sort-value="{{ a.updated_at }}">{{ a.updated_by_email ?? t('common.audit.unknown_user') }}<br>{{ a.updated_at }}</td>
        <td>
            <a href="/admin/gallery/{{ a.id }}/edit">{{ t('gallery.edit') }}</a> |
            <form method="POST" action="/admin/gallery/{{ a.id }}/delete" style="display:inline" onsubmit="return confirm('{{ t('gallery.confirm_delete') }}')">
                <button class="btn-link">{{ t('gallery.delete') }}</button>
            </form>
        </td>
    </tr>
    {% else %}
    <tr><td colspan="5">{{ t('gallery.no_albums') }}</td></tr>
    {% endfor %}
    </tbody>
</table>
```

- [ ] **Step 2: Verify manually**

Open `http://localhost:8080/admin/gallery`:
1. Click "ID" and "Название" (Name) headers, confirm ascending/descending toggling.
2. Click "Порядок" (Order) → sorts by `sort_order` numerically.
3. Click "Обновлено" (Updated) → sorts by date.
4. Confirm edit/delete links still work post-sort.

- [ ] **Step 3: Commit**

```bash
git add templates/admin/gallery/index.twig
git commit -m "feat: add client-side column sorting to admin gallery table"
```

---

## Task 4: Apply sorting to the Services table

**Files:**
- Modify: `templates/admin/services/index.twig`

**Interfaces:**
- Consumes: same markup contract; adds one price column like Products' price column (nullable this time).

- [ ] **Step 1: Update the template**

In `templates/admin/services/index.twig`, replace the `<thead>`:

```twig
    <thead>
        <tr>
            <th>{{ t('services.col.id') }}</th>
            <th>{{ t('services.col.name') }}</th>
            <th>{{ t('services.col.price') }}</th>
            <th>{{ t('services.col.order') }}</th>
            <th>{{ t('services.col.updated') }}</th>
            <th>{{ t('services.col.actions') }}</th>
        </tr>
    </thead>
```

with:

```twig
    <thead>
        <tr>
            <th><button type="button" class="sort-btn" data-sort-type="number">{{ t('services.col.id') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="text">{{ t('services.col.name') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="number">{{ t('services.col.price') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="number">{{ t('services.col.order') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="text">{{ t('services.col.updated') }}</button></th>
            <th>{{ t('services.col.actions') }}</th>
        </tr>
    </thead>
```

Change the `<table>` tag:

```twig
<table class="admin-table">
```
to:
```twig
<table class="admin-table" data-sortable>
```

Replace the row body:

```twig
        <td>{{ service.id }}</td>
        <td>{{ service.name ?? '—' }}</td>
        <td>{{ service.price_from ? service.price_from ~ ' Kč' : '—' }}</td>
        <td>{{ service.sort_order }}</td>
        <td class="audit-meta">{{ service.updated_by_email ?? t('common.audit.unknown_user') }}<br>{{ service.updated_at }}</td>
```

with:

```twig
        <td>{{ service.id }}</td>
        <td>{{ service.name ?? '—' }}</td>
        <td data-sort-value="{{ service.price_from ?? -1 }}">{{ service.price_from ? service.price_from ~ ' Kč' : '—' }}</td>
        <td>{{ service.sort_order }}</td>
        <td class="audit-meta" data-sort-value="{{ service.updated_at }}">{{ service.updated_by_email ?? t('common.audit.unknown_user') }}<br>{{ service.updated_at }}</td>
```

- [ ] **Step 2: Verify manually**

Open `http://localhost:8080/admin/services`:
1. Click "Цена" (Price) ascending → services without a price ("—", `data-sort-value="-1"`) sort to the bottom, same as Products' Stock column.
2. Click "ID", "Название" (Name), "Порядок" (Order), "Обновлено" (Updated) — confirm each sorts correctly and toggles direction on a second click.

- [ ] **Step 3: Commit**

```bash
git add templates/admin/services/index.twig
git commit -m "feat: add client-side column sorting to admin services table"
```

---

## Task 5: Apply sorting to the Users table

**Files:**
- Modify: `templates/admin/users/index.twig`

**Interfaces:**
- Consumes: same markup contract; simplest case — every sortable column's displayed text is already its raw sortable value, so no `data-sort-value` attributes are needed anywhere in this table.

- [ ] **Step 1: Update the template**

In `templates/admin/users/index.twig`, replace the `<thead>`:

```twig
    <thead>
        <tr>
            <th>{{ t('users.col.id') }}</th>
            <th>{{ t('users.col.email') }}</th>
            <th>{{ t('users.col.role') }}</th>
            <th>{{ t('users.col.created') }}</th>
            <th>{{ t('users.col.actions') }}</th>
        </tr>
    </thead>
```

with:

```twig
    <thead>
        <tr>
            <th><button type="button" class="sort-btn" data-sort-type="number">{{ t('users.col.id') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="text">{{ t('users.col.email') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="text">{{ t('users.col.role') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="text">{{ t('users.col.created') }}</button></th>
            <th>{{ t('users.col.actions') }}</th>
        </tr>
    </thead>
```

Change the `<table>` tag:

```twig
<table class="admin-table">
```
to:
```twig
<table class="admin-table" data-sortable>
```

No `<td>` changes are needed — `u.id`, `u.email`, `u.role`, and `u.created_at` are all already plain, directly-sortable text.

- [ ] **Step 2: Verify manually**

Open `http://localhost:8080/admin/users`:
1. Click "ID", "Email", "Роль" (Role), "Создан" (Created) — confirm each sorts and toggles direction.
2. Confirm the inline password-change form and delete form in the Actions column still work correctly after sorting a couple of times (this column has interactive form inputs, not just links — verify the sort doesn't detach event listeners or break the forms, since `appendChild` on an existing node moves it without destroying its listeners, but this is worth eyeballing given the extra complexity of this column).

- [ ] **Step 3: Commit**

```bash
git add templates/admin/users/index.twig
git commit -m "feat: add client-side column sorting to admin users table"
```

---

## Task 6: Full regression pass

**Files:** none (verification only)

**Interfaces:** none — this task only exercises what Tasks 1–5 already built.

- [ ] **Step 1: Run the full PHPUnit suite**

Confirm nothing in `src/` was touched by this feature and the suite is still green (this change is templates/CSS/JS only, so this should already pass, but the project convention is to run the full suite before considering work done):

```bash
php vendor/bin/phpunit --testdox
```

Expected: all tests pass (no failures related to Products/Categories/Gallery/Services/Users, since no PHP files changed).

- [ ] **Step 2: Click through every affected page once more, end to end**

With `php -S localhost:8080 -t www` running, visit each of:
- `http://localhost:8080/admin/products`
- `http://localhost:8080/admin/categories`
- `http://localhost:8080/admin/gallery`
- `http://localhost:8080/admin/services`
- `http://localhost:8080/admin/users`

On each: sort by two different columns, confirm no JS console errors (open browser dev tools console), confirm row data stays intact (no cells go blank or misaligned), and confirm the page's non-sorting features (add/edit/delete links, bulk actions on Products, password-change on Users) still work.

- [ ] **Step 3: Confirm out-of-scope pages are untouched**

Visit `http://localhost:8080/admin/orders`, `http://localhost:8080/admin/notifications`, `http://localhost:8080/admin/page-views`, and `http://localhost:8080/admin/pages` — confirm their headers are plain text (no sort buttons, no arrows), matching the spec's explicit exclusion of paginated tables and the single-column Pages list.

No commit for this task — it's a verification pass over work already committed in Tasks 1–5.
