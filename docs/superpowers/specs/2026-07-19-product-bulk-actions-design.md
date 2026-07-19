# Product Bulk Actions — Design Spec

Date: 2026-07-19

## Problem

Admins managing many products currently must open each product's edit form
individually to toggle its "Active" checkbox. When a whole batch needs to
change state at once (e.g. taking a set of seasonal products offline), this
is slow and repetitive. The admin products list should let an admin select
several products and activate or deactivate all of them in one action.

## Scope

- Admin-only feature: a checkbox per row on the products list plus an
  action bar with "Activate selected" / "Deactivate selected".
- Exactly two bulk actions: activate and deactivate. No bulk delete, bulk
  category change, or other bulk field edits — those are separate features
  if ever needed.
- Both actions require a `confirm()` dialog before submitting, matching the
  existing confirm-before-delete pattern already used on this page.
- No per-product notification entries are created for a bulk action (see
  "Out of scope").

## Design

### Model — `ProductModel::bulkSetActive(int[] $ids, bool $active, int $userId): int`

1. Filter `$ids` to positive integers and de-duplicate. If the result is
   empty, return `0` immediately — no query is run.
2. Run one `UPDATE products SET is_active = ?, updated_by = ? WHERE id IN
   (...)` with a bound placeholder per id (never string-interpolated).
3. Return the **count of validated ids**, not `PDOStatement::rowCount()`.
   Under PDO's MySQL driver, `rowCount()` on an `UPDATE` only counts rows
   whose value actually *changed* — re-activating an already-active product
   wouldn't be counted, which would make the "N products activated" wording
   look wrong even though every selected row was correctly processed.

### Controller — `ProductController::bulkUpdate()`

- Route: `POST /admin/products/bulk`, registered in `src/routes.php`
  immediately after the `/products/new` routes and before the
  `{id:[0-9]+}` routes — matches this file's existing convention of
  registering static path segments before variable ones.
- Reads `ids[]` (array of numeric strings from the checked checkboxes) and
  `action` (string) from the parsed body.
- `action` must be exactly `activate` or `deactivate`. Any other value
  (a tampered request — the UI never sends anything else) →
  `$response->withStatus(400)`, no exception-based flow control.
- Empty `ids[]` → flash `products.flash.bulk_none_selected` (type
  `error`), redirect to `/admin/products`. This is the server-side
  backstop for the case the UI's disabled-until-checked button is meant to
  prevent — the route must not silently no-op a malformed request.
- Otherwise: `$count = ProductModel::bulkSetActive($ids, $action ===
  'activate', $userId)`, flash `products.flash.bulk_activated` or
  `products.flash.bulk_deactivated` (type `success`, no count
  interpolated — see "Out of scope"), redirect to `/admin/products`.

### UI & translations

- `templates/admin/products/index.twig`:
  - Add a checkbox column: a `<th>` with a "select all" checkbox
    (`aria-label` via `t('products.bulk.select_all')`) and a per-row
    `<input type="checkbox" name="ids[]" value="{{ p.id }}">`.
  - Wrap the whole table in one
    `<form method="POST" action="/admin/products/bulk">` (the existing
    per-row Clone/Delete forms stay as their own separate inline forms —
    HTML forms can't nest, and they already POST to different endpoints).
  - Add an action bar above the table with two buttons, both
    `disabled` until at least one row checkbox is checked (vanilla JS,
    no build step): `name="action" value="activate"` /
    `value="deactivate"`, each with `onclick="return
    confirm(...)"` showing the selected count and using
    `t('products.bulk.confirm_activate')` /
    `t('products.bulk.confirm_deactivate')` (both accept a `{count}`
    placeholder via the existing `I18n::t($key, $params)` support — this
    is JS-side string substitution before the confirm, not a flash
    message, so it doesn't touch the flash mechanism at all).
  - "Select all" toggles every row checkbox; any row checkbox unchecking
    unchecks "select all"; the two action buttons' `disabled` state is
    recomputed on any checkbox change.
- New translation keys, added to all 5 admin files
  (`lang/admin/{cs,en,ru,uk,sk}.json`):
  - `products.bulk.activate` — button label.
  - `products.bulk.deactivate` — button label.
  - `products.bulk.select_all` — header checkbox `aria-label`.
  - `products.bulk.confirm_activate` — confirm text, e.g. "Activate
    {count} products?".
  - `products.bulk.confirm_deactivate` — confirm text, e.g. "Deactivate
    {count} products?".
  - `products.flash.bulk_activated` — success flash, e.g. "Selected
    products activated."
  - `products.flash.bulk_deactivated` — success flash, e.g. "Selected
    products deactivated."
  - `products.flash.bulk_none_selected` — error flash, e.g. "Select at
    least one product."

### Testing

`tests/Unit/Models/ProductModelTest.php`, real Docker MySQL, TDD:

1. `test_bulk_set_active_activates_selected_products` — two fixture
   products with `is_active = 0`; call `bulkSetActive([...], true,
   $userId)`; assert both are now `is_active = 1`.
2. `test_bulk_set_active_deactivates_selected_products` — mirror of the
   above with `active = false`.
3. `test_bulk_set_active_ignores_non_numeric_and_empty_ids` — call with a
   mix of a valid id and non-numeric/empty entries (as would arrive from a
   raw HTTP body); assert only the valid id's product changed and no error
   is thrown.
4. `test_bulk_set_active_returns_zero_for_empty_array` — `bulkSetActive([],
   true, $userId)` returns `0`.
5. `test_bulk_set_active_records_updated_by` — assert `updated_by` on the
   affected rows is set to the acting `$userId`, mirroring the existing
   `test_update_changes_updated_by_but_not_created_by` coverage for single
   edits.

No controller test, per `.claude/rules/unit-testing.md` — the route and UI
(checkbox toggling, disabled-button state, confirm dialogs) are verified
manually via `php -S localhost:8080 -t www`.

## Out of scope

- Bulk delete or any other bulk field edit (category, price, stock) —
  explicitly rejected to keep this change small; each would need its own
  confirmation/safety design, especially delete.
- Per-product `Notifier::notify()` entries for a bulk action — would flood
  the admin notification bell with one entry per affected product for what
  is a single admin action. `updated_by`/`updated_at` on each row already
  carries the audit trail.
- Interpolating a live count into the **flash** message (only the
  client-side confirm dialog gets a count) — the shared
  `AdminBaseController::flash()` / `{{ t(flash.message) }}` rendering in
  `layout/admin-base.twig` has no parameter-passing today, and extending
  that shared mechanism for every admin flash message in the app is out of
  proportion to this feature.
- Pagination-aware "select all across pages" — the admin products list has
  no pagination today, so every visible row is every existing row.
