# Product Image Split Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin split one image off a multi-image product into its own new product (e.g. turning a "balloon arch" product with 3 color photos into 3 separate color-specific products), reusing the existing `ProductModel::clone()` mechanism.

**Architecture:** `ProductModel::clone()` gains an optional third `?int $imageId` parameter. When provided, the same clone (new inactive product + copied translations) additionally reassigns that one `product_images` row onto the new product (making it primary), copies `product_subtypes`, promotes a new primary on the source if needed, and deactivates the source once it has zero images left — all inside one transaction. A new `POST /admin/products/{id}/clone-image/{image_id}` route/controller method exposes this, reusing the existing "redirect straight to the new product's edit page" pattern. A "Split into new product" button is added next to each image thumbnail on the product edit form.

**Tech Stack:** PHP 8, Slim 4, PDO/MySQL 8 (Docker for local tests), Twig 3, PHPUnit 11.

## Global Constraints

- Model methods are static, go through `Database::getConnection()`, use prepared statements only (see `.claude/rules/database.md`).
- Multi-statement writes that must not partially apply belong in one transaction (`.claude/rules/database.md`) — the whole image-split mutation (new product + translations + subtypes + image move + primary promotion + source deactivation) is one `beginTransaction()`/`commit()`.
- Calling `ProductModel::clone($id, $userId)` with no third argument must behave byte-for-byte as it does today — the three existing `test_clone_*` tests in `tests/Unit/Models/ProductModelTest.php` (lines 510–569) must keep passing unmodified.
- The image is **moved**, not copied — it must no longer appear on the source product after a split.
- Splitting the source's last remaining image sets the source's `is_active = 0`. This behavior is scoped to the new split path only — `ProductModel::deleteImage()` (the existing per-image delete button) must NOT change.
- Flash messages are translation keys, not literal text (`.claude/rules/backend.md`).
- New route goes inside the existing `$app->group('/admin', ...)` block in `src/routes.php` (`.claude/rules/backend.md` routing order).
- All 5 admin translation files (`lang/admin/{cs,en,ru,uk,sk}.json`) must gain identical new keys, keeping their existing alphabetical key ordering.
- Run `php vendor/bin/phpunit` (whole suite) before every commit — must be fully green.
- Docker MySQL must be running for model tests: `docker compose up -d`.

---

### Task 1: Extend `ProductModel::clone()` to move an image (and its subtypes)

**Files:**
- Modify: `src/Models/ProductModel.php:338-361` (replace the existing `clone()` method)
- Test: `tests/Unit/Models/ProductModelTest.php` (add tests immediately before the closing `}` of the class, after `test_clone_returns_null_for_missing_product` at line 569)

**Interfaces:**
- Consumes: `ProductModel::findById(int $id): ?array` (returns `null` or an array with `sku`, `price`, `category_id`, `stock_type`, `stock_qty`, `images` (each with `id`, `filename`, `is_primary`, `sort_order`), `subtypes` (each with `id`, `price`, `sort_order`, `t` map)); `ProductModel::uniqueSku(string $candidate): string`; `ProductModel::create(array $data, int $userId): int`; `ProductModel::getTranslations(int $id): array`; `ProductModel::setTranslations(int $id, array $translations): void`; `ProductModel::setSubtypes(int $productId, array $rows): void`; `ProductModel::addImage(int $productId, string $filename, bool $isPrimary = false): void` (test fixtures only).
- Produces: `ProductModel::clone(int $id, int $userId, ?int $imageId = null): ?int` — returns the new product's ID, or `null` if the source product doesn't exist OR `$imageId` doesn't belong to it. Task 2 calls this with a non-null `$imageId`.

- [ ] **Step 1: Ensure Docker MySQL is running**

Run: `docker compose up -d`
Expected: MySQL container up (or already running).

- [ ] **Step 2: Write the failing tests**

Add to `tests/Unit/Models/ProductModelTest.php`, immediately before the final closing `}` of the `ProductModelTest` class (after `test_clone_returns_null_for_missing_product`):

```php
    public function test_clone_with_image_id_moves_image_and_makes_it_primary(): void
    {
        $id = $this->makeProduct();
        ProductModel::addImage($id, 'color-red.jpg', false);   // becomes primary
        ProductModel::addImage($id, 'color-blue.jpg', false);

        $images    = ProductModel::findById($id)['images'];
        $secondary = current(array_filter($images, fn ($img) => $img['filename'] === 'color-blue.jpg'));

        $newId = ProductModel::clone($id, self::$userId, (int) $secondary['id']);

        $this->assertNotNull($newId);
        $newImages = ProductModel::findById($newId)['images'];
        $this->assertCount(1, $newImages);
        $this->assertSame('color-blue.jpg', $newImages[0]['filename']);
        $this->assertSame(1, (int) $newImages[0]['is_primary']);

        $sourceImages = ProductModel::findById($id)['images'];
        $this->assertCount(1, $sourceImages);
        $this->assertSame('color-red.jpg', $sourceImages[0]['filename']);
        $this->assertSame(1, (int) $sourceImages[0]['is_primary']);
    }

    public function test_clone_with_image_id_promotes_new_primary_on_source_when_primary_moved(): void
    {
        $id = $this->makeProduct();
        ProductModel::addImage($id, 'color-red.jpg', false);   // becomes primary
        ProductModel::addImage($id, 'color-blue.jpg', false);

        $images  = ProductModel::findById($id)['images'];
        $primary = current(array_filter($images, fn ($img) => (int) $img['is_primary'] === 1));

        ProductModel::clone($id, self::$userId, (int) $primary['id']);

        $remaining = ProductModel::findById($id)['images'];
        $this->assertCount(1, $remaining);
        $this->assertSame('color-blue.jpg', $remaining[0]['filename']);
        $this->assertSame(1, (int) $remaining[0]['is_primary']);
    }

    public function test_clone_with_image_id_deactivates_source_when_last_image_removed(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-SPLIT-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
            'is_active'   => 1,
        ], self::$userId);
        ProductModel::addImage($id, 'only-image.jpg', false);
        $imageId = ProductModel::findById($id)['images'][0]['id'];

        ProductModel::clone($id, self::$userId, (int) $imageId);

        $source = ProductModel::findById($id);
        $this->assertSame(0, (int) $source['is_active']);
        $this->assertSame([], $source['images']);
    }

    public function test_clone_with_image_id_keeps_source_active_when_images_remain(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-SPLIT-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
            'is_active'   => 1,
        ], self::$userId);
        ProductModel::addImage($id, 'color-red.jpg', false);
        ProductModel::addImage($id, 'color-blue.jpg', false);
        $secondImageId = ProductModel::findById($id)['images'][1]['id'];

        ProductModel::clone($id, self::$userId, (int) $secondImageId);

        $source = ProductModel::findById($id);
        $this->assertSame(1, (int) $source['is_active']);
    }

    public function test_clone_with_image_id_copies_subtypes(): void
    {
        $id = $this->makeProduct();
        ProductModel::addImage($id, 'color-red.jpg', false);
        ProductModel::setSubtypes($id, [
            ['price' => '1.90', 't' => ['cs' => 'Malý', 'en' => 'Small']],
            ['price' => '3.40', 't' => ['cs' => 'Velký', 'en' => 'Large']],
        ]);
        $imageId = ProductModel::findById($id)['images'][0]['id'];

        $newId = ProductModel::clone($id, self::$userId, (int) $imageId);

        $newSubtypes = ProductModel::getSubtypes($newId);
        $this->assertCount(2, $newSubtypes);
        $this->assertSame('1.90', $newSubtypes[0]['price']);
        $this->assertSame('Small', $newSubtypes[0]['t']['en']);
        $this->assertSame('Velký', $newSubtypes[1]['t']['cs']);

        $sourceSubtypes = ProductModel::getSubtypes($id);
        $this->assertCount(2, $sourceSubtypes);
    }

    public function test_clone_with_image_id_not_belonging_to_product_returns_null(): void
    {
        $id      = $this->makeProduct();
        $otherId = $this->makeProduct();
        ProductModel::addImage($otherId, 'not-mine.jpg', false);
        $foreignImageId = ProductModel::findById($otherId)['images'][0]['id'];

        $result = ProductModel::clone($id, self::$userId, (int) $foreignImageId);

        $this->assertNull($result);
        $this->assertSame([], ProductModel::findById($id)['images']);
        $otherImages = ProductModel::findById($otherId)['images'];
        $this->assertCount(1, $otherImages);
        $this->assertSame('not-mine.jpg', $otherImages[0]['filename']);
    }
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php --testdox`
Expected: FAIL — `Too many arguments passed` (or similar `ArgumentCountError`) for the new tests, since `clone()` doesn't accept a third argument yet.

- [ ] **Step 4: Replace `ProductModel::clone()`**

In `src/Models/ProductModel.php`, replace the existing method (lines 338-361):

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

with:

```php
    public static function clone(int $id, int $userId, ?int $imageId = null): ?int
    {
        $source = self::findById($id);
        if (!$source) {
            return null;
        }

        $movedImage = null;
        if ($imageId !== null) {
            $movedImage = current(array_filter(
                $source['images'],
                fn ($img) => (int) $img['id'] === $imageId
            ));
            if (!$movedImage) {
                return null;
            }
        }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();

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

        if ($movedImage !== null) {
            $pdo->prepare('UPDATE product_images SET product_id = ?, is_primary = 1, sort_order = 0 WHERE id = ?')
                ->execute([$newId, $movedImage['id']]);

            if ((int) $movedImage['is_primary'] === 1) {
                $pdo->prepare(
                    'UPDATE product_images SET is_primary = 1 WHERE product_id = ? ORDER BY sort_order, id LIMIT 1'
                )->execute([$id]);
            }

            if ($source['subtypes']) {
                self::setSubtypes($newId, $source['subtypes']);
            }

            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = ?');
            $countStmt->execute([$id]);
            if ((int) $countStmt->fetchColumn() === 0) {
                $pdo->prepare('UPDATE products SET is_active = 0 WHERE id = ?')->execute([$id]);
            }
        }

        $pdo->commit();

        return $newId;
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php --testdox`
Expected: PASS (all tests, including the 3 pre-existing `clone` tests and the 6 new ones)

- [ ] **Step 6: Run the full suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: PASS, no regressions

- [ ] **Step 7: Commit**

```bash
git add src/Models/ProductModel.php tests/Unit/Models/ProductModelTest.php
git commit -m "$(cat <<'EOF'
feat: extend ProductModel::clone() to move an image onto the new product

Adds an optional $imageId param that reassigns one product_images row
(and any subtypes) onto the newly cloned product, promotes a new
primary on the source if needed, and deactivates the source once it
has zero images left — the building block for splitting a multi-color
product into one product per color.
EOF
)"
```

---

### Task 2: Controller action and route

**Files:**
- Modify: `src/routes.php:57` (insert new route after the existing `/clone` route)
- Modify: `src/Controllers/Admin/ProductController.php:118-131` (add `cloneWithImage()` method after `clone()`)

**Interfaces:**
- Consumes: `ProductModel::clone(int $id, int $userId, ?int $imageId = null): ?int` (Task 1); `ProductModel::findById(int $id): ?array`; `\App\Services\Notifier::notify(string $entityType, int $entityId, string $entityLabel, string $action, int $actorId, string $actorLabel): void`; `AdminBaseController::flash(string $type, string $key): void`; `AdminBaseController::redirect(Response $response, string $url): Response`.
- Produces: `POST /admin/products/{id}/clone-image/{image_id}` route handled by `ProductController::cloneWithImage()`. Task 3's template links to this route.

- [ ] **Step 1: Add the route**

In `src/routes.php`, change:

```php
    $group->post('/products/{id:[0-9]+}/clone',                           ProductController::class . ':clone');
    $group->post('/products/{id:[0-9]+}/delete',                          ProductController::class . ':delete');
```

to:

```php
    $group->post('/products/{id:[0-9]+}/clone',                           ProductController::class . ':clone');
    $group->post('/products/{id:[0-9]+}/clone-image/{image_id:[0-9]+}',   ProductController::class . ':cloneWithImage');
    $group->post('/products/{id:[0-9]+}/delete',                          ProductController::class . ':delete');
```

- [ ] **Step 2: Add the controller method**

In `src/Controllers/Admin/ProductController.php`, add this method immediately after `clone()` (after line 131, before `deleteImage()`):

```php
    public function cloneWithImage(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $newId  = ProductModel::clone((int) $args['id'], $userId, (int) $args['image_id']);
        if ($newId === null) {
            return $response->withStatus(404);
        }
        $clone = ProductModel::findById($newId);
        \App\Services\Notifier::notify(
            'product', $newId, $clone['sku'], 'created', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'products.flash.split');
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
feat: add POST /admin/products/{id}/clone-image/{image_id} endpoint

Exposes the image-moving clone as its own route, mirroring the
existing delete-image endpoint shape, and redirects to the new
product's edit page so the admin can name it right away.
EOF
)"
```

---

### Task 3: Admin UI and translations

**Files:**
- Modify: `templates/admin/products/form.twig:119-127` (add split button per image thumbnail) and `templates/admin/products/form.twig:140-146` (add JS handler)
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json` (add `products.form.confirm_split`, `products.form.split_image`, `products.flash.split` — identical line numbers across all 5 files, confirmed below)

**Interfaces:**
- Consumes: `POST /admin/products/{id}/clone-image/{image_id}` route (Task 2); `t('products.form.split_image')` / `t('products.form.confirm_split')` via the existing `admin_i18n` Twig `t()` function.
- Produces: nothing consumed by later tasks — this is the final visible piece of the feature.

- [ ] **Step 1: Add translation keys to all 5 admin language files**

In each of `lang/admin/{cs,en,ru,uk,sk}.json`, insert a new line immediately after the existing `"products.flash.image_deleted": ...,` line (line 193), before `"products.flash.updated"` (line 194):

`lang/admin/cs.json`:
```json
  "products.flash.split": "Obrázek rozdělen do nového produktu. Nastavte jeho název a cenu.",
```

`lang/admin/en.json`:
```json
  "products.flash.split": "Image split into a new product. Set its name and price.",
```

`lang/admin/ru.json`:
```json
  "products.flash.split": "Изображение перемещено в новый товар. Задайте его название и цену.",
```

`lang/admin/uk.json`:
```json
  "products.flash.split": "Зображення переміщено в новий товар. Встановіть його назву та ціну.",
```

`lang/admin/sk.json`:
```json
  "products.flash.split": "Obrázok rozdelený do nového produktu. Nastavte jeho názov a cenu.",
```

Then, in each of the same 5 files, insert a new line immediately after the existing `"products.form.category": ...,` line (line 199, before this step's earlier insertion shifted anything above it — re-grep `"products.form.category"` to confirm its line number if unsure), before `"products.form.delete_image"`:

`lang/admin/cs.json`:
```json
  "products.form.confirm_split": "Rozdělit tento obrázek do nového produktu? Obrázek bude z tohoto produktu odebrán.",
```

`lang/admin/en.json`:
```json
  "products.form.confirm_split": "Split this image into a new product? The image will be moved off this product.",
```

`lang/admin/ru.json`:
```json
  "products.form.confirm_split": "Разделить это изображение в новый товар? Изображение будет перемещено из этого товара.",
```

`lang/admin/uk.json`:
```json
  "products.form.confirm_split": "Розділити це зображення в новий товар? Зображення буде переміщено з цього товару.",
```

`lang/admin/sk.json`:
```json
  "products.form.confirm_split": "Rozdeliť tento obrázok do nového produktu? Obrázok bude z tohto produktu odobratý.",
```

Then, in each of the same 5 files, insert a new line immediately after the existing `"products.form.sku_hint": ...,` line (line 210), before `"products.form.stock_label"`:

`lang/admin/cs.json`:
```json
  "products.form.split_image": "Rozdělit do nového produktu",
```

`lang/admin/en.json`:
```json
  "products.form.split_image": "Split into new product",
```

`lang/admin/ru.json`:
```json
  "products.form.split_image": "Разделить в новый товар",
```

`lang/admin/uk.json`:
```json
  "products.form.split_image": "Розділити в новий товар",
```

`lang/admin/sk.json`:
```json
  "products.form.split_image": "Rozdeliť do nového produktu",
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

- [ ] **Step 3: Add the split button and JS handler to the product edit form**

In `templates/admin/products/form.twig`, change (lines 119-124):

```twig
                {% for img in product.images %}
                <div style="text-align:center;">
                    <img src="/assets/uploads/products/thumb_{{ img.filename }}" class="img-thumb"><br>
                    <button type="button" class="btn-link delete-image-btn" style="font-size:0.8rem" data-url="/admin/products/{{ product.id }}/image/{{ img.id }}/delete">{{ t('products.form.delete_image') }}</button>
                </div>
                {% endfor %}
```

to:

```twig
                {% for img in product.images %}
                <div style="text-align:center;">
                    <img src="/assets/uploads/products/thumb_{{ img.filename }}" class="img-thumb"><br>
                    <button type="button" class="btn-link delete-image-btn" style="font-size:0.8rem" data-url="/admin/products/{{ product.id }}/image/{{ img.id }}/delete">{{ t('products.form.delete_image') }}</button>
                    <button type="button" class="btn-link split-image-btn" style="font-size:0.8rem" data-url="/admin/products/{{ product.id }}/clone-image/{{ img.id }}" data-confirm="{{ t('products.form.confirm_split') }}">{{ t('products.form.split_image') }}</button>
                </div>
                {% endfor %}
```

Then change the JS block (lines 140-146):

```javascript
// Delete-image buttons — plain fetch(), not a nested <form> (forms can't nest in valid HTML)
document.querySelectorAll('.delete-image-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        await fetch(btn.dataset.url, { method: 'POST' });
        location.reload();
    });
});
```

to:

```javascript
// Delete-image buttons — plain fetch(), not a nested <form> (forms can't nest in valid HTML)
document.querySelectorAll('.delete-image-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        await fetch(btn.dataset.url, { method: 'POST' });
        location.reload();
    });
});

// Split-image buttons — same fetch() convention as delete, but navigate to
// wherever the request redirected (the new product's edit page) instead of
// reloading in place.
document.querySelectorAll('.split-image-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        if (!confirm(btn.dataset.confirm)) return;
        const res = await fetch(btn.dataset.url, { method: 'POST' });
        location.href = res.url;
    });
});
```

- [ ] **Step 4: Manually verify the full flow in a browser**

Run:
```bash
docker compose up -d
php -S localhost:8080 -t www &
```

Then:
1. Open `http://localhost:8080/admin/login`, log in with an admin account (create one via `/admin/setup` first if needed).
2. Go to `http://localhost:8080/admin/products`, open a product that has 2+ images (upload a second test image via the edit form if none exists), and optionally add a variant under "Variants" to verify subtype copying.
3. On the edit page, click "Rozdělit do nového produktu" / "Split into new product" under one of the images, confirm the dialog.
4. Confirm: browser lands on `/admin/products/{newId}/edit` with a green flash message, the new product has exactly the one split-off image (marked primary), the same price/category/stock/translations as the source, and the same variants (if any) as the source.
5. Go back to the source product's edit page and confirm the split image is gone from its image list, and (if a primary image was split) a different image is now marked primary.
6. Repeat until the source product's last image is split off; confirm on `/admin/products` that the source now shows as inactive.

Stop the server afterward:
```bash
kill %1
```

- [ ] **Step 5: Run the full test suite one more time**

Run: `php vendor/bin/phpunit --testdox`
Expected: PASS, no regressions

- [ ] **Step 6: Commit**

```bash
git add templates/admin/products/form.twig lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/uk.json lang/admin/sk.json
git commit -m "$(cat <<'EOF'
feat: add "split into new product" button to the product edit form

Wires the clone-image endpoint into the UI with translations in all 5
admin languages, completing the product image split feature used to
turn a multi-color product into one product per color.
EOF
)"
```

---

## Self-Review Notes

- **Spec coverage:** model-layer image move + subtype copy + primary promotion + source deactivation (Task 1, matches spec's "Model" section point-for-point); route/controller/notification/redirect (Task 2, matches spec's "Controller" section); UI button, JS navigation-on-redirect, translations, manual verification (Task 3, matches spec's "UI & translations" section). The spec's "Out of scope" items (auto-derived color names, batch split-all, changing `deleteImage()`, actually splitting product 10) have no corresponding task, as intended.
- **Placeholder scan:** no TBD/TODO; every step has complete, runnable code.
- **Type consistency:** `ProductModel::clone(int $id, int $userId, ?int $imageId = null): ?int` signature is identical across Task 1 (definition) and Task 2 (consumption). `Notifier::notify()` call matches its existing signature (already used by the plain `clone()` method). `setSubtypes(int $productId, array $rows)` is called with `$source['subtypes']`, whose shape (`price` + `t` map per row, from `getSubtypes()`) matches what `setSubtypes()` reads — same pattern already proven by the existing `test_set_subtypes_*` tests.
- **Regression safety:** Task 1's rewritten `clone()` keeps the two-argument call path (`$imageId` defaults to `null`) producing identical behavior to today — verified by the three pre-existing `clone` tests continuing to pass unmodified, not just by new tests.
