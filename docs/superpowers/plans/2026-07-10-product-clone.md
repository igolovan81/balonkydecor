# Product Clone Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Clone" action to the admin products list so admins can create a near-duplicate product (e.g. a color variant) without re-entering SKU, price, category, stock settings, and translations from scratch.

**Architecture:** A new `ProductModel::clone()` static method copies the source product's core fields and translations into a new inactive row with a unique SKU (no images copied). A new controller action + route exposes this as a `POST /admin/products/{id}/clone` endpoint that redirects straight to the new product's edit form. A "Clone" button is added to the admin products list next to Edit/Delete.

**Tech Stack:** PHP 8, Slim 4, PDO/MySQL 8 (Docker for local tests), Twig 3, PHPUnit 11.

## Global Constraints

- Model methods are static, go through `Database::getConnection()`, use prepared statements only (see `.claude/rules/database.md`).
- Cloned product must be created with `is_active = 0` regardless of the source's active state.
- Cloned product must NOT copy `product_images` rows or files.
- Cloned product's SKU must be generated via the existing `ProductModel::uniqueSku()` helper, seeded from the source SKU.
- Translations are copied verbatim (no "(Copy)" suffix) across all languages the source has.
- New route goes inside the existing `$app->group('/admin', ...)` block in `src/routes.php`, after the `/edit` routes and before `/delete` (`.claude/rules/backend.md` routing order).
- Flash messages are translation keys, not literal text (`.claude/rules/backend.md`).
- All 5 admin translation files (`lang/admin/{cs,en,ru,uk,sk}.json`) must gain identical new keys, keeping their existing alphabetical key ordering.
- Run `php vendor/bin/phpunit` (whole suite) before every commit — must be fully green.
- Docker MySQL must be running for model tests: `docker compose up -d`.

---

### Task 1: `ProductModel::clone()` with tests

**Files:**
- Modify: `src/Models/ProductModel.php` (add method after `deleteImage()`, i.e. after line 277, before the closing `}` of the class)
- Test: `tests/Unit/Models/ProductModelTest.php` (add tests after `test_all_falls_back_to_sku_when_no_translation` at the end of the class, before the closing `}`)

**Interfaces:**
- Consumes: `ProductModel::findById(int $id): ?array` (returns `null` or array with `sku`, `price`, `category_id`, `stock_type`, `stock_qty`, `images`), `ProductModel::uniqueSku(string $candidate): string`, `ProductModel::create(array $data, int $userId): int`, `ProductModel::getTranslations(int $id): array`, `ProductModel::setTranslations(int $id, array $translations): void`.
- Produces: `ProductModel::clone(int $id, int $userId): ?int` — returns the new product's ID, or `null` if the source product doesn't exist. Later tasks (Task 2) call this.

- [ ] **Step 1: Ensure Docker MySQL is running**

Run: `docker compose up -d`
Expected: MySQL container up (or already running).

- [ ] **Step 2: Write the failing tests**

Add to `tests/Unit/Models/ProductModelTest.php`, immediately before the final closing `}` of the `ProductModelTest` class:

```php
    public function test_clone_copies_translations_and_creates_inactive_product(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-CLONE-' . uniqid(),
            'price'       => 24.50,
            'category_id' => self::$categoryId,
            'is_active'   => 1,
            'stock_type'  => 'limited',
            'stock_qty'   => 7,
        ], self::$userId);
        ProductModel::setTranslations($id, [
            'cs' => ['name' => 'Balónek modrý', 'description' => 'Popis', 'meta_title' => 'Meta CS', 'meta_desc' => 'Desc CS'],
            'en' => ['name' => 'Blue Balloon', 'description' => 'Description', 'meta_title' => 'Meta EN', 'meta_desc' => 'Desc EN'],
        ]);

        $newId = ProductModel::clone($id, self::$userId);

        $this->assertNotNull($newId);
        $this->assertNotSame($id, $newId);

        $original = ProductModel::findById($id);
        $clone    = ProductModel::findById($newId);

        $this->assertNotSame($original['sku'], $clone['sku']);
        $this->assertEquals(24.50, (float) $clone['price']);
        $this->assertSame(self::$categoryId, (int) $clone['category_id']);
        $this->assertSame('limited', $clone['stock_type']);
        $this->assertSame(7, (int) $clone['stock_qty']);
        $this->assertSame(0, (int) $clone['is_active']);
        $this->assertSame([], $clone['images']);

        $cloneTranslations = ProductModel::getTranslations($newId);
        $this->assertSame('Blue Balloon', $cloneTranslations['en']['name']);
        $this->assertSame('Meta EN', $cloneTranslations['en']['meta_title']);
        $this->assertSame('Balónek modrý', $cloneTranslations['cs']['name']);
    }

    public function test_clone_generates_unique_sku_on_collision(): void
    {
        $base = 'CLONE-SKU-' . uniqid();
        $id   = ProductModel::create([
            'sku'         => $base,
            'price'       => 9.99,
            'category_id' => self::$categoryId,
        ], self::$userId);

        $firstCloneId  = ProductModel::clone($id, self::$userId);
        $secondCloneId = ProductModel::clone($id, self::$userId);

        $firstSku  = ProductModel::findById($firstCloneId)['sku'];
        $secondSku = ProductModel::findById($secondCloneId)['sku'];

        $this->assertSame($base . '-2', $firstSku);
        $this->assertSame($base . '-3', $secondSku);
    }

    public function test_clone_returns_null_for_missing_product(): void
    {
        $this->assertNull(ProductModel::clone(999999999, self::$userId));
    }
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php --testdox`
Expected: FAIL — `Call to undefined method App\Models\ProductModel::clone()`

- [ ] **Step 4: Implement `ProductModel::clone()`**

In `src/Models/ProductModel.php`, add this method after `deleteImage()` (after line 277), still inside the class body:

```php
    public static function clone(int $id, int $userId): ?int
    {
        $source = self::findById($id);
        if (!$source) {
            return null;
        }

        $sku   = self::uniqueSku($source['sku']);
        $newId = self::create([
            'sku'         => $sku,
            'price'       => $source['price'],
            'category_id' => $source['category_id'],
            'is_active'   => 0,
            'stock_type'  => $source['stock_type'],
            'stock_qty'   => $source['stock_qty'],
        ], $userId);

        $translations = self::getTranslations($id);
        if ($translations) {
            self::setTranslations($newId, $translations);
        }

        return $newId;
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php --testdox`
Expected: PASS (all tests, including the 3 new `clone` tests)

- [ ] **Step 6: Run the full suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: PASS, no regressions

- [ ] **Step 7: Commit**

```bash
git add src/Models/ProductModel.php tests/Unit/Models/ProductModelTest.php
git commit -m "$(cat <<'EOF'
feat: add ProductModel::clone() for duplicating products

Copies core fields and translations into a new inactive product with a
unique SKU, without copying images, so admins can create color/variant
duplicates without re-entering everything from scratch.
EOF
)"
```

---

### Task 2: Controller action and route

**Files:**
- Modify: `src/routes.php:57` (insert new route line after the existing `/edit` POST route at line 56, before the `/delete` route currently at line 57)
- Modify: `src/Controllers/Admin/ProductController.php` (add `clone()` method after `editSubmit()`, i.e. after line 110, before `deleteImage()`)

**Interfaces:**
- Consumes: `ProductModel::clone(int $id, int $userId): ?int` (Task 1), `ProductModel::findById(int $id): ?array`, `\App\Services\Notifier::notify(string $entityType, int $entityId, string $entityLabel, string $action, int $actorId, string $actorLabel): void`, `AdminBaseController::flash(string $type, string $key): void`, `AdminBaseController::redirect(Response $response, string $url): Response`.
- Produces: `POST /admin/products/{id}/clone` route handled by `ProductController::clone()`. Task 3's template links to this route.

- [ ] **Step 1: Add the route**

In `src/routes.php`, change:

```php
    $group->post('/products/{id:[0-9]+}/edit',                            ProductController::class . ':editSubmit');
    $group->post('/products/{id:[0-9]+}/delete',                          ProductController::class . ':delete');
```

to:

```php
    $group->post('/products/{id:[0-9]+}/edit',                            ProductController::class . ':editSubmit');
    $group->post('/products/{id:[0-9]+}/clone',                           ProductController::class . ':clone');
    $group->post('/products/{id:[0-9]+}/delete',                          ProductController::class . ':delete');
```

- [ ] **Step 2: Add the controller method**

In `src/Controllers/Admin/ProductController.php`, add this method after `editSubmit()` (after line 110, before `deleteImage()`):

```php
    public function clone(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $newId  = ProductModel::clone((int) $args['id'], $userId);
        if ($newId === null) {
            return $response->withStatus(404);
        }
        $clone = ProductModel::findById($newId);
        \App\Services\Notifier::notify(
            'product', $newId, $clone['sku'], 'created', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'products.flash.cloned');
        return $this->redirect($response, '/admin/products/' . $newId . '/edit');
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
feat: add POST /admin/products/{id}/clone endpoint

Clones a product via ProductModel::clone(), notifies, and redirects
straight to the new product's edit form.
EOF
)"
```

---

### Task 3: Admin UI and translations

**Files:**
- Modify: `templates/admin/products/index.twig:35-40` (add Clone form to the actions cell)
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json` (add `products.clone` after line 168, `products.flash.cloned` after line 180, in each file)

**Interfaces:**
- Consumes: `POST /admin/products/{id}/clone` route (Task 2); `t('products.clone')` / `t('products.flash.cloned')` via the existing `admin_i18n` Twig `t()` function.
- Produces: nothing consumed by later tasks — this is the final visible piece of the feature.

- [ ] **Step 1: Add translation keys to all 5 admin language files**

In each of `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`, insert a new line 169 (immediately after the existing `"products.audit.updated": ...,` line 168, before `"products.col.actions"`):

`lang/admin/cs.json`:
```json
  "products.clone": "Klonovat",
```

`lang/admin/en.json`:
```json
  "products.clone": "Clone",
```

`lang/admin/ru.json`:
```json
  "products.clone": "Клонировать",
```

`lang/admin/uk.json`:
```json
  "products.clone": "Клонувати",
```

`lang/admin/sk.json`:
```json
  "products.clone": "Klonovať",
```

Then, in each of the same 5 files, insert a new line immediately after the existing `"products.flash.created": ...,` line (line 181 before this edit; verify by re-grepping since the previous insertion shifts line numbers by 1), before `"products.flash.deleted"`:

`lang/admin/cs.json`:
```json
  "products.flash.cloned": "Produkt naklonován.",
```

`lang/admin/en.json`:
```json
  "products.flash.cloned": "Product cloned.",
```

`lang/admin/ru.json`:
```json
  "products.flash.cloned": "Товар клонирован.",
```

`lang/admin/uk.json`:
```json
  "products.flash.cloned": "Товар клоновано.",
```

`lang/admin/sk.json`:
```json
  "products.flash.cloned": "Produkt naklonovaný.",
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

- [ ] **Step 3: Add the Clone button to the products list template**

In `templates/admin/products/index.twig`, change the actions cell (lines 35-40):

```twig
        <td>
            <a href="/admin/products/{{ p.id }}/edit">{{ t('products.edit') }}</a> |
            <form method="POST" action="/admin/products/{{ p.id }}/delete" style="display:inline" onsubmit="return confirm('{{ t('products.confirm_delete') }}')">
                <button class="btn-link">{{ t('products.delete') }}</button>
            </form>
        </td>
```

to:

```twig
        <td>
            <a href="/admin/products/{{ p.id }}/edit">{{ t('products.edit') }}</a> |
            <form method="POST" action="/admin/products/{{ p.id }}/clone" style="display:inline">
                <button class="btn-link">{{ t('products.clone') }}</button>
            </form> |
            <form method="POST" action="/admin/products/{{ p.id }}/delete" style="display:inline" onsubmit="return confirm('{{ t('products.confirm_delete') }}')">
                <button class="btn-link">{{ t('products.delete') }}</button>
            </form>
        </td>
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
3. Pick any existing product row, click "Klonovat"/"Clone".
4. Confirm: browser lands on `/admin/products/{newId}/edit` with a green flash message ("Produkt naklonován." / "Product cloned."), the form is pre-filled with the same name/description/price/category/stock, no images are present, and the "Aktivní"/"Active" checkbox is unchecked.
5. Go back to `/admin/products` and confirm the new (cloned) row appears with a different SKU than the source and shows as inactive.

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
feat: add clone button to admin products list

Wires the clone endpoint into the UI with translations in all 5 admin
languages, completing the product clone feature.
EOF
)"
```

---

## Self-Review Notes

- **Spec coverage:** model clone semantics (Task 1), route/controller/notification/redirect (Task 2), UI button + translations + manual verification (Task 3) — all spec sections have a corresponding task.
- **Type consistency:** `ProductModel::clone(int $id, int $userId): ?int` signature is identical across Task 1 (definition) and Task 2 (consumption). `Notifier::notify()` signature matches its existing declaration in `src/Services/Notifier.php`.
- **Out of scope confirmed:** no image cloning, no bulk clone, no new notification action — matches the spec's explicit exclusions.
