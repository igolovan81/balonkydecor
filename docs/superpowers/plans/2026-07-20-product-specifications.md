# Product Specifications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins attach an ordered list of attribute name/value rows to a product, shown on the public product page below the description, with two special rendering conventions: a blank value renders as a full-width section header, and a hex-color value renders as a swatch.

**Architecture:** Two new tables (`product_specs` + `product_spec_t`) mirror the existing `product_subtypes` + `product_subtype_t` pattern exactly — same admin add/remove-row UI, same MyMemory auto-translate flow, same delete-and-reinsert save semantics. `ProductModel` gains `getSpecs`/`setSpecs`; `ProductController` gains `buildSpecs()`; the public product template gains a new section with two rendering branches (header row / swatch / plain text) driven purely by the stored `attribute_value` — no extra "row type" column.

**Tech Stack:** Slim 4, Twig 3, PHP 8, PDO/MySQL, PHPUnit 11, vanilla CSS/JS (no build step).

## Global Constraints

- Notice/legal text varies per product — always admin-typed per row, never a shared/global field (spec: Constraints).
- Blank `attribute_value` → full-width header row; hex-color `attribute_value` (strict regex `^#[0-9a-fA-F]{3}$` / `^#[0-9a-fA-F]{6}$`) → swatch, no visible text; anything else → plain escaped text (spec: Architecture & data model).
- New DB objects follow `.claude/rules/database.md`: `V0NN__snake_case.sql` migration, translation table uses `lang_code` (never `lang`), prepared statements only.
- New admin/public routes, controllers, and rendering follow `.claude/rules/backend.md` / `.claude/rules/frontend.md` conventions already used by subtypes (POST-redirect-GET, `t()` translation keys added to **all five** language files per surface).
- Model tests run against real Docker MySQL, no mocks (`.claude/rules/unit-testing.md`).
- CSS: reuse design tokens from `:root`, flat kebab-case classes, responsive rules next to the component they modify (`.claude/rules/css-styling.md`).

---

### Task 1: Migration

**Files:**
- Create: `database/migrations/V022__product_specs.sql`

**Interfaces:**
- Produces: tables `product_specs (id, product_id, sort_order)` and `product_spec_t (id, spec_id, lang_code, attribute_name, attribute_value)`, consumed by Task 2's `ProductModel` methods.

- [ ] **Step 1: Create the migration file**

```sql
CREATE TABLE `product_specs` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `product_spec_t` (
  `id`               int NOT NULL AUTO_INCREMENT,
  `spec_id`          int NOT NULL,
  `lang_code`        varchar(5) NOT NULL,
  `attribute_name`   varchar(255) NOT NULL,
  `attribute_value`  text NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spec_lang` (`spec_id`,`lang_code`),
  FOREIGN KEY (`spec_id`) REFERENCES `product_specs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Ensure the local server is running, then apply the migration**

```bash
docker compose up -d
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/cs/
```

If that doesn't print `200`, start the server in the background:

```bash
php -S localhost:8080 -t www
```

Then apply pending migrations:

```bash
MIGRATE_TOKEN=$(php -r "echo (require 'config/settings.php')['migrate_token'];")
curl -s "http://localhost:8080/migrate.php?token=${MIGRATE_TOKEN}" | python3 -m json.tool
```

Expected: `{"applied": ["V022__product_specs"], "count": 1}` (or `count` includes any other
already-pending migrations if some existed before this task — as long as `V022__product_specs`
appears in `applied` and there's no `"error"` key).

- [ ] **Step 3: Verify the schema**

```bash
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e "DESCRIBE product_specs; DESCRIBE product_spec_t;"
```

Expected: both tables listed with the columns from Step 1, no error.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/V022__product_specs.sql
git commit -m "feat: add product_specs schema"
```

---

### Task 2: ProductModel — getSpecs/setSpecs and wiring

**Files:**
- Modify: `src/Models/ProductModel.php` (`findBySku`, `findById`, `clone`, plus two new methods)
- Test: `tests/Unit/Models/ProductModelTest.php`

**Interfaces:**
- Consumes: `product_specs` / `product_spec_t` tables (Task 1).
- Produces (consumed by Task 3's `ProductController::buildSpecs` and Task 4's public template):
  - `ProductModel::getSpecs(int $productId): array` — rows shaped
    `['id' => int, 'sort_order' => int, 't' => ['cs' => ['name' => string, 'value' => string], ...]]`.
  - `ProductModel::setSpecs(int $productId, array $rows): void` — `$rows` shaped like
    `getSpecs()`'s `t`-per-row output (i.e. `[['t' => ['cs' => ['name' => ..., 'value' => ...], ...]], ...]`);
    delete-and-reinsert; skips a row entirely if its name is empty in every language.
  - `$product['specs']` on both `findById()` (admin, nested `t[lang]` shape) and
    `findBySku()` (public, flat `['id', 'attribute_name', 'attribute_value']` shape resolved
    for the requested `$lang`, empty array when none).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Models/ProductModelTest.php`, right after `test_find_by_sku_subtypes_empty_without_any` (around line 135):

```php
    public function test_find_by_sku_resolves_spec_rows_for_requested_lang(): void
    {
        $productId = $this->makeProduct();
        $sku       = $this->skuOf($productId);
        ProductModel::setSpecs($productId, [
            ['t' => [
                'cs' => ['name' => 'Materiál', 'value' => 'Latex'],
                'en' => ['name' => 'Material', 'value' => 'Latex'],
            ]],
        ]);

        $product = ProductModel::findBySku($sku, 'en');
        $this->assertCount(1, $product['specs']);
        $this->assertSame('Material', $product['specs'][0]['attribute_name']);
        $this->assertSame('Latex', $product['specs'][0]['attribute_value']);
    }

    public function test_find_by_sku_specs_empty_without_any(): void
    {
        $product = ProductModel::findBySku('TEST-SKU-001', 'en');
        $this->assertSame([], $product['specs']);
    }
```

Add right after `test_set_subtypes_skips_rows_with_no_names` (around line 229):

```php
    public function test_set_specs_creates_and_returns_translated_rows(): void
    {
        $productId = $this->makeProduct();
        ProductModel::setSpecs($productId, [
            ['t' => [
                'cs' => ['name' => 'Materiál', 'value' => 'Latex'],
                'en' => ['name' => 'Material', 'value' => 'Latex'],
            ]],
            ['t' => [
                'cs' => ['name' => 'Velikost', 'value' => '30 cm'],
                'en' => ['name' => 'Size', 'value' => '30 cm'],
            ]],
        ]);

        $product = ProductModel::findById($productId);
        $this->assertCount(2, $product['specs']);
        $this->assertSame('Materiál', $product['specs'][0]['t']['cs']['name']);
        $this->assertSame('Latex', $product['specs'][0]['t']['cs']['value']);
        $this->assertSame('Size', $product['specs'][1]['t']['en']['name']);
    }

    public function test_set_specs_replaces_existing_rows(): void
    {
        $productId = $this->makeProduct();
        ProductModel::setSpecs($productId, [
            ['t' => ['cs' => ['name' => 'A', 'value' => '1']]],
        ]);
        ProductModel::setSpecs($productId, [
            ['t' => ['cs' => ['name' => 'B', 'value' => '2']]],
        ]);

        $specs = ProductModel::getSpecs($productId);
        $this->assertCount(1, $specs);
        $this->assertSame('B', $specs[0]['t']['cs']['name']);
    }

    public function test_set_specs_skips_rows_with_no_names(): void
    {
        $productId = $this->makeProduct();
        ProductModel::setSpecs($productId, [
            ['t' => ['cs' => ['name' => '', 'value' => 'orphan value']]],
            ['t' => ['cs' => ['name' => 'Valid', 'value' => 'x']]],
        ]);

        $specs = ProductModel::getSpecs($productId);
        $this->assertCount(1, $specs);
        $this->assertSame('Valid', $specs[0]['t']['cs']['name']);
    }

    public function test_set_specs_allows_blank_value_for_header_rows(): void
    {
        $productId = $this->makeProduct();
        ProductModel::setSpecs($productId, [
            ['t' => ['cs' => ['name' => 'Upozornění', 'value' => '']]],
        ]);

        $specs = ProductModel::getSpecs($productId);
        $this->assertCount(1, $specs);
        $this->assertSame('Upozornění', $specs[0]['t']['cs']['name']);
        $this->assertSame('', $specs[0]['t']['cs']['value']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php
```

Expected: FAIL / ERROR — `Call to undefined method App\Models\ProductModel::setSpecs()`
(and `getSpecs`).

- [ ] **Step 3: Add `getSpecs`/`setSpecs` to `ProductModel`**

In `src/Models/ProductModel.php`, insert immediately after `setSubtypes()` (after the
closing `}` at line 271, before `public static function addImage(...)`):

```php
    public static function getSpecs(int $productId): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, sort_order FROM product_specs WHERE product_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$productId]);
        $rows = $stmt->fetchAll();

        $tStmt = $pdo->prepare(
            'SELECT lang_code, attribute_name, attribute_value FROM product_spec_t WHERE spec_id = ?'
        );
        foreach ($rows as &$row) {
            $tStmt->execute([$row['id']]);
            $row['t'] = [];
            foreach ($tStmt->fetchAll() as $t) {
                $row['t'][$t['lang_code']] = [
                    'name'  => $t['attribute_name'],
                    'value' => $t['attribute_value'],
                ];
            }
        }
        unset($row);
        return $rows;
    }

    public static function setSpecs(int $productId, array $rows): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM product_specs WHERE product_id = ?')->execute([$productId]);

        $insertSpec = $pdo->prepare(
            'INSERT INTO product_specs (product_id, sort_order) VALUES (?, ?)'
        );
        $insertT = $pdo->prepare(
            'INSERT INTO product_spec_t (spec_id, lang_code, attribute_name, attribute_value) VALUES (?, ?, ?, ?)'
        );

        foreach (array_values($rows) as $index => $row) {
            $t = array_filter(
                $row['t'] ?? [],
                fn ($fields) => trim((string) ($fields['name'] ?? '')) !== ''
            );
            if (!$t) continue;

            $insertSpec->execute([$productId, $index]);
            $specId = (int) $pdo->lastInsertId();

            foreach ($t as $lang => $fields) {
                $insertT->execute([
                    $specId,
                    $lang,
                    trim((string) ($fields['name'] ?? '')),
                    trim((string) ($fields['value'] ?? '')),
                ]);
            }
        }
    }
```

- [ ] **Step 4: Wire specs into `findBySku()`**

In `findBySku()`, insert right after the subtypes block and before `return $product;`
(after `$product['subtypes'] = $subStmt->fetchAll();`):

```php
        $specStmt = $pdo->prepare(
            'SELECT ps.id, pt.attribute_name, pt.attribute_value
             FROM product_specs ps
             JOIN product_spec_t pt ON pt.spec_id = ps.id AND pt.lang_code = ?
             WHERE ps.product_id = ?
             ORDER BY ps.sort_order, ps.id'
        );
        $specStmt->execute([$lang, $product['id']]);
        $product['specs'] = $specStmt->fetchAll();
```

- [ ] **Step 5: Wire specs into `findById()`**

In `findById()`, change:

```php
        $product['subtypes'] = self::getSubtypes($id);
        return $product;
```

to:

```php
        $product['subtypes'] = self::getSubtypes($id);
        $product['specs']    = self::getSpecs($id);
        return $product;
```

- [ ] **Step 6: Wire specs into `clone()`**

In `clone()`, change:

```php
            if ($source['subtypes']) {
                self::setSubtypes($newId, $source['subtypes']);
            }
```

to:

```php
            if ($source['subtypes']) {
                self::setSubtypes($newId, $source['subtypes']);
            }
            if ($source['specs']) {
                self::setSpecs($newId, $source['specs']);
            }
```

- [ ] **Step 7: Run tests to verify they pass**

```bash
php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php --testdox
```

Expected: all pass, including the 7 new spec tests.

- [ ] **Step 8: Run the full suite**

```bash
php vendor/bin/phpunit
```

Expected: all green, no regressions.

- [ ] **Step 9: Commit**

```bash
git add src/Models/ProductModel.php tests/Unit/Models/ProductModelTest.php
git commit -m "feat: add ProductModel::getSpecs/setSpecs and wire into findById/findBySku/clone"
```

---

### Task 3: Admin — edit form, controller wiring, admin translations

**Files:**
- Modify: `src/Controllers/Admin/ProductController.php` (`createSubmit`, `editSubmit`, plus new `buildSpecs()`)
- Modify: `templates/admin/products/form.twig` (new specs section + JS)
- Modify: `www/assets/css/admin.css` (`.spec-row` styling)
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`

**Interfaces:**
- Consumes: `ProductModel::setSpecs()` (Task 2), `Translator::autoFill()` (existing, same
  signature already used by `buildSubtypes()`).
- Produces: admin product create/edit forms persist `specs[]` rows; consumed visually by
  Task 4's public template only through the DB, not directly.

- [ ] **Step 1: Add `buildSpecs()` and wire it into both submit handlers**

In `src/Controllers/Admin/ProductController.php`, change `createSubmit()`'s:

```php
        ProductModel::setSubtypes($id, $this->buildSubtypes(
            $body['subtypes'] ?? [], $request->getAttribute('admin_lang', 'cs')
        ));
        $this->handleImageUpload($request, $id, true);
```

to:

```php
        ProductModel::setSubtypes($id, $this->buildSubtypes(
            $body['subtypes'] ?? [], $request->getAttribute('admin_lang', 'cs')
        ));
        ProductModel::setSpecs($id, $this->buildSpecs(
            $body['specs'] ?? [], $request->getAttribute('admin_lang', 'cs')
        ));
        $this->handleImageUpload($request, $id, true);
```

Change `editSubmit()`'s:

```php
        ProductModel::setSubtypes($id, $this->buildSubtypes(
            $body['subtypes'] ?? [], $request->getAttribute('admin_lang', 'cs')
        ));
        $this->handleImageUpload($request, $id, false);
```

to:

```php
        ProductModel::setSubtypes($id, $this->buildSubtypes(
            $body['subtypes'] ?? [], $request->getAttribute('admin_lang', 'cs')
        ));
        ProductModel::setSpecs($id, $this->buildSpecs(
            $body['specs'] ?? [], $request->getAttribute('admin_lang', 'cs')
        ));
        $this->handleImageUpload($request, $id, false);
```

Add the private method right after `buildSubtypes()` (after its closing `}`, before the
class's final closing `}`):

```php
    private function buildSpecs(array $rows, string $adminLang): array
    {
        $specs = [];
        foreach ($rows as $row) {
            $name = trim($row['name'] ?? '');
            if ($name === '') continue;

            $t = \App\Services\Translator::autoFill(
                [$adminLang => ['name' => $name, 'value' => trim($row['value'] ?? '')]],
                $adminLang, self::LANGS, ['name', 'value']
            );
            $specs[] = ['t' => $t];
        }
        return $specs;
    }
```

- [ ] **Step 2: Add the specs section to the admin form template**

In `templates/admin/products/form.twig`, insert right after the subtypes `<template>`
block (after the `</template>` that closes `subtype-row-template`, before the closing
`</div>` of `.product-form-main`):

```twig

            <h3>{{ t('products.form.specs') }}</h3>
            <div id="spec-rows">
                {% for spec in product.specs ?? [] %}
                <div class="spec-row">
                    <input type="text" name="specs[{{ loop.index0 }}][name]" value="{{ spec.t[admin_lang].name ?? '' }}" placeholder="{{ t('products.form.spec_name_label') }}">
                    <input type="text" name="specs[{{ loop.index0 }}][value]" value="{{ spec.t[admin_lang].value ?? '' }}" placeholder="{{ t('products.form.spec_value_label') }}">
                    <button type="button" class="btn-link spec-remove-btn">{{ t('products.form.spec_remove') }}</button>
                </div>
                {% endfor %}
            </div>
            <button type="button" id="spec-add-btn" class="btn btn-secondary">{{ t('products.form.spec_add') }}</button>

            <template id="spec-row-template">
                <div class="spec-row">
                    <input type="text" name="specs[__INDEX__][name]" placeholder="{{ t('products.form.spec_name_label') }}">
                    <input type="text" name="specs[__INDEX__][value]" placeholder="{{ t('products.form.spec_value_label') }}">
                    <button type="button" class="btn-link spec-remove-btn">{{ t('products.form.spec_remove') }}</button>
                </div>
            </template>
```

- [ ] **Step 3: Add the add/remove JS, mirroring the subtype-rows block**

In the same file's `{% block scripts %}`, insert right after the subtype-rows IIFE
(after its closing `})();`, before the `// Stock quantity field toggle` comment):

```javascript

// Spec rows — add/remove
(function () {
    const container = document.getElementById('spec-rows');
    const addBtn    = document.getElementById('spec-add-btn');
    const template  = document.getElementById('spec-row-template');
    if (!container || !addBtn || !template) return;

    let nextIndex = container.querySelectorAll('.spec-row').length;

    function bindRemove(row) {
        row.querySelector('.spec-remove-btn').addEventListener('click', () => {
            row.remove();
        });
    }

    container.querySelectorAll('.spec-row').forEach(bindRemove);

    addBtn.addEventListener('click', () => {
        const html    = template.innerHTML.replace(/__INDEX__/g, nextIndex);
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();
        const row = wrapper.firstElementChild;
        container.appendChild(row);
        bindRemove(row);
        nextIndex++;
    });
})();
```

- [ ] **Step 4: Add `.spec-row` CSS**

In `www/assets/css/admin.css`, change:

```css
.subtype-row { display:flex; gap:0.75rem; align-items:center; margin-bottom:0.5rem; }
.subtype-row input[type="text"] { flex:2; }
.subtype-row input[type="number"] { flex:1; }
```

to:

```css
.subtype-row { display:flex; gap:0.75rem; align-items:center; margin-bottom:0.5rem; }
.subtype-row input[type="text"] { flex:2; }
.subtype-row input[type="number"] { flex:1; }
.spec-row { display:flex; gap:0.75rem; align-items:center; margin-bottom:0.5rem; }
.spec-row input[type="text"] { flex:1; }
```

- [ ] **Step 5: Add admin translation keys to all five `lang/admin/*.json` files**

Each file is alphabetically sorted within the `products.form.*` group. Insert the five
new keys after `products.form.sku_hint` and before `products.form.split_image`.

In `lang/admin/cs.json`:

```json
  "products.form.spec_add": "+ Přidat řádek",
  "products.form.spec_name_label": "Název atributu",
  "products.form.spec_remove": "Odebrat",
  "products.form.spec_value_label": "Hodnota atributu",
  "products.form.specs": "Specifikace produktů",
```

In `lang/admin/en.json`:

```json
  "products.form.spec_add": "+ Add row",
  "products.form.spec_name_label": "Attribute name",
  "products.form.spec_remove": "Remove",
  "products.form.spec_value_label": "Attribute value",
  "products.form.specs": "Product specifications",
```

In `lang/admin/ru.json`:

```json
  "products.form.spec_add": "+ Добавить строку",
  "products.form.spec_name_label": "Название атрибута",
  "products.form.spec_remove": "Удалить",
  "products.form.spec_value_label": "Значение атрибута",
  "products.form.specs": "Характеристики товара",
```

In `lang/admin/uk.json`:

```json
  "products.form.spec_add": "+ Додати рядок",
  "products.form.spec_name_label": "Назва атрибута",
  "products.form.spec_remove": "Видалити",
  "products.form.spec_value_label": "Значення атрибута",
  "products.form.specs": "Характеристики товару",
```

In `lang/admin/sk.json`:

```json
  "products.form.spec_add": "+ Pridať riadok",
  "products.form.spec_name_label": "Názov atribútu",
  "products.form.spec_remove": "Odobrať",
  "products.form.spec_value_label": "Hodnota atribútu",
  "products.form.specs": "Špecifikácie produktu",
```

- [ ] **Step 6: Verify all five admin files still parse and have identical key sets**

```bash
php -r '
$files = ["cs","en","ru","uk","sk"];
$keys = null;
foreach ($files as $f) {
    $data = json_decode(file_get_contents("lang/admin/$f.json"), true);
    if ($data === null) { echo "INVALID JSON: $f\n"; exit(1); }
    $k = array_keys($data);
    sort($k);
    if ($keys === null) { $keys = $k; $ref = $f; continue; }
    if ($k !== $keys) {
        echo "KEY MISMATCH between $ref and $f\n";
        echo "Only in $ref: " . implode(", ", array_diff($keys, $k)) . "\n";
        echo "Only in $f: " . implode(", ", array_diff($k, $keys)) . "\n";
        exit(1);
    }
}
echo "OK: all " . count($keys) . " keys match across " . count($files) . " files\n";
'
```

Expected: `OK: all N keys match across 5 files` with no mismatch reported.

- [ ] **Step 7: Verify the admin flow end-to-end via a throwaway fixture admin user**

The dev DB has real admin accounts whose passwords aren't available to script a login,
so create a disposable verification-only admin fixture (same `INSERT IGNORE` + unique
email convention already used by other model test fixtures):

```bash
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e "
INSERT IGNORE INTO users (email, password_hash, role)
VALUES ('specs-verify-test@example.com', '$(php -r "echo password_hash('verify-pass-123', PASSWORD_DEFAULT);")', 'admin');
"
```

Ensure the local server is running (see Task 1, Step 2 if not), then log in and create
a product exercising all three spec-row behaviors (plain row, hex-color row, blank-value
header row):

```bash
rm -f /tmp/specs-verify-cookies.txt
curl -s -c /tmp/specs-verify-cookies.txt -b /tmp/specs-verify-cookies.txt \
  -X POST -d 'email=specs-verify-test@example.com&password=verify-pass-123' \
  http://localhost:8080/admin/login -o /dev/null -w '%{http_code}\n'

curl -s -b /tmp/specs-verify-cookies.txt http://localhost:8080/admin/products/new \
  | grep -o 'spec-row-template\|spec-add-btn' | sort -u

CAT_ID=$(docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -N -e "SELECT id FROM categories LIMIT 1;" | tr -d '\r')

curl -s -b /tmp/specs-verify-cookies.txt -c /tmp/specs-verify-cookies.txt \
  -X POST http://localhost:8080/admin/products/new \
  --data-urlencode "sku=SPECS-VERIFY-001" \
  --data-urlencode "price=19.90" \
  --data-urlencode "category_id=${CAT_ID}" \
  --data-urlencode "is_active=1" \
  --data-urlencode "stock_type=unlimited" \
  --data-urlencode "t[cs][name]=Testovaci specifikace" \
  --data-urlencode "specs[0][name]=Material" \
  --data-urlencode "specs[0][value]=Latex" \
  --data-urlencode "specs[1][name]=Barva" \
  --data-urlencode "specs[1][value]=#2f7fb8" \
  --data-urlencode "specs[2][name]=Notice" \
  --data-urlencode "specs[2][value]=" \
  -o /dev/null -w '%{http_code} %{redirect_url}\n'
```

Expected: login returns `302`; the `new` form grep prints both markers; the create POST
returns `302` to `/admin/products`.

Find the created product and confirm the edit form round-trips the saved rows:

```bash
PID=$(docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -N -e "SELECT id FROM products WHERE sku='SPECS-VERIFY-001';" | tr -d '\r')
curl -s -b /tmp/specs-verify-cookies.txt "http://localhost:8080/admin/products/${PID}/edit" \
  | grep -o 'value="Material"\|value="Latex"\|value="#2f7fb8"'
```

Expected: all three values present in the rendered form (the `Notice` row's blank
value naturally produces no `value="..."` attribute to grep for — that's expected, not
a failure).

- [ ] **Step 8: Run the full test suite (no PHP unit tests changed in this task, confirming no regressions)**

```bash
php vendor/bin/phpunit
```

Expected: all green.

- [ ] **Step 9: Commit**

```bash
git add src/Controllers/Admin/ProductController.php templates/admin/products/form.twig www/assets/css/admin.css lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/uk.json lang/admin/sk.json
git commit -m "feat: add product specifications admin editing UI"
```

---

### Task 4: Public rendering, translations, and CSS

**Files:**
- Modify: `templates/public/shop/product.twig`
- Modify: `www/assets/css/style.css`
- Modify: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`

**Interfaces:**
- Consumes: `product.specs` (flat `attribute_name`/`attribute_value` per row, Task 2),
  translation keys `shop.specs_title`, `shop.specs_attribute_name`,
  `shop.specs_attribute_value` (this task).

- [ ] **Step 1: Add public translation keys to all five `lang/*.json` files**

Each file is alphabetically sorted. Insert the three new `shop.specs_*` keys after
`shop.qty` and before `shop.subtype`.

In `lang/cs.json`, change:

```json
  "shop.qty": "Množství",
  "shop.subtype": "Varianta",
```

to:

```json
  "shop.qty": "Množství",
  "shop.specs_attribute_name": "Název atributu",
  "shop.specs_attribute_value": "Hodnota atributu",
  "shop.specs_title": "Specifikace produktů",
  "shop.subtype": "Varianta",
```

In `lang/en.json`, change:

```json
  "shop.qty": "Quantity",
  "shop.subtype": "Variant",
```

to:

```json
  "shop.qty": "Quantity",
  "shop.specs_attribute_name": "Attribute name",
  "shop.specs_attribute_value": "Attribute value",
  "shop.specs_title": "Products specifications",
  "shop.subtype": "Variant",
```

In `lang/ru.json`, change:

```json
  "shop.qty": "Количество",
  "shop.subtype": "Вариант",
```

to:

```json
  "shop.qty": "Количество",
  "shop.specs_attribute_name": "Название атрибута",
  "shop.specs_attribute_value": "Значение атрибута",
  "shop.specs_title": "Характеристики товара",
  "shop.subtype": "Вариант",
```

In `lang/uk.json`, change:

```json
  "shop.qty": "Кількість",
  "shop.subtype": "Варіант",
```

to:

```json
  "shop.qty": "Кількість",
  "shop.specs_attribute_name": "Назва атрибута",
  "shop.specs_attribute_value": "Значення атрибута",
  "shop.specs_title": "Характеристики товару",
  "shop.subtype": "Варіант",
```

In `lang/sk.json`, change:

```json
  "shop.qty": "Množstvo",
  "shop.subtype": "Variant",
```

to:

```json
  "shop.qty": "Množstvo",
  "shop.specs_attribute_name": "Názov atribútu",
  "shop.specs_attribute_value": "Hodnota atribútu",
  "shop.specs_title": "Špecifikácie produktu",
  "shop.subtype": "Variant",
```

- [ ] **Step 2: Verify all five public files still parse and have identical key sets**

```bash
php -r '
$files = ["cs","en","ru","uk","sk"];
$keys = null;
foreach ($files as $f) {
    $data = json_decode(file_get_contents("lang/$f.json"), true);
    if ($data === null) { echo "INVALID JSON: $f\n"; exit(1); }
    $k = array_keys($data);
    sort($k);
    if ($keys === null) { $keys = $k; $ref = $f; continue; }
    if ($k !== $keys) {
        echo "KEY MISMATCH between $ref and $f\n";
        echo "Only in $ref: " . implode(", ", array_diff($keys, $k)) . "\n";
        echo "Only in $f: " . implode(", ", array_diff($k, $keys)) . "\n";
        exit(1);
    }
}
echo "OK: all " . count($keys) . " keys match across " . count($files) . " files\n";
'
```

Expected: `OK: all N keys match across 5 files` with no mismatch reported.

- [ ] **Step 3: Add the specs section to `templates/public/shop/product.twig`**

Change the closing of the file's main container:

```twig
            <input type="hidden" name="sku" value="{{ product.sku }}">
            <button type="submit" class="btn btn-primary btn-lg">{{ t('shop.add_to_cart') }}</button>
        </form>
    </div>
</div>
{% endblock %}
```

to:

```twig
            <input type="hidden" name="sku" value="{{ product.sku }}">
            <button type="submit" class="btn btn-primary btn-lg">{{ t('shop.add_to_cart') }}</button>
        </form>
    </div>
</div>

{% if product.specs %}
<div class="container product-specs">
    <h2 class="product-specs-heading">{{ t('shop.specs_title') }}</h2>
    <div class="specs-scroll">
        <table class="specs-table">
            <thead>
                <tr>
                    <th>{{ t('shop.specs_attribute_name') }}</th>
                    <th>{{ t('shop.specs_attribute_value') }}</th>
                </tr>
            </thead>
            <tbody>
                {% for spec in product.specs %}
                {% if spec.attribute_value is empty %}
                <tr class="specs-row-header">
                    <td colspan="2" data-label="">{{ spec.attribute_name }}</td>
                </tr>
                {% elseif spec.attribute_value matches '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/' %}
                <tr>
                    <td data-label="{{ t('shop.specs_attribute_name') }}">{{ spec.attribute_name }}</td>
                    <td data-label="{{ t('shop.specs_attribute_value') }}"><span class="spec-swatch" style="background:{{ spec.attribute_value }};" aria-label="{{ spec.attribute_value }}"></span></td>
                </tr>
                {% else %}
                <tr>
                    <td data-label="{{ t('shop.specs_attribute_name') }}">{{ spec.attribute_name }}</td>
                    <td data-label="{{ t('shop.specs_attribute_value') }}">{{ spec.attribute_value }}</td>
                </tr>
                {% endif %}
                {% endfor %}
            </tbody>
        </table>
    </div>
</div>
{% endif %}
{% endblock %}
```

- [ ] **Step 4: Add CSS for the specs table, heading, and swatch**

In `www/assets/css/style.css`, change:

```css
.empty-state { color: var(--muted); font-family: var(--ui-font); padding: 2rem 0; }
```

to:

```css
.empty-state { color: var(--muted); font-family: var(--ui-font); padding: 2rem 0; }

/* Product specifications table */
.product-specs { padding: 0 1.5rem 3rem; }
.product-specs-heading { font-size: 1.4rem; font-weight: normal; padding-top: 2rem; border-top: 1px solid var(--border); margin-bottom: 1rem; }
.specs-scroll { overflow-x: auto; }
.specs-table { width: 100%; border-collapse: collapse; font-family: var(--ui-font); font-size: .9rem; }
.specs-table th { text-align: left; background: var(--surface-warm); padding: .65rem .9rem; border-bottom: 1px solid var(--border); font-weight: 600; color: var(--text); }
.specs-table td { padding: .65rem .9rem; border-bottom: 1px solid var(--border); vertical-align: top; }
.specs-table tr.specs-row-header td { background: var(--surface-warm); font-weight: 600; }
.spec-swatch { display: inline-block; width: 20px; height: 20px; border-radius: 3px; border: 1px solid var(--border); }
```

Add the mobile horizontal-padding override next to `.product-detail`'s — change:

```css
@media (max-width: 768px) {
    .product-detail { grid-template-columns: 1fr; gap: 2rem; padding: 2rem 1rem; }
    .checkout-layout { grid-template-columns: 1fr; gap: 2rem; padding: 2rem 1rem; }
}
```

to:

```css
@media (max-width: 768px) {
    .product-detail { grid-template-columns: 1fr; gap: 2rem; padding: 2rem 1rem; }
    .checkout-layout { grid-template-columns: 1fr; gap: 2rem; padding: 2rem 1rem; }
    .product-specs { padding: 0 1rem 2rem; }
}
```

Reuse the existing cart-table stacked-card mobile treatment for `.specs-table` — change:

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

to:

```css
/* Responsive: cart-table / specs-table stacked cards (phone only) */
@media (max-width: 480px) {
    .cart-table thead, .specs-table thead { display: none; }
    .cart-table, .cart-table tbody, .cart-table tr, .cart-table td,
    .specs-table, .specs-table tbody, .specs-table tr, .specs-table td { display: block; width: 100%; }
    .cart-table tr, .specs-table tr { border: 1px solid var(--border); border-radius: 4px; margin-bottom: 1rem; padding: .5rem .75rem; }
    .cart-table td, .specs-table td {
        display: flex; justify-content: space-between; align-items: center;
        padding: .5rem 0; border-bottom: 1px solid var(--border);
    }
    .cart-table td:last-child, .specs-table td:last-child { border-bottom: none; }
    .cart-table td::before, .specs-table td::before {
        content: attr(data-label); font-family: var(--ui-font); font-size: .8rem; color: var(--muted); margin-right: 1rem;
    }
}
```

- [ ] **Step 5: Verify the public page renders all three row behaviors**

Reusing the `SPECS-VERIFY-001` product created in Task 3, Step 7:

```bash
curl -s http://localhost:8080/cs/shop/SPECS-VERIFY-001 | grep -o 'Testovaci specifikace\|>Material<\|>Latex<\|>Barva<\|spec-swatch\|specs-row-header\|>Notice<'
```

Expected output includes each of: the product name, `>Material<`, `>Latex<`, `>Barva<`,
`spec-swatch` (confirming the hex-color row rendered as a swatch, not text — grep for
`>#2f7fb8<` should find nothing, confirming no visible hex text leaked into the cell),
`specs-row-header`, and `>Notice<` (confirming the blank-value row rendered as a header).

```bash
curl -s http://localhost:8080/cs/shop/SPECS-VERIFY-001 | grep -o '>#2f7fb8<' && echo "FAIL: hex leaked as text" || echo "OK: no visible hex text"
```

Expected: `OK: no visible hex text`.

- [ ] **Step 6: Run the full test suite**

```bash
php vendor/bin/phpunit
```

Expected: all green.

- [ ] **Step 7: Stop the local dev server started for manual verification**

```bash
pkill -f "php -S localhost:8080 -t www" 2>/dev/null; echo done
```

- [ ] **Step 8: Commit**

```bash
git add templates/public/shop/product.twig www/assets/css/style.css lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json
git commit -m "feat: render product specifications on the public product page"
```
