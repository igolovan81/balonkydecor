# Category/Product Legal Notice Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move "Notice"/"Legal information and warnings" out of generic product-spec rows into a dedicated, translatable field on categories (default) and products (optional override), rendered as its own separate table on the public product page — and fix the root cause of the bug that made the old approach silently drop content.

**Architecture:** `category_t` and `product_t` each gain a nullable `legal_notice` column. `ProductModel::findBySku()` resolves the effective notice (product override wins, else category default, else none) via a join. Admin forms gain a per-language textarea for each. The specs table's blank-value-header convention is removed; a new dedicated table renders the resolved notice. Root fix: `Translator::autoFill()` now translates each field independently instead of batching a language's fields into one API call, so one long/failing field can never take down its siblings — this also lets `ProductController::buildSpecs()` simplify back to a single `autoFill()` call, and the manual "Translate" button JS is fixed the same way (one fetch per field).

**Tech Stack:** Slim 4, Twig 3, PHP 8, PDO/MySQL, PHPUnit 11, vanilla CSS/JS (no build step).

## Global Constraints

- Category default + per-product override, override via a dedicated field (not the specs-rows list) (spec: Constraints).
- Old product-spec "Notice"/"Legal information and warnings" rows (product #42, test fixtures) are left untouched in the DB — no backfill (spec: Constraints).
- The dedicated Notice table must visually match the reference screenshots: shaded "Notice" header band, then a "Legal information and warnings" row (spec: Constraints).
- Translated entities use `*_t` tables with `lang_code`; writes upsert via `ON DUPLICATE KEY UPDATE` (`.claude/rules/database.md`).
- Every visible string goes through `t('key')`, added to **all five** files per surface (`.claude/rules/frontend.md`).
- Model tests run against real Docker MySQL, no mocks; unique/`INSERT IGNORE` fixtures (`.claude/rules/unit-testing.md`).
- New migration: `V0NN__snake_case.sql`, additive only, no backfill needed here (`.claude/rules/database.md`).

---

### Task 1: Migration

**Files:**
- Create: `database/migrations/V023__legal_notice.sql`

**Interfaces:**
- Produces: `category_t.legal_notice` and `product_t.legal_notice` (both `TEXT NULL`), consumed by Task 3's model changes.

- [ ] **Step 1: Create the migration file**

```sql
ALTER TABLE category_t ADD COLUMN legal_notice TEXT NULL;
ALTER TABLE product_t  ADD COLUMN legal_notice TEXT NULL;
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

Expected: `{"applied": ["V023__legal_notice"], "count": 1}` (with no `"error"` key).

- [ ] **Step 3: Verify the schema**

```bash
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e "DESCRIBE category_t; DESCRIBE product_t;" | grep legal_notice
```

Expected: two lines, both showing `legal_notice` as `text`, nullable.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/V023__legal_notice.sql
git commit -m "feat: add legal_notice column to category_t and product_t"
```

---

### Task 2: Translator root-cause fix

**Files:**
- Modify: `src/Services/Translator.php` (`autoFill`)
- Test: `tests/Unit/Services/TranslatorTest.php`

**Interfaces:**
- Consumes: `Translator::translate(array $texts, string $sourceLang, string $targetLang, ?callable $transport): array` (existing, unchanged).
- Produces: `Translator::autoFill()` keeps its existing signature and contract (still leaves a field blank if translation fails), but now fails **per field** instead of per batched-group-of-missing-fields. Consumed by Task 5 (simplified `buildSpecs()`) and every existing caller (`buildSubtypes()`, product/category create/edit) with no call-site changes required.

- [ ] **Step 1: Write the failing regression test**

Add to `tests/Unit/Services/TranslatorTest.php`, after `test_autofill_does_not_overwrite_existing_partial_value`:

```php
    public function test_autofill_isolates_field_failures_from_siblings(): void
    {
        $transport = function (string $url): string {
            if (str_contains($url, rawurlencode('Popis'))) {
                return 'not-json';
            }
            return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Balloons']]);
        };

        $translations = [
            'cs' => ['name' => 'Balónky', 'description' => 'Popis'],
            'en' => ['name' => '', 'description' => ''],
        ];

        $result = Translator::autoFill($translations, 'cs', ['cs', 'en'], ['name', 'description'], $transport);

        $this->assertSame('Balloons', $result['en']['name']);
        $this->assertSame('', $result['en']['description']);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php vendor/bin/phpunit tests/Unit/Services/TranslatorTest.php --filter test_autofill_isolates_field_failures_from_siblings
```

Expected: FAIL — `$result['en']['name']` is `''`, not `'Balloons'` (the current batched implementation aborts translating `name` because `description` fails in the same batch).

- [ ] **Step 3: Fix `Translator::autoFill()`**

In `src/Services/Translator.php`, replace the whole method:

```php
    public static function autoFill(array $translations, string $sourceLang, array $allLangs, array $fields, ?callable $transport = null): array
    {
        $source = $translations[$sourceLang] ?? [];

        foreach ($allLangs as $lang) {
            if ($lang === $sourceLang) {
                continue;
            }

            foreach ($fields as $field) {
                $targetValue = trim((string) ($translations[$lang][$field] ?? ''));
                $sourceValue = trim((string) ($source[$field] ?? ''));
                if ($targetValue !== '' || $sourceValue === '') {
                    continue;
                }

                // Each field is translated in its own request so one over-length or
                // failing field (MyMemory rejects requests over ~500 chars) can never
                // abort translation of its sibling fields for this language.
                try {
                    $translated = self::translate([$sourceValue], $sourceLang, $lang, $transport);
                    $translations[$lang][$field] = $translated[0] ?? '';
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }

        return $translations;
    }
```

- [ ] **Step 4: Run the full Translator test suite**

```bash
php vendor/bin/phpunit tests/Unit/Services/TranslatorTest.php --testdox
```

Expected: all pass (11 tests: the 10 existing + the new regression test), confirming none of the 4 pre-existing `autoFill` tests regressed.

- [ ] **Step 5: Run the full test suite**

```bash
php vendor/bin/phpunit
```

Expected: all green, no regressions.

- [ ] **Step 6: Commit**

```bash
git add src/Services/Translator.php tests/Unit/Services/TranslatorTest.php
git commit -m "fix: translate autoFill fields independently so one failure can't block siblings"
```

---

### Task 3: ProductModel/CategoryModel legal_notice wiring

**Files:**
- Modify: `src/Models/CategoryModel.php` (`getTranslations`, `setTranslations`)
- Modify: `src/Models/ProductModel.php` (`getTranslations`, `setTranslations`, `findBySku`)
- Test: `tests/Unit/Models/CategoryModelTest.php`, `tests/Unit/Models/ProductModelTest.php`

**Interfaces:**
- Consumes: `category_t.legal_notice` / `product_t.legal_notice` columns (Task 1).
- Produces: `CategoryModel::getTranslations()`/`setTranslations()` and `ProductModel::getTranslations()`/`setTranslations()` accept/return a `legal_notice` key per language (consumed by Task 4/5 admin forms). `ProductModel::findBySku()` returns `$product['legal_notice']` — the resolved effective value (product override, else category default, else `null`) — consumed by Task 6's public template.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Models/CategoryModelTest.php`, after `test_each_row_has_expected_keys`:

```php
    public function test_set_translations_stores_legal_notice(): void
    {
        $pdo = Database::getConnection();
        $id  = $pdo->query("SELECT id FROM categories WHERE slug='test-cat'")->fetch()['id'];
        CategoryModel::setTranslations($id, [
            'en' => ['name' => 'Test Category', 'legal_notice' => 'Test warning text.'],
        ]);
        $translations = CategoryModel::getTranslations($id);
        $this->assertSame('Test warning text.', $translations['en']['legal_notice']);
    }
```

Add to `tests/Unit/Models/ProductModelTest.php`, a new private helper right after `makeProduct()`:

```php
    private function makeCategory(): int
    {
        $pdo  = Database::getConnection();
        $slug = 'test-cat-' . uniqid();
        $pdo->prepare('INSERT INTO categories (slug) VALUES (?)')->execute([$slug]);
        return (int) $pdo->lastInsertId();
    }
```

And these test methods, right after `test_find_by_sku_specs_empty_without_any`:

```php
    public function test_find_by_sku_uses_product_legal_notice_when_set(): void
    {
        $catId = $this->makeCategory();
        CategoryModel::setTranslations($catId, ['en' => ['name' => 'Cat', 'legal_notice' => 'Category notice']]);
        $pdo = Database::getConnection();
        $sku = 'LEGALNOTICE-' . strtoupper(uniqid());
        $pdo->prepare('INSERT INTO products (category_id, sku, price) VALUES (?, ?, 9.99)')->execute([$catId, $sku]);
        $id = (int) $pdo->lastInsertId();
        ProductModel::setTranslations($id, ['en' => ['name' => 'Product', 'legal_notice' => 'Product notice']]);

        $product = ProductModel::findBySku($sku, 'en');
        $this->assertSame('Product notice', $product['legal_notice']);
    }

    public function test_find_by_sku_falls_back_to_category_legal_notice(): void
    {
        $catId = $this->makeCategory();
        CategoryModel::setTranslations($catId, ['en' => ['name' => 'Cat', 'legal_notice' => 'Category notice']]);
        $pdo = Database::getConnection();
        $sku = 'LEGALNOTICE-' . strtoupper(uniqid());
        $pdo->prepare('INSERT INTO products (category_id, sku, price) VALUES (?, ?, 9.99)')->execute([$catId, $sku]);
        $id = (int) $pdo->lastInsertId();
        ProductModel::setTranslations($id, ['en' => ['name' => 'Product']]);

        $product = ProductModel::findBySku($sku, 'en');
        $this->assertSame('Category notice', $product['legal_notice']);
    }

    public function test_find_by_sku_legal_notice_null_when_neither_set(): void
    {
        $catId = $this->makeCategory();
        $pdo = Database::getConnection();
        $sku = 'LEGALNOTICE-' . strtoupper(uniqid());
        $pdo->prepare('INSERT INTO products (category_id, sku, price) VALUES (?, ?, 9.99)')->execute([$catId, $sku]);
        $id = (int) $pdo->lastInsertId();
        ProductModel::setTranslations($id, ['en' => ['name' => 'Product']]);

        $product = ProductModel::findBySku($sku, 'en');
        $this->assertNull($product['legal_notice']);
    }

    public function test_set_translations_stores_legal_notice(): void
    {
        $pdo = Database::getConnection();
        $id  = $pdo->query("SELECT id FROM products WHERE sku='TEST-SKU-001'")->fetch()['id'];
        ProductModel::setTranslations($id, [
            'en' => ['name' => 'Test Product', 'legal_notice' => 'Notice text.'],
        ]);
        $translations = ProductModel::getTranslations($id);
        $this->assertSame('Notice text.', $translations['en']['legal_notice']);
    }
```

Add `use App\Models\CategoryModel;` to `ProductModelTest.php`'s imports if not already present (it already is — check the top of the file before adding).

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php vendor/bin/phpunit tests/Unit/Models/CategoryModelTest.php tests/Unit/Models/ProductModelTest.php
```

Expected: FAIL — `legal_notice` key missing from translation arrays, and `$product['legal_notice']` undefined.

- [ ] **Step 3: Update `CategoryModel::getTranslations()`/`setTranslations()`**

In `src/Models/CategoryModel.php`, change:

```php
    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, name, description FROM category_t WHERE category_id = ?');
```

to:

```php
    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, name, description, legal_notice FROM category_t WHERE category_id = ?');
```

Change:

```php
    public static function setTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO category_t (category_id, lang_code, name, description)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)'
        );
        foreach ($translations as $lang => $t) {
            if (empty($t['name'])) continue;
            $stmt->execute([$id, $lang, $t['name'], $t['description'] ?? '']);
        }
    }
```

to:

```php
    public static function setTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO category_t (category_id, lang_code, name, description, legal_notice)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
                                     legal_notice = VALUES(legal_notice)'
        );
        foreach ($translations as $lang => $t) {
            if (empty($t['name'])) continue;
            $stmt->execute([$id, $lang, $t['name'], $t['description'] ?? '', $t['legal_notice'] ?? null]);
        }
    }
```

- [ ] **Step 4: Update `ProductModel::getTranslations()`/`setTranslations()`**

In `src/Models/ProductModel.php`, change:

```php
    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, name, description, meta_title, meta_desc FROM product_t WHERE product_id = ?');
```

to:

```php
    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, name, description, meta_title, meta_desc, legal_notice FROM product_t WHERE product_id = ?');
```

Change:

```php
    public static function setTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO product_t (product_id, lang_code, name, description, meta_title, meta_desc)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
                                     meta_title = VALUES(meta_title), meta_desc = VALUES(meta_desc)'
        );
        foreach ($translations as $lang => $t) {
            if (empty($t['name'])) continue;
            $stmt->execute([$id, $lang, $t['name'], $t['description'] ?? '', $t['meta_title'] ?? null, $t['meta_desc'] ?? null]);
        }
    }
```

to:

```php
    public static function setTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO product_t (product_id, lang_code, name, description, meta_title, meta_desc, legal_notice)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
                                     meta_title = VALUES(meta_title), meta_desc = VALUES(meta_desc),
                                     legal_notice = VALUES(legal_notice)'
        );
        foreach ($translations as $lang => $t) {
            if (empty($t['name'])) continue;
            $stmt->execute([$id, $lang, $t['name'], $t['description'] ?? '', $t['meta_title'] ?? null, $t['meta_desc'] ?? null, $t['legal_notice'] ?? null]);
        }
    }
```

- [ ] **Step 5: Wire notice resolution into `ProductModel::findBySku()`**

Change:

```php
    public static function findBySku(string $sku, string $lang): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT p.id, p.category_id, p.sku, p.price, p.stock_type, p.stock_qty,
                   COALESCE(t.name, p.sku) AS name,
                   t.description, t.meta_title, t.meta_desc
            FROM products p
            LEFT JOIN product_t t ON t.product_id = p.id AND t.lang_code = :lang
            WHERE p.sku = :sku AND p.is_active = 1
        ');
        $stmt->execute(['sku' => $sku, 'lang' => $lang]);
        $product = $stmt->fetch();
        if (!$product) {
            return null;
        }
```

to:

```php
    public static function findBySku(string $sku, string $lang): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT p.id, p.category_id, p.sku, p.price, p.stock_type, p.stock_qty,
                   COALESCE(t.name, p.sku) AS name,
                   t.description, t.meta_title, t.meta_desc,
                   t.legal_notice AS product_legal_notice,
                   ct.legal_notice AS category_legal_notice
            FROM products p
            LEFT JOIN product_t t ON t.product_id = p.id AND t.lang_code = :lang
            LEFT JOIN category_t ct ON ct.category_id = p.category_id AND ct.lang_code = :lang2
            WHERE p.sku = :sku AND p.is_active = 1
        ');
        $stmt->execute(['sku' => $sku, 'lang' => $lang, 'lang2' => $lang]);
        $product = $stmt->fetch();
        if (!$product) {
            return null;
        }

        $product['legal_notice'] = trim((string) ($product['product_legal_notice'] ?? '')) !== ''
            ? $product['product_legal_notice']
            : (trim((string) ($product['category_legal_notice'] ?? '')) !== '' ? $product['category_legal_notice'] : null);
        unset($product['product_legal_notice'], $product['category_legal_notice']);
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
php vendor/bin/phpunit tests/Unit/Models/CategoryModelTest.php tests/Unit/Models/ProductModelTest.php --testdox
```

Expected: all pass, including the 4 new tests.

- [ ] **Step 7: Run the full test suite**

```bash
php vendor/bin/phpunit
```

Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add src/Models/CategoryModel.php src/Models/ProductModel.php tests/Unit/Models/CategoryModelTest.php tests/Unit/Models/ProductModelTest.php
git commit -m "feat: add legal_notice to Category/ProductModel translations and resolve it in findBySku"
```

---

### Task 4: Category admin UI

**Files:**
- Modify: `src/Controllers/Admin/CategoryController.php` (`TRANSLATABLE_FIELDS`)
- Modify: `templates/admin/categories/form.twig` (field + Translate-button JS)
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`

**Interfaces:**
- Consumes: `CategoryModel::getTranslations()`/`setTranslations()` with `legal_notice` (Task 3), `Translator::autoFill()` (Task 2's fixed version), `POST /admin/translate` (existing route, unchanged).

- [ ] **Step 1: Add `legal_notice` to `TRANSLATABLE_FIELDS`**

In `src/Controllers/Admin/CategoryController.php`, change:

```php
    private const TRANSLATABLE_FIELDS = ['name', 'description'];
```

to:

```php
    private const TRANSLATABLE_FIELDS = ['name', 'description', 'legal_notice'];
```

- [ ] **Step 2: Add the field to the admin form template**

In `templates/admin/categories/form.twig`, change:

```twig
        <div class="form-group">
            <label>{{ t('categories.form.desc_label') }} ({{ lang_labels[lang] ?? lang|upper }})</label>
            <textarea name="t[{{ lang }}][description]">{{ translations[lang].description ?? '' }}</textarea>
        </div>
        {% if lang != admin_lang %}
```

to:

```twig
        <div class="form-group">
            <label>{{ t('categories.form.desc_label') }} ({{ lang_labels[lang] ?? lang|upper }})</label>
            <textarea name="t[{{ lang }}][description]">{{ translations[lang].description ?? '' }}</textarea>
        </div>
        <div class="form-group">
            <label>{{ t('categories.form.legal_notice_label') }} ({{ lang_labels[lang] ?? lang|upper }})</label>
            <textarea name="t[{{ lang }}][legal_notice]">{{ translations[lang].legal_notice ?? '' }}</textarea>
        </div>
        {% if lang != admin_lang %}
```

- [ ] **Step 3: Fix the Translate button to send one request per field**

In the same file's `{% block scripts %}`, change:

```javascript
        const fields      = [
            { name: 'name',        el: document.querySelector('input[name="t[' + PREFERRED_LANG + '][name]"]') },
            { name: 'description', el: document.querySelector('textarea[name="t[' + PREFERRED_LANG + '][description]"]') },
        ];
        const filled = fields.filter(f => f.el.value.trim() !== '');

        if (filled.length === 0) {
            msgSpan.textContent = 'Nejprve vyplňte texty ve výchozím jazyce.';
            msgSpan.style.color = '#c00';
            msgSpan.style.display = 'inline';
            return;
        }

        btn.disabled = true;
        const originalLabel = btn.textContent;
        btn.textContent = 'Překládám…';
        msgSpan.style.display = 'none';
        msgSpan.textContent   = '';

        try {
            const res = await fetch('/admin/translate', {
                method:  'POST',
                headers: {'Content-Type': 'application/json'},
                body:    JSON.stringify({
                    texts:  filled.map(f => f.el.value),
                    target: targetLang.toUpperCase(),
                }),
            });

            const data = await res.json();

            if (!res.ok || data.error) {
                msgSpan.textContent   = 'Překlad se nezdařil: ' + (data.error ?? res.status);
                msgSpan.style.color   = '#c00';
                msgSpan.style.display = 'inline';
                return;
            }

            filled.forEach((f, i) => {
                panel.querySelector('[name="t[' + targetLang + '][' + f.name + ']"]').value = data.texts[i] ?? '';
            });
            msgSpan.style.display = 'none';
            msgSpan.textContent   = '';
        } catch (e) {
            msgSpan.textContent   = 'Překlad se nezdařil: ' + e.message;
            msgSpan.style.color   = '#c00';
            msgSpan.style.display = 'inline';
        } finally {
            btn.disabled    = false;
            btn.textContent = originalLabel;
        }
```

to:

```javascript
        const fields      = [
            { name: 'name',          el: document.querySelector('input[name="t[' + PREFERRED_LANG + '][name]"]') },
            { name: 'description',   el: document.querySelector('textarea[name="t[' + PREFERRED_LANG + '][description]"]') },
            { name: 'legal_notice',  el: document.querySelector('textarea[name="t[' + PREFERRED_LANG + '][legal_notice]"]') },
        ];
        const filled = fields.filter(f => f.el.value.trim() !== '');

        if (filled.length === 0) {
            msgSpan.textContent = 'Nejprve vyplňte texty ve výchozím jazyce.';
            msgSpan.style.color = '#c00';
            msgSpan.style.display = 'inline';
            return;
        }

        btn.disabled = true;
        const originalLabel = btn.textContent;
        btn.textContent = 'Překládám…';
        msgSpan.style.display = 'none';
        msgSpan.textContent   = '';

        // One request per field: a single over-length or failing field must not
        // block its siblings from translating successfully.
        const failedFields = [];

        await Promise.all(filled.map(async (f) => {
            try {
                const res  = await fetch('/admin/translate', {
                    method:  'POST',
                    headers: {'Content-Type': 'application/json'},
                    body:    JSON.stringify({
                        texts:  [f.el.value],
                        target: targetLang.toUpperCase(),
                    }),
                });
                const data = await res.json();
                if (!res.ok || data.error) {
                    failedFields.push(f.name);
                    return;
                }
                panel.querySelector('[name="t[' + targetLang + '][' + f.name + ']"]').value = data.texts[0] ?? '';
            } catch (e) {
                failedFields.push(f.name);
            }
        }));

        if (failedFields.length > 0) {
            msgSpan.textContent   = 'Překlad se nezdařil pro: ' + failedFields.join(', ');
            msgSpan.style.color   = '#c00';
            msgSpan.style.display = 'inline';
        } else {
            msgSpan.style.display = 'none';
            msgSpan.textContent   = '';
        }

        btn.disabled    = false;
        btn.textContent = originalLabel;
```

- [ ] **Step 4: Add admin translation keys to all five `lang/admin/*.json` files**

Insert `categories.form.legal_notice_label` after `categories.form.desc_label` and before `categories.form.name_label` (alphabetical: `desc_label` < `legal_notice_label` < `name_label`).

In `lang/admin/cs.json`, change:

```json
  "categories.form.desc_label": "Popis",
  "categories.form.name_label": "Název",
```

to:

```json
  "categories.form.desc_label": "Popis",
  "categories.form.legal_notice_label": "Zákonné informace a varování",
  "categories.form.name_label": "Název",
```

In `lang/admin/en.json`:

```json
  "categories.form.desc_label": "Description",
  "categories.form.legal_notice_label": "Legal information and warnings",
  "categories.form.name_label": "Name",
```

In `lang/admin/ru.json`:

```json
  "categories.form.desc_label": "Описание",
  "categories.form.legal_notice_label": "Юридическая информация и предупреждения",
  "categories.form.name_label": "Название",
```

In `lang/admin/uk.json`:

```json
  "categories.form.desc_label": "Опис",
  "categories.form.legal_notice_label": "Юридична інформація та попередження",
  "categories.form.name_label": "Назва",
```

In `lang/admin/sk.json`:

```json
  "categories.form.desc_label": "Popis",
  "categories.form.legal_notice_label": "Právne informácie a upozornenia",
  "categories.form.name_label": "Názov",
```

(Use each file's existing exact wording for the surrounding `desc_label`/`name_label` lines — check the file before editing so the `old_string` match is exact.)

- [ ] **Step 5: Verify all five admin files still parse and have identical key sets**

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

Expected: `OK` with no mismatch.

- [ ] **Step 6: Verify by hand**

With the local server running and logged in as the `specs-verify-test@example.com` fixture (created in the prior feature's Task 3; recreate it the same way if it no longer exists locally):

```bash
curl -s -b /tmp/specs-verify-cookies.txt http://localhost:8080/admin/categories/1/edit | grep -o 'legal_notice'
```

Expected: prints `legal_notice` (field present in the rendered form).

- [ ] **Step 7: Run the full test suite**

```bash
php vendor/bin/phpunit
```

Expected: all green (no PHP unit tests changed in this task).

- [ ] **Step 8: Commit**

```bash
git add src/Controllers/Admin/CategoryController.php templates/admin/categories/form.twig lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/uk.json lang/admin/sk.json
git commit -m "feat: add legal notice field to category admin form"
```

---

### Task 5: Product admin UI + buildSpecs simplification

**Files:**
- Modify: `src/Controllers/Admin/ProductController.php` (`TRANSLATABLE_FIELDS`, `buildSpecs`)
- Modify: `templates/admin/products/form.twig` (field + Translate-button JS)
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`

**Interfaces:**
- Consumes: `ProductModel::getTranslations()`/`setTranslations()` with `legal_notice` (Task 3), `Translator::autoFill()` (Task 2).

- [ ] **Step 1: Add `legal_notice` to `TRANSLATABLE_FIELDS`**

In `src/Controllers/Admin/ProductController.php`, change:

```php
    private const TRANSLATABLE_FIELDS  = ['name', 'description', 'meta_title', 'meta_desc'];
```

to:

```php
    private const TRANSLATABLE_FIELDS  = ['name', 'description', 'meta_title', 'meta_desc', 'legal_notice'];
```

- [ ] **Step 2: Simplify `buildSpecs()` now that `autoFill()` isolates fields internally**

Change:

```php
    private function buildSpecs(array $rows, string $adminLang): array
    {
        $specs = [];
        foreach ($rows as $row) {
            $name = trim($row['name'] ?? '');
            if ($name === '') continue;
            $value = trim($row['value'] ?? '');

            // name and value are auto-filled independently: MyMemory rejects
            // requests over ~500 chars (long legal/notice text), and if that
            // failure aborted a single combined call, both fields — and the
            // whole row — would silently vanish for that language.
            $tName  = \App\Services\Translator::autoFill([$adminLang => ['name' => $name]], $adminLang, self::LANGS, ['name']);
            $tValue = \App\Services\Translator::autoFill([$adminLang => ['value' => $value]], $adminLang, self::LANGS, ['value']);

            $t = [];
            foreach (self::LANGS as $lang) {
                $t[$lang] = [
                    'name'  => $tName[$lang]['name'] ?? $name,
                    'value' => $tValue[$lang]['value'] ?? $value,
                ];
            }
            $specs[] = ['t' => $t];
        }
        return $specs;
    }
```

to:

```php
    private function buildSpecs(array $rows, string $adminLang): array
    {
        $specs = [];
        foreach ($rows as $row) {
            $name = trim($row['name'] ?? '');
            if ($name === '') continue;
            $value = trim($row['value'] ?? '');

            $t = \App\Services\Translator::autoFill(
                [$adminLang => ['name' => $name, 'value' => $value]],
                $adminLang, self::LANGS, ['name', 'value']
            );

            // Translator::autoFill() now isolates field failures from each other, but
            // a field can still end up unset if translation fails outright — fall
            // back to the admin's own text so a row is never missing/blank.
            foreach (self::LANGS as $lang) {
                $t[$lang]['name']  = $t[$lang]['name']  ?? $name;
                $t[$lang]['value'] = $t[$lang]['value'] ?? $value;
            }

            $specs[] = ['t' => $t];
        }
        return $specs;
    }
```

- [ ] **Step 3: Add the field to the admin form template**

In `templates/admin/products/form.twig`, change:

```twig
                <div class="form-group">
                    <label>{{ t('products.form.desc_label') }} ({{ lang|upper }})</label>
                    <textarea name="t[{{ lang }}][description]">{{ translations[lang].description ?? '' }}</textarea>
                </div>
                <div class="form-group">
                    <label>{{ t('products.form.meta_title_label') }} ({{ lang|upper }})</label>
```

to:

```twig
                <div class="form-group">
                    <label>{{ t('products.form.desc_label') }} ({{ lang|upper }})</label>
                    <textarea name="t[{{ lang }}][description]">{{ translations[lang].description ?? '' }}</textarea>
                </div>
                <div class="form-group">
                    <label>{{ t('products.form.legal_notice_label') }} ({{ lang|upper }})</label>
                    <textarea name="t[{{ lang }}][legal_notice]">{{ translations[lang].legal_notice ?? '' }}</textarea>
                    <p class="audit-meta" style="margin-top:0.35rem;">{{ t('products.form.legal_notice_hint') }}</p>
                </div>
                <div class="form-group">
                    <label>{{ t('products.form.meta_title_label') }} ({{ lang|upper }})</label>
```

- [ ] **Step 4: Fix the Translate button to send one request per field**

In the same file's `{% block scripts %}`, change:

```javascript
        const fields      = [
            { name: 'name',        el: document.querySelector('input[name="t[' + PREFERRED_LANG + '][name]"]') },
            { name: 'description', el: document.querySelector('textarea[name="t[' + PREFERRED_LANG + '][description]"]') },
            { name: 'meta_title',  el: document.querySelector('input[name="t[' + PREFERRED_LANG + '][meta_title]"]') },
            { name: 'meta_desc',   el: document.querySelector('textarea[name="t[' + PREFERRED_LANG + '][meta_desc]"]') },
        ];
        const filled = fields.filter(f => f.el.value.trim() !== '');

        if (filled.length === 0) {
            msgSpan.textContent = 'Nejprve vyplňte texty ve výchozím jazyce.';
            msgSpan.style.color = '#c00';
            msgSpan.style.display = 'inline';
            return;
        }

        btn.disabled = true;
        const originalLabel = btn.textContent;
        btn.textContent = 'Překládám…';
        msgSpan.style.display = 'none';
        msgSpan.textContent   = '';

        try {
            const res = await fetch('/admin/translate', {
                method:  'POST',
                headers: {'Content-Type': 'application/json'},
                body:    JSON.stringify({
                    texts:  filled.map(f => f.el.value),
                    target: targetLang.toUpperCase(),
                }),
            });

            const data = await res.json();

            if (!res.ok || data.error) {
                msgSpan.textContent   = 'Překlad se nezdařil: ' + (data.error ?? res.status);
                msgSpan.style.color   = '#c00';
                msgSpan.style.display = 'inline';
                return;
            }

            filled.forEach((f, i) => {
                panel.querySelector('[name="t[' + targetLang + '][' + f.name + ']"]').value = data.texts[i] ?? '';
            });
            msgSpan.style.display = 'none';
            msgSpan.textContent   = '';
        } catch (e) {
            msgSpan.textContent   = 'Překlad se nezdařil: ' + e.message;
            msgSpan.style.color   = '#c00';
            msgSpan.style.display = 'inline';
        } finally {
            btn.disabled    = false;
            btn.textContent = originalLabel;
        }
```

to:

```javascript
        const fields      = [
            { name: 'name',          el: document.querySelector('input[name="t[' + PREFERRED_LANG + '][name]"]') },
            { name: 'description',   el: document.querySelector('textarea[name="t[' + PREFERRED_LANG + '][description]"]') },
            { name: 'legal_notice',  el: document.querySelector('textarea[name="t[' + PREFERRED_LANG + '][legal_notice]"]') },
            { name: 'meta_title',    el: document.querySelector('input[name="t[' + PREFERRED_LANG + '][meta_title]"]') },
            { name: 'meta_desc',     el: document.querySelector('textarea[name="t[' + PREFERRED_LANG + '][meta_desc]"]') },
        ];
        const filled = fields.filter(f => f.el.value.trim() !== '');

        if (filled.length === 0) {
            msgSpan.textContent = 'Nejprve vyplňte texty ve výchozím jazyce.';
            msgSpan.style.color = '#c00';
            msgSpan.style.display = 'inline';
            return;
        }

        btn.disabled = true;
        const originalLabel = btn.textContent;
        btn.textContent = 'Překládám…';
        msgSpan.style.display = 'none';
        msgSpan.textContent   = '';

        // One request per field: a single over-length or failing field must not
        // block its siblings from translating successfully.
        const failedFields = [];

        await Promise.all(filled.map(async (f) => {
            try {
                const res  = await fetch('/admin/translate', {
                    method:  'POST',
                    headers: {'Content-Type': 'application/json'},
                    body:    JSON.stringify({
                        texts:  [f.el.value],
                        target: targetLang.toUpperCase(),
                    }),
                });
                const data = await res.json();
                if (!res.ok || data.error) {
                    failedFields.push(f.name);
                    return;
                }
                panel.querySelector('[name="t[' + targetLang + '][' + f.name + ']"]').value = data.texts[0] ?? '';
            } catch (e) {
                failedFields.push(f.name);
            }
        }));

        if (failedFields.length > 0) {
            msgSpan.textContent   = 'Překlad se nezdařil pro: ' + failedFields.join(', ');
            msgSpan.style.color   = '#c00';
            msgSpan.style.display = 'inline';
        } else {
            msgSpan.style.display = 'none';
            msgSpan.textContent   = '';
        }

        btn.disabled    = false;
        btn.textContent = originalLabel;
```

- [ ] **Step 5: Add admin translation keys to all five `lang/admin/*.json` files**

Insert `products.form.legal_notice_hint` and `products.form.legal_notice_label` after `products.form.existing_images` and before `products.form.meta_desc_label` (alphabetical: `existing_images` < `legal_notice_hint` < `legal_notice_label` < `meta_desc_label`).

In `lang/admin/cs.json`, change:

```json
  "products.form.existing_images": "Stávající obrázky",
  "products.form.meta_desc_label": "SEO popis (meta description)",
```

to:

```json
  "products.form.existing_images": "Stávající obrázky",
  "products.form.legal_notice_hint": "Ponechte prázdné pro použití upozornění nastaveného u kategorie.",
  "products.form.legal_notice_label": "Zákonné informace a varování",
  "products.form.meta_desc_label": "SEO popis (meta description)",
```

In `lang/admin/en.json`, change:

```json
  "products.form.existing_images": "Existing images",
  "products.form.meta_desc_label": "SEO description (meta description)",
```

to:

```json
  "products.form.existing_images": "Existing images",
  "products.form.legal_notice_hint": "Leave blank to use the category's notice.",
  "products.form.legal_notice_label": "Legal information and warnings",
  "products.form.meta_desc_label": "SEO description (meta description)",
```

In `lang/admin/ru.json`, change:

```json
  "products.form.existing_images": "Существующие изображения",
  "products.form.meta_desc_label": "SEO-описание (meta description)",
```

to:

```json
  "products.form.existing_images": "Существующие изображения",
  "products.form.legal_notice_hint": "Оставьте пустым, чтобы использовать уведомление категории.",
  "products.form.legal_notice_label": "Юридическая информация и предупреждения",
  "products.form.meta_desc_label": "SEO-описание (meta description)",
```

In `lang/admin/uk.json`, change:

```json
  "products.form.existing_images": "Наявні зображення",
  "products.form.meta_desc_label": "SEO-опис (meta description)",
```

to:

```json
  "products.form.existing_images": "Наявні зображення",
  "products.form.legal_notice_hint": "Залиште порожнім, щоб використати повідомлення категорії.",
  "products.form.legal_notice_label": "Юридична інформація та попередження",
  "products.form.meta_desc_label": "SEO-опис (meta description)",
```

In `lang/admin/sk.json`, change:

```json
  "products.form.existing_images": "Existujúce obrázky",
  "products.form.meta_desc_label": "SEO popis (meta description)",
```

to:

```json
  "products.form.existing_images": "Existujúce obrázky",
  "products.form.legal_notice_hint": "Ponechajte prázdne pre použitie upozornenia nastaveného pri kategórii.",
  "products.form.legal_notice_label": "Právne informácie a upozornenia",
  "products.form.meta_desc_label": "SEO popis (meta description)",
```

(As in Task 4, verify each file's exact surrounding line text before editing so the match is exact.)

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

Expected: `OK` with no mismatch.

- [ ] **Step 7: Verify by hand — including the long-notice translate scenario that motivated the Translator fix**

With the local server running and logged in as the fixture admin:

```bash
curl -s -b /tmp/specs-verify-cookies.txt http://localhost:8080/admin/products/new | grep -o 'legal_notice'
```

Expected: prints `legal_notice`.

Create a product with a long (>500 char) legal notice directly in `cs`, and confirm the row saves and the notice resolves correctly on a different-language public page (proving `buildSpecs()`'s simplified form and the `autoFill()` fix both work end-to-end):

```bash
CAT_ID=$(docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -N -e "SELECT id FROM categories LIMIT 1;" | tr -d '\r')
LONGTEXT="Testovaci dlouhy pravni text s vice nez peti sty znaky, aby bylo mozne overit, ze prekladac spravne zpracovava dlouhe hodnoty bez ztraty obsahu. Tento text je zamerne velmi dlouhy a opakuje podobne fraze, aby prekrocil limit API pro strojovy preklad, ktery je nastaven na peti sta znaku na jeden pozadavek, a overil tak spravne chovani systemu i v pripade, ze API vrati chybu kvuli prekroceni tohoto limitu behem prekladu do jineho jazyka."
echo "length: ${#LONGTEXT}"

curl -s -b /tmp/specs-verify-cookies.txt -c /tmp/specs-verify-cookies.txt \
  -X POST http://localhost:8080/admin/products/new \
  --data-urlencode "sku=LEGALNOTICE-VERIFY-001" \
  --data-urlencode "price=9.90" \
  --data-urlencode "category_id=${CAT_ID}" \
  --data-urlencode "is_active=1" \
  --data-urlencode "stock_type=unlimited" \
  --data-urlencode "t[cs][name]=Test dlouheho upozorneni" \
  --data-urlencode "t[cs][legal_notice]=${LONGTEXT}" \
  -o /dev/null -w '%{http_code}\n'

curl -s http://localhost:8080/en/shop/LEGALNOTICE-VERIFY-001 | grep -o 'Testovaci dlouhy pravni text[^<]*' | head -c 200
```

Expected: create returns `302`; the `/en/` page shows the (possibly untranslated, but present) notice text — never blank/missing.

- [ ] **Step 8: Run the full test suite**

```bash
php vendor/bin/phpunit
```

Expected: all green.

- [ ] **Step 9: Commit**

```bash
git add src/Controllers/Admin/ProductController.php templates/admin/products/form.twig lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/uk.json lang/admin/sk.json
git commit -m "feat: add legal notice field to product admin form and simplify buildSpecs"
```

---

### Task 6: Public rendering

**Files:**
- Modify: `templates/public/shop/product.twig`
- Modify: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`

**Interfaces:**
- Consumes: `product.legal_notice` (Task 3's resolved value from `findBySku()`).

- [ ] **Step 1: Add public translation keys to all five `lang/*.json` files**

Insert `shop.notice_legal_label` and `shop.notice_title` after `shop.no_products` and before `shop.qty` (alphabetical: `no_products` < `notice_legal_label` < `notice_title` < `qty`).

In `lang/cs.json`, change:

```json
  "shop.no_products": "Žádné produkty v této kategorii.",
  "shop.qty": "Množství",
```

to:

```json
  "shop.no_products": "Žádné produkty v této kategorii.",
  "shop.notice_legal_label": "Zákonné informace a varování",
  "shop.notice_title": "Upozornění",
  "shop.qty": "Množství",
```

In `lang/en.json`, change:

```json
  "shop.no_products": "No products in this category.",
  "shop.qty": "Quantity",
```

to:

```json
  "shop.no_products": "No products in this category.",
  "shop.notice_legal_label": "Legal information and warnings",
  "shop.notice_title": "Notice",
  "shop.qty": "Quantity",
```

In `lang/ru.json`, change:

```json
  "shop.no_products": "Нет товаров в этой категории.",
  "shop.qty": "Количество",
```

to:

```json
  "shop.no_products": "Нет товаров в этой категории.",
  "shop.notice_legal_label": "Юридическая информация и предупреждения",
  "shop.notice_title": "Уведомление",
  "shop.qty": "Количество",
```

In `lang/uk.json`, change:

```json
  "shop.no_products": "Немає товарів у цій категорії.",
  "shop.qty": "Кількість",
```

to:

```json
  "shop.no_products": "Немає товарів у цій категорії.",
  "shop.notice_legal_label": "Юридична інформація та попередження",
  "shop.notice_title": "Повідомлення",
  "shop.qty": "Кількість",
```

In `lang/sk.json`, change:

```json
  "shop.no_products": "Žiadne produkty v tejto kategórii.",
  "shop.qty": "Množstvo",
```

to:

```json
  "shop.no_products": "Žiadne produkty v tejto kategórii.",
  "shop.notice_legal_label": "Právne informácie a upozornenia",
  "shop.notice_title": "Upozornenie",
  "shop.qty": "Množstvo",
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

Expected: `OK` with no mismatch.

- [ ] **Step 3: Remove the blank-value-header branch from the specs table, add the dedicated Notice table**

In `templates/public/shop/product.twig`, change:

```twig
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

to:

```twig
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
                {% if spec.attribute_value matches '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/' %}
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

{% if product.legal_notice %}
<div class="container product-specs">
    <div class="specs-scroll">
        <table class="specs-table">
            <thead>
                <tr>
                    <th>{{ t('shop.specs_attribute_name') }}</th>
                    <th>{{ t('shop.specs_attribute_value') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr class="specs-row-header">
                    <td colspan="2" data-label="">{{ t('shop.notice_title') }}</td>
                </tr>
                <tr>
                    <td data-label="{{ t('shop.specs_attribute_name') }}">{{ t('shop.notice_legal_label') }}</td>
                    <td data-label="{{ t('shop.specs_attribute_value') }}">{{ product.legal_notice }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
{% endif %}
{% endblock %}
```

No CSS changes needed — `.product-specs`, `.specs-table`, and `.specs-row-header` already exist from the prior feature and are reused as-is.

- [ ] **Step 4: Verify by hand — category default and product override**

Reusing the local server and `CAT_ID` from Task 5's verification, set a category notice and confirm a product with no override inherits it:

```bash
curl -s -b /tmp/specs-verify-cookies.txt -c /tmp/specs-verify-cookies.txt \
  -X POST "http://localhost:8080/admin/categories/${CAT_ID}/edit" \
  --data-urlencode "slug=$(docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -N -e "SELECT slug FROM categories WHERE id=${CAT_ID};" | tr -d '\r')" \
  --data-urlencode "sort_order=0" \
  --data-urlencode "t[cs][name]=$(docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -N -e "SELECT COALESCE((SELECT name FROM category_t WHERE category_id=${CAT_ID} AND lang_code='cs'), slug) FROM categories WHERE id=${CAT_ID};" | tr -d '\r')" \
  --data-urlencode "t[cs][legal_notice]=Kategorie: obecne bezpecnostni upozorneni." \
  -o /dev/null -w '%{http_code}\n'

curl -s http://localhost:8080/cs/shop/SPECS-LONGTEXT-001 | grep -o 'Kategorie: obecne bezpecnostni upozorneni\.\|specs-row-header\|>Upozornění<'
```

Expected: the category-inherited notice text, `specs-row-header`, and `>Upozornění<` all appear (`SPECS-LONGTEXT-001` is a product from the prior feature's Task 4 verification with no `legal_notice` of its own, in the same category as `CAT_ID`).

Confirm a product-level override wins:

```bash
curl -s -b /tmp/specs-verify-cookies.txt -c /tmp/specs-verify-cookies.txt \
  -X POST http://localhost:8080/admin/products/new \
  --data-urlencode "sku=LEGALNOTICE-OVERRIDE-001" \
  --data-urlencode "price=9.90" \
  --data-urlencode "category_id=${CAT_ID}" \
  --data-urlencode "is_active=1" \
  --data-urlencode "stock_type=unlimited" \
  --data-urlencode "t[cs][name]=Test prebiti upozorneni" \
  --data-urlencode "t[cs][legal_notice]=Produkt: specificke upozorneni prebijejici kategorii." \
  -o /dev/null -w '%{http_code}\n'

curl -s http://localhost:8080/cs/shop/LEGALNOTICE-OVERRIDE-001 | grep -o 'Produkt: specificke upozorneni prebijejici kategorii\.'
curl -s http://localhost:8080/cs/shop/LEGALNOTICE-OVERRIDE-001 | grep -o 'Kategorie: obecne bezpecnostni upozorneni\.' && echo "FAIL: category notice leaked through override" || echo "OK: override wins, category text absent"
```

Expected: the product's own text appears; the category text does not.

- [ ] **Step 5: Confirm the old per-product spec "Notice" rows no longer render specially**

```bash
curl -s http://localhost:8080/cs/shop/SPECS-VERIFY-001 | grep -A1 '>Notice<' | grep -c 'specs-row-header'
```

Expected: `0` — the old blank-value row (from the prior feature's `SPECS-VERIFY-001` fixture) now renders as a plain row, not a header band, confirming the convention was fully removed.

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
git add templates/public/shop/product.twig lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json
git commit -m "feat: render category/product legal notice as a dedicated public table"
```
