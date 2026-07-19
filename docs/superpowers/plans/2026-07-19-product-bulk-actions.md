# Product Bulk Actions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin select multiple products on the products list and activate or deactivate all of them in one action, instead of opening each product's edit form individually.

**Architecture:** A new `ProductModel::bulkSetActive(array $ids, bool $active, int $userId): int` runs one `UPDATE ... WHERE id IN (...)` for a validated, de-duplicated id list. A new `POST /admin/products/bulk` route/controller reads `ids[]` + `action` (`activate`|`deactivate`) and calls it. The products list template gets a checkbox per row (+ "select all") and an action bar with two buttons that stay disabled until something is checked; clicking one confirms (with the selected count) then submits via `fetch()` — not a wrapping `<form>`, since the table already contains per-row Clone/Delete forms and HTML forms can't nest.

**Tech Stack:** PHP 8, Slim 4, PDO/MySQL 8 (Docker for local tests), Twig 3, PHPUnit 11, vanilla JS (no build step).

## Global Constraints

- Model methods are static, go through `Database::getConnection()`, use prepared statements only — every id in the `IN (...)` clause is a bound placeholder, never string-interpolated (`.claude/rules/database.md`).
- `bulkSetActive()` returns the count of **validated ids**, not `PDOStatement::rowCount()` (which under PDO/MySQL only counts rows whose value actually changed, undercounting when some selected products already match the target state).
- `action` must be exactly `activate` or `deactivate`; any other value → `$response->withStatus(400)`, no exception-based flow control (`.claude/rules/backend.md`).
- Empty `ids[]` → flash `products.flash.bulk_none_selected` (error) and redirect, not a silent no-op.
- No `Notifier::notify()` calls for bulk actions (would flood the admin notification feed with one entry per affected product) — `updated_by`/`updated_at` on each row already carries the audit trail.
- New route goes inside the existing `$app->group('/admin', ...)` block in `src/routes.php`, registered as a static segment (`/products/bulk`) before the `{id:[0-9]+}` variable routes, matching this file's existing convention (`.claude/rules/backend.md` routing order).
- Flash messages are translation keys, not literal text (`.claude/rules/backend.md`).
- All 5 admin translation files (`lang/admin/{cs,en,ru,uk,sk}.json`) must gain identical new keys, keeping their existing alphabetical key ordering.
- The confirm-dialog count (`{count}`) is substituted client-side in JS, not via the server-side flash mechanism — `AdminBaseController::flash()` / `{{ t(flash.message) }}` in `layout/admin-base.twig` has no parameter support today and this feature does not add any.
- Run `php vendor/bin/phpunit` (whole suite) before every commit — must be fully green.
- Docker MySQL must be running for model tests: `docker compose up -d`.

---

### Task 1: `ProductModel::bulkSetActive()` with tests

**Files:**
- Modify: `src/Models/ProductModel.php:153-178` (add the new method between `update()` and `delete()`, i.e. after line 172, before line 174)
- Test: `tests/Unit/Models/ProductModelTest.php` (add tests immediately before the final closing `}` of the class)

**Interfaces:**
- Consumes: `Database::getConnection(): PDO`.
- Produces: `ProductModel::bulkSetActive(array $ids, bool $active, int $userId): int` — returns the count of validated (positive-integer, de-duplicated) ids it updated; `0` if `$ids` contains no valid id. Task 2 calls this.

- [ ] **Step 1: Ensure Docker MySQL is running**

Run: `docker compose up -d`
Expected: MySQL container up (or already running).

- [ ] **Step 2: Write the failing tests**

Add to `tests/Unit/Models/ProductModelTest.php`, immediately before the final closing `}` of the `ProductModelTest` class:

```php
    public function test_bulk_set_active_activates_selected_products(): void
    {
        $id1 = ProductModel::create([
            'sku' => 'TEST-BULK-' . uniqid(), 'price' => 9.99,
            'category_id' => self::$categoryId, 'is_active' => 0,
        ], self::$userId);
        $id2 = ProductModel::create([
            'sku' => 'TEST-BULK-' . uniqid(), 'price' => 9.99,
            'category_id' => self::$categoryId, 'is_active' => 0,
        ], self::$userId);

        $count = ProductModel::bulkSetActive([$id1, $id2], true, self::$userId);

        $this->assertSame(2, $count);
        $this->assertSame(1, (int) ProductModel::findById($id1)['is_active']);
        $this->assertSame(1, (int) ProductModel::findById($id2)['is_active']);
    }

    public function test_bulk_set_active_deactivates_selected_products(): void
    {
        $id1 = ProductModel::create([
            'sku' => 'TEST-BULK-' . uniqid(), 'price' => 9.99,
            'category_id' => self::$categoryId, 'is_active' => 1,
        ], self::$userId);
        $id2 = ProductModel::create([
            'sku' => 'TEST-BULK-' . uniqid(), 'price' => 9.99,
            'category_id' => self::$categoryId, 'is_active' => 1,
        ], self::$userId);

        $count = ProductModel::bulkSetActive([$id1, $id2], false, self::$userId);

        $this->assertSame(2, $count);
        $this->assertSame(0, (int) ProductModel::findById($id1)['is_active']);
        $this->assertSame(0, (int) ProductModel::findById($id2)['is_active']);
    }

    public function test_bulk_set_active_ignores_non_numeric_and_empty_ids(): void
    {
        $id = ProductModel::create([
            'sku' => 'TEST-BULK-' . uniqid(), 'price' => 9.99,
            'category_id' => self::$categoryId, 'is_active' => 0,
        ], self::$userId);

        $count = ProductModel::bulkSetActive([(string) $id, 'abc', '', '0', '-5'], true, self::$userId);

        $this->assertSame(1, $count);
        $this->assertSame(1, (int) ProductModel::findById($id)['is_active']);
    }

    public function test_bulk_set_active_returns_zero_for_empty_array(): void
    {
        $this->assertSame(0, ProductModel::bulkSetActive([], true, self::$userId));
    }

    public function test_bulk_set_active_records_updated_by(): void
    {
        $id = ProductModel::create([
            'sku' => 'TEST-BULK-' . uniqid(), 'price' => 9.99,
            'category_id' => self::$categoryId,
        ], self::$userId);

        ProductModel::bulkSetActive([$id], false, self::$userId);

        $this->assertSame(self::$userId, (int) ProductModel::findById($id)['updated_by']);
    }
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php --testdox`
Expected: FAIL — `Call to undefined method App\Models\ProductModel::bulkSetActive()`

- [ ] **Step 4: Implement `ProductModel::bulkSetActive()`**

In `src/Models/ProductModel.php`, add this method after `update()` (after line 172), before `delete()` (currently line 174):

```php
    public static function bulkSetActive(array $ids, bool $active, int $userId): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn ($id) => $id > 0
        )));
        if (!$ids) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo          = Database::getConnection();
        $stmt         = $pdo->prepare(
            "UPDATE products SET is_active = ?, updated_by = ? WHERE id IN ($placeholders)"
        );
        $stmt->execute(array_merge([$active ? 1 : 0, $userId], $ids));

        return count($ids);
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php --testdox`
Expected: PASS (all tests, including the 5 new `bulk_set_active` tests)

- [ ] **Step 6: Run the full suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: PASS, no regressions

- [ ] **Step 7: Commit**

```bash
git add src/Models/ProductModel.php tests/Unit/Models/ProductModelTest.php
git commit -m "$(cat <<'EOF'
feat: add ProductModel::bulkSetActive() for bulk activate/deactivate

Runs one UPDATE ... WHERE id IN (...) over a validated, de-duplicated
id list, so the admin can flip is_active on many products in a single
action instead of editing each one individually.
EOF
)"
```

---

### Task 2: Controller action and route

**Files:**
- Modify: `src/routes.php:54` (insert new route after the `/products/new` POST route, before the `{id:[0-9]+}/edit` GET route)
- Modify: `src/Controllers/Admin/ProductController.php:16-20` (add `bulkUpdate()` method immediately after `index()`)

**Interfaces:**
- Consumes: `ProductModel::bulkSetActive(array $ids, bool $active, int $userId): int` (Task 1); `AdminBaseController::flash(string $type, string $key): void`; `AdminBaseController::redirect(Response $response, string $url): Response`.
- Produces: `POST /admin/products/bulk` route handled by `ProductController::bulkUpdate()`. Task 3's template posts to this route.

- [ ] **Step 1: Add the route**

In `src/routes.php`, change:

```php
    $group->get('/products/new',                                          ProductController::class . ':createForm');
    $group->post('/products/new',                                         ProductController::class . ':createSubmit');
    $group->get('/products/{id:[0-9]+}/edit',                             ProductController::class . ':editForm');
```

to:

```php
    $group->get('/products/new',                                          ProductController::class . ':createForm');
    $group->post('/products/new',                                         ProductController::class . ':createSubmit');
    $group->post('/products/bulk',                                        ProductController::class . ':bulkUpdate');
    $group->get('/products/{id:[0-9]+}/edit',                             ProductController::class . ':editForm');
```

- [ ] **Step 2: Add the controller method**

In `src/Controllers/Admin/ProductController.php`, add this method immediately after `index()` (after line 20), before `createForm()`:

```php
    public function bulkUpdate(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $action = $body['action'] ?? '';
        if (!in_array($action, ['activate', 'deactivate'], true)) {
            return $response->withStatus(400);
        }

        $ids = $body['ids'] ?? [];
        if (!is_array($ids) || !$ids) {
            $this->flash('error', 'products.flash.bulk_none_selected');
            return $this->redirect($response, '/admin/products');
        }

        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        ProductModel::bulkSetActive($ids, $action === 'activate', $userId);

        $this->flash('success', $action === 'activate' ? 'products.flash.bulk_activated' : 'products.flash.bulk_deactivated');
        return $this->redirect($response, '/admin/products');
    }
```

- [ ] **Step 3: Lint the changed files**

Run: `php -l src/routes.php && php -l src/Controllers/Admin/ProductController.php`
Expected: `No syntax errors detected` for both files

- [ ] **Step 4: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: PASS, no regressions (this task has no new unit tests — controllers are untested per `.claude/rules/unit-testing.md`; the endpoint is exercised end-to-end in Task 3)

- [ ] **Step 5: Commit**

```bash
git add src/routes.php src/Controllers/Admin/ProductController.php
git commit -m "$(cat <<'EOF'
feat: add POST /admin/products/bulk endpoint

Validates action (activate|deactivate) and a non-empty id list, then
delegates to ProductModel::bulkSetActive(), flashing an error if
nothing was selected.
EOF
)"
```

---

### Task 3: Admin UI and translations

**Files:**
- Modify: `templates/admin/products/index.twig` (add checkbox column, action bar, JS handler)
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json` (add `products.bulk.activate`, `products.bulk.deactivate`, `products.bulk.select_all`, `products.bulk.confirm_activate`, `products.bulk.confirm_deactivate`, `products.flash.bulk_activated`, `products.flash.bulk_deactivated`, `products.flash.bulk_none_selected` — identical line numbers across all 5 files, confirmed below)

**Interfaces:**
- Consumes: `POST /admin/products/bulk` route (Task 2); `t('products.bulk.*')` / `t('products.flash.bulk_*')` via the existing `admin_i18n` Twig `t()` function.
- Produces: nothing consumed by later tasks — this is the final visible piece of the feature.

- [ ] **Step 1: Add translation keys to all 5 admin language files**

In each of `lang/admin/{cs,en,ru,uk,sk}.json`, insert 3 new lines immediately after the existing `"products.audit.updated": ...,` line (line 176), before `"products.clone"` (line 177):

`lang/admin/cs.json`:
```json
  "products.bulk.activate": "Aktivovat vybrané",
  "products.bulk.confirm_activate": "Aktivovat {count} produktů?",
  "products.bulk.confirm_deactivate": "Deaktivovat {count} produktů?",
  "products.bulk.deactivate": "Deaktivovat vybrané",
  "products.bulk.select_all": "Vybrat vše",
```

`lang/admin/en.json`:
```json
  "products.bulk.activate": "Activate selected",
  "products.bulk.confirm_activate": "Activate {count} products?",
  "products.bulk.confirm_deactivate": "Deactivate {count} products?",
  "products.bulk.deactivate": "Deactivate selected",
  "products.bulk.select_all": "Select all",
```

`lang/admin/ru.json`:
```json
  "products.bulk.activate": "Активировать выбранные",
  "products.bulk.confirm_activate": "Активировать {count} товаров?",
  "products.bulk.confirm_deactivate": "Деактивировать {count} товаров?",
  "products.bulk.deactivate": "Деактивировать выбранные",
  "products.bulk.select_all": "Выбрать всё",
```

`lang/admin/uk.json`:
```json
  "products.bulk.activate": "Активувати вибрані",
  "products.bulk.confirm_activate": "Активувати {count} товарів?",
  "products.bulk.confirm_deactivate": "Деактивувати {count} товарів?",
  "products.bulk.deactivate": "Деактивувати вибрані",
  "products.bulk.select_all": "Вибрати все",
```

`lang/admin/sk.json`:
```json
  "products.bulk.activate": "Aktivovať vybrané",
  "products.bulk.confirm_activate": "Aktivovať {count} produktov?",
  "products.bulk.confirm_deactivate": "Deaktivovať {count} produktov?",
  "products.bulk.deactivate": "Deaktivovať vybrané",
  "products.bulk.select_all": "Vybrať všetko",
```

(Keys within the inserted block are already in alphabetical order: `activate` < `confirm_activate` < `confirm_deactivate` < `deactivate` < `select_all`.)

Then, in each of the same 5 files, insert 3 new lines immediately after the existing `"products.edit": ...,` line (line 189), before `"products.flash.cloned"` (line 190):

`lang/admin/cs.json`:
```json
  "products.flash.bulk_activated": "Vybrané produkty aktivovány.",
  "products.flash.bulk_deactivated": "Vybrané produkty deaktivovány.",
  "products.flash.bulk_none_selected": "Vyberte alespoň jeden produkt.",
```

`lang/admin/en.json`:
```json
  "products.flash.bulk_activated": "Selected products activated.",
  "products.flash.bulk_deactivated": "Selected products deactivated.",
  "products.flash.bulk_none_selected": "Select at least one product.",
```

`lang/admin/ru.json`:
```json
  "products.flash.bulk_activated": "Выбранные товары активированы.",
  "products.flash.bulk_deactivated": "Выбранные товары деактивированы.",
  "products.flash.bulk_none_selected": "Выберите хотя бы один товар.",
```

`lang/admin/uk.json`:
```json
  "products.flash.bulk_activated": "Вибрані товари активовано.",
  "products.flash.bulk_deactivated": "Вибрані товари деактивовано.",
  "products.flash.bulk_none_selected": "Виберіть принаймні один товар.",
```

`lang/admin/sk.json`:
```json
  "products.flash.bulk_activated": "Vybrané produkty aktivované.",
  "products.flash.bulk_deactivated": "Vybrané produkty deaktivované.",
  "products.flash.bulk_none_selected": "Vyberte aspoň jeden produkt.",
```

- [ ] **Step 2: Validate JSON and identical key sets across all 5 files**

Run:
```bash
for f in cs en ru uk sk; do php -r "json_decode(file_get_contents('lang/admin/$f.json'), true) !== null || exit(1);" && echo "$f: valid JSON" || echo "$f: INVALID"; done
php -r '
$langs = ["cs","en","ru","uk","sk"];
$keysets = [];
foreach ($langs as $l) { $keysets[$l] = array_keys(json_decode(file_get_contents("lang/admin/$l.json"), true)); }
$base = $keysets["cs"];
foreach ($langs as $l) {
    $diff = array_merge(array_diff($base, $keysets[$l]), array_diff($keysets[$l], $base));
    echo $l . ": " . (empty($diff) ? "OK" : "MISMATCH " . implode(",", $diff)) . PHP_EOL;
}
'
```
Expected: `valid JSON` for all 5, and `OK` for all 5 (no mismatch)

- [ ] **Step 3: Add the checkbox column, action bar, and JS to the products list template**

Replace the full contents of `templates/admin/products/index.twig` with:

```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ t('products.title') }}{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>{{ t('products.title') }}</h1>
    <a href="/admin/products/new" class="btn btn-primary">{{ t('products.add') }}</a>
</div>
<div style="margin:0 0 1rem;display:flex;gap:0.5rem;">
    <button type="button" class="btn btn-secondary" id="bulk-activate-btn" disabled
            data-action="activate" data-confirm="{{ t('products.bulk.confirm_activate') }}">{{ t('products.bulk.activate') }}</button>
    <button type="button" class="btn btn-secondary" id="bulk-deactivate-btn" disabled
            data-action="deactivate" data-confirm="{{ t('products.bulk.confirm_deactivate') }}">{{ t('products.bulk.deactivate') }}</button>
</div>
<table class="admin-table">
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
    <tbody>
    {% for p in products %}
    <tr>
        <td><input type="checkbox" class="row-checkbox" value="{{ p.id }}"></td>
        <td>
            {% if p.primary_image %}
            <img src="/assets/uploads/products/thumb_{{ p.primary_image }}" class="img-thumb">
            {% endif %}
        </td>
        <td>{{ p.name }}</td>
        <td>{{ p.category_name ?? '—' }}</td>
        <td>{{ p.price|number_format(2, '.', ' ') }} Kč</td>
        <td>{{ p.stock_type == 'limited' ? p.stock_qty ~ ' ks' : '—' }}</td>
        <td>{{ p.is_active ? '✓' : '—' }}</td>
        <td class="audit-meta">{{ p.updated_by_email ?? t('common.audit.unknown_user') }}<br>{{ p.updated_at }}</td>
        <td>
            <a href="/admin/products/{{ p.id }}/edit">{{ t('products.edit') }}</a> |
            <form method="POST" action="/admin/products/{{ p.id }}/clone" style="display:inline">
                <button class="btn-link">{{ t('products.clone') }}</button>
            </form> |
            <form method="POST" action="/admin/products/{{ p.id }}/delete" style="display:inline" onsubmit="return confirm('{{ t('products.confirm_delete') }}')">
                <button class="btn-link">{{ t('products.delete') }}</button>
            </form>
        </td>
    </tr>
    {% else %}
    <tr><td colspan="9">{{ t('products.no_products') }}</td></tr>
    {% endfor %}
    </tbody>
</table>
{% endblock %}
{% block scripts %}
<script>
const rowCheckboxes     = document.querySelectorAll('.row-checkbox');
const selectAllCheckbox = document.getElementById('select-all-checkbox');
const activateBtn       = document.getElementById('bulk-activate-btn');
const deactivateBtn     = document.getElementById('bulk-deactivate-btn');

function checkedIds() {
    return Array.from(rowCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
}

function refreshButtons() {
    const hasSelection = checkedIds().length > 0;
    activateBtn.disabled = !hasSelection;
    deactivateBtn.disabled = !hasSelection;
}

rowCheckboxes.forEach(cb => cb.addEventListener('change', () => {
    if (!cb.checked) selectAllCheckbox.checked = false;
    refreshButtons();
}));

selectAllCheckbox.addEventListener('change', () => {
    rowCheckboxes.forEach(cb => { cb.checked = selectAllCheckbox.checked; });
    refreshButtons();
});

// Bulk buttons — fetch(), not a wrapping <form> (the table already has
// per-row Clone/Delete forms; forms can't nest in valid HTML).
[activateBtn, deactivateBtn].forEach(btn => {
    btn.addEventListener('click', async () => {
        const ids = checkedIds();
        if (!ids.length) return;
        const message = btn.dataset.confirm.replace('{count}', ids.length);
        if (!confirm(message)) return;

        const body = new URLSearchParams();
        body.set('action', btn.dataset.action);
        ids.forEach(id => body.append('ids[]', id));

        await fetch('/admin/products/bulk', { method: 'POST', body });
        location.reload();
    });
});
</script>
{% endblock %}
```

- [ ] **Step 4: Manually verify the full flow in a browser**

Run:
```bash
docker compose up -d
php -S localhost:8080 -t www &
```

Then:
1. Open `http://localhost:8080/admin/login`, log in with an admin account (create one via `/admin/setup` first if needed).
2. Go to `http://localhost:8080/admin/products`.
3. Confirm both bulk buttons render disabled with no checkboxes checked.
4. Check one row's checkbox — confirm both buttons become enabled.
5. Click "Select all" in the header — confirm every row checkbox checks and buttons stay enabled; uncheck one row — confirm "select all" unchecks itself while buttons remain enabled (other rows still checked).
6. With 2+ products checked, click "Deactivate selected" — confirm the browser's confirm dialog shows the correct count, accept it, and confirm the page reloads with a green flash message and the checked products now show "—" in the Active column.
7. Repeat with "Activate selected" on the same rows — confirm they show "✓" again.
8. Uncheck everything and confirm both buttons go back to disabled.

Stop the server afterward:
```bash
kill %1
```

- [ ] **Step 5: Run the full test suite one more time**

Run: `php vendor/bin/phpunit --testdox`
Expected: PASS, no regressions

- [ ] **Step 6: Commit**

```bash
git add templates/admin/products/index.twig lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/uk.json lang/admin/sk.json
git commit -m "$(cat <<'EOF'
feat: add bulk activate/deactivate UI to admin products list

Adds per-row and "select all" checkboxes plus a two-button action bar
(disabled until something is checked) that confirms with the selected
count and posts to the bulk endpoint via fetch(), completing the bulk
actions feature.
EOF
)"
```

---

## Self-Review Notes

- **Spec coverage:** model-layer id validation + bulk UPDATE (Task 1, matches spec's "Model" section point-for-point); route/controller with 400-on-bad-action and empty-selection guard (Task 2, matches spec's "Controller" section); checkbox UI, disabled-button state, confirm dialogs with `{count}`, translations, manual verification (Task 3, matches spec's "UI & translations" section). The spec's "Out of scope" items (bulk delete, per-product notifications, server-side flash count interpolation, pagination-aware select-all) have no corresponding task, as intended.
- **Placeholder scan:** no TBD/TODO; every step has complete, runnable code.
- **Type consistency:** `ProductModel::bulkSetActive(array $ids, bool $active, int $userId): int` signature is identical across Task 1 (definition) and Task 2 (consumption). The JS `data-action` values (`activate`/`deactivate`) sent as the `action` form field in Task 3 exactly match the strings `ProductController::bulkUpdate()` checks in Task 2's `in_array($action, ['activate', 'deactivate'], true)`.
- **Nested-form constraint verified:** Task 3 deliberately does not wrap the table in a `<form>` — row checkboxes are plain inputs read by JS, and the bulk buttons submit via `fetch()`, avoiding the same nested-form problem already solved for delete-image/split-image buttons on the edit form.
