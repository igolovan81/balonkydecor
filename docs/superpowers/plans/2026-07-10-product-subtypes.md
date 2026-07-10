# Product Subtypes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a product optionally carry priced "subtypes" (e.g. balloon colors/finishes each with their own per-piece price), required at add-to-cart time once any exist, with the chosen subtype flowing through cart → order → order history.

**Architecture:** Two new tables (`product_subtypes`, `product_subtype_t`) alongside the existing `products`/`product_t` pattern. `ProductModel` gains subtype CRUD; `Cart` keys session lines by `sku` or `sku:subtypeId`; `OrderModel::create()` persists the chosen subtype per line. Admin manages subtypes as repeatable rows inline on the existing product form, auto-translated server-side like the rest of admin content.

**Tech Stack:** Slim 4 / PHP 8 / PDO+MySQL 8 / Twig 3 / vanilla JS, no build step — same as the rest of the app.

## Global Constraints

- Prepared statements with bound parameters for all SQL; no string interpolation of request data (`.claude/rules/database.md`).
- Every new user-facing string goes through `t('key')` and must be added to all 5 files: `lang/{cs,en,ru,uk,sk}.json` (public) or `lang/admin/{cs,en,ru,uk,sk}.json` (admin) — all five keep identical key sets (`.claude/rules/frontend.md`).
- New migration file: `database/migrations/V021__product_subtypes.sql`, never edit an applied migration (`.claude/rules/database.md`).
- Model tests run against real Docker MySQL, no mocks; unique fixtures via `uniqid()`, shared fixtures via `INSERT IGNORE` (`.claude/rules/unit-testing.md`).
- Run `php vendor/bin/phpunit` (whole suite) before considering any task done that touches PHP.
- Controllers are untested per convention — verify controller/template changes by rendering the page locally (`.claude/rules/unit-testing.md`).
- CSS: reuse design tokens in `:root`, flat kebab-case class names, no `!important` (`.claude/rules/css-styling.md`).
- All public links stay language-prefixed; admin routes stay inside the existing `/admin` group (`.claude/rules/backend.md`).

---

### Task 1: Migration — `product_subtypes` / `product_subtype_t` / `order_items` columns

**Files:**
- Create: `database/migrations/V021__product_subtypes.sql`

**Interfaces:**
- Produces: tables `product_subtypes(id, product_id, price, sort_order)`, `product_subtype_t(id, subtype_id, lang_code, name)`; new nullable columns `order_items.subtype_id`, `order_items.subtype_name_snapshot`. All later tasks depend on this schema existing in the local dev DB.

- [ ] **Step 1: Write the migration file**

```sql
CREATE TABLE `product_subtypes` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `price`      decimal(10,2) NOT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `product_subtype_t` (
  `id`          int NOT NULL AUTO_INCREMENT,
  `subtype_id`  int NOT NULL,
  `lang_code`   varchar(5) NOT NULL,
  `name`        varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subtype_lang` (`subtype_id`,`lang_code`),
  FOREIGN KEY (`subtype_id`) REFERENCES `product_subtypes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `order_items`
  ADD COLUMN `subtype_id` int NULL AFTER `product_id`,
  ADD COLUMN `subtype_name_snapshot` varchar(255) NULL AFTER `product_name_snapshot`,
  ADD FOREIGN KEY (`subtype_id`) REFERENCES `product_subtypes`(`id`) ON DELETE SET NULL;
```

- [ ] **Step 2: Confirm the local MySQL container and app server are up**

```bash
docker compose up -d
until docker compose exec -T db mysqladmin ping -ubalonky -pbalonky --silent 2>/dev/null; do sleep 1; done
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/cs/
```
Expected: `200`. If it's not `200`, start the server first: `php -S localhost:8080 -t www` (run in background), then re-check.

- [ ] **Step 3: Apply the migration via the existing migrate.php runner**

```bash
MIGRATE_TOKEN=$(php -r "echo (require 'config/settings.php')['migrate_token'];")
curl -s "http://localhost:8080/migrate.php?token=${MIGRATE_TOKEN}" | python3 -m json.tool
```
Expected: `{"applied": ["V021__product_subtypes"], "count": 1}`.

- [ ] **Step 4: Verify the schema landed**

```bash
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e "DESCRIBE product_subtypes; DESCRIBE product_subtype_t; DESCRIBE order_items;"
```
Expected: `product_subtypes` has `id, product_id, price, sort_order`; `product_subtype_t` has `id, subtype_id, lang_code, name`; `order_items` now includes `subtype_id` and `subtype_name_snapshot` columns.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/V021__product_subtypes.sql
git commit -m "feat: add product_subtypes schema"
```

---

### Task 2: `ProductModel::getSubtypes()` / `setSubtypes()`, wired into `findById()`

**Files:**
- Modify: `src/Models/ProductModel.php`
- Test: `tests/Unit/Models/ProductModelTest.php`

**Interfaces:**
- Consumes: `Database::getConnection()` (existing PDO singleton).
- Produces: `ProductModel::getSubtypes(int $productId): array` — rows shaped `{id: int, price: string, sort_order: int, t: array<string,string>}` ordered by `sort_order, id`. `ProductModel::setSubtypes(int $productId, array $rows): void` — `$rows` is a list of `{price: string, t: array<string,string>}` (lang_code => name); fully replaces the product's subtypes. `ProductModel::findById(int $id): ?array` now includes a `subtypes` key using the same shape as `getSubtypes()`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Models/ProductModelTest.php` (inside the `ProductModelTest` class, near the other `set_translations`-style tests):

```php
    public function test_set_subtypes_creates_and_returns_translated_rows(): void
    {
        $productId = $this->makeProduct();
        ProductModel::setSubtypes($productId, [
            ['price' => '1.90', 't' => ['cs' => 'Makarons', 'en' => 'Macarons']],
            ['price' => '3.40', 't' => ['cs' => 'Chrom', 'en' => 'Chrome']],
        ]);

        $product = ProductModel::findById($productId);
        $this->assertCount(2, $product['subtypes']);
        $this->assertSame('1.90', $product['subtypes'][0]['price']);
        $this->assertSame('Makarons', $product['subtypes'][0]['t']['cs']);
        $this->assertSame('Chrome', $product['subtypes'][1]['t']['en']);
    }

    public function test_set_subtypes_replaces_existing_rows(): void
    {
        $productId = $this->makeProduct();
        ProductModel::setSubtypes($productId, [
            ['price' => '1.00', 't' => ['cs' => 'A']],
            ['price' => '2.00', 't' => ['cs' => 'B']],
        ]);
        ProductModel::setSubtypes($productId, [
            ['price' => '3.00', 't' => ['cs' => 'C']],
        ]);

        $subtypes = ProductModel::getSubtypes($productId);
        $this->assertCount(1, $subtypes);
        $this->assertSame('C', $subtypes[0]['t']['cs']);
    }

    public function test_set_subtypes_skips_rows_with_no_names(): void
    {
        $productId = $this->makeProduct();
        ProductModel::setSubtypes($productId, [
            ['price' => '1.00', 't' => ['cs' => '', 'en' => '']],
            ['price' => '2.00', 't' => ['cs' => 'Valid']],
        ]);

        $subtypes = ProductModel::getSubtypes($productId);
        $this->assertCount(1, $subtypes);
        $this->assertSame('Valid', $subtypes[0]['t']['cs']);
    }
```

- [ ] **Step 2: Run to verify the tests fail**

```bash
php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php --filter test_set_subtypes
```
Expected: FAIL — `Call to undefined method App\Models\ProductModel::setSubtypes()`.

- [ ] **Step 3: Implement `getSubtypes()` and `setSubtypes()`**

Add to `src/Models/ProductModel.php`, after `getTranslations()`/`setTranslations()`:

```php
    public static function getSubtypes(int $productId): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, price, sort_order FROM product_subtypes WHERE product_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$productId]);
        $rows = $stmt->fetchAll();

        $tStmt = $pdo->prepare('SELECT lang_code, name FROM product_subtype_t WHERE subtype_id = ?');
        foreach ($rows as &$row) {
            $tStmt->execute([$row['id']]);
            $row['t'] = [];
            foreach ($tStmt->fetchAll() as $t) {
                $row['t'][$t['lang_code']] = $t['name'];
            }
        }
        unset($row);
        return $rows;
    }

    public static function setSubtypes(int $productId, array $rows): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM product_subtypes WHERE product_id = ?')->execute([$productId]);

        $insertSubtype = $pdo->prepare(
            'INSERT INTO product_subtypes (product_id, price, sort_order) VALUES (?, ?, ?)'
        );
        $insertName = $pdo->prepare(
            'INSERT INTO product_subtype_t (subtype_id, lang_code, name) VALUES (?, ?, ?)'
        );

        foreach (array_values($rows) as $index => $row) {
            $t = array_filter($row['t'] ?? [], fn ($name) => trim((string) $name) !== '');
            if (!$t) continue;

            $insertSubtype->execute([$productId, $row['price'] ?? '0.00', $index]);
            $subtypeId = (int) $pdo->lastInsertId();

            foreach ($t as $lang => $name) {
                $insertName->execute([$subtypeId, $lang, trim((string) $name)]);
            }
        }
    }
```

Then wire `getSubtypes()` into `findById()` — in the existing method, right before `return $product;`:

```php
        $product['subtypes'] = self::getSubtypes($id);
        return $product;
```

- [ ] **Step 4: Run to verify the tests pass**

```bash
php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php
```
Expected: PASS, whole file (this also re-runs the pre-existing tests to confirm `findById()`'s new `subtypes` key doesn't break anything).

- [ ] **Step 5: Commit**

```bash
git add src/Models/ProductModel.php tests/Unit/Models/ProductModelTest.php
git commit -m "feat: add ProductModel::getSubtypes/setSubtypes"
```

---

### Task 3: `ProductModel::findBySku()` resolves subtypes for the requested language

**Files:**
- Modify: `src/Models/ProductModel.php`
- Test: `tests/Unit/Models/ProductModelTest.php`

**Interfaces:**
- Consumes: `product_subtypes`/`product_subtype_t` (Task 1), `ProductModel::setSubtypes()` (Task 2).
- Produces: `findBySku()`'s return array gains a `subtypes` key: list of `{id: int, price: string, name: string}`, resolved to the `$lang` argument, ordered by `sort_order, id`. Empty array when the product has none. This is what `CartController::add()` (Task 7) and `shop/product.twig` (Task 9) consume.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Models/ProductModelTest.php`:

```php
    public function test_find_by_sku_resolves_subtype_names_for_requested_lang(): void
    {
        $productId = $this->makeProduct();
        $sku       = $this->skuOf($productId);
        ProductModel::setSubtypes($productId, [
            ['price' => '1.90', 't' => ['cs' => 'Makarons', 'en' => 'Macarons']],
        ]);

        $product = ProductModel::findBySku($sku, 'en');
        $this->assertCount(1, $product['subtypes']);
        $this->assertSame('Macarons', $product['subtypes'][0]['name']);
        $this->assertSame('1.90', $product['subtypes'][0]['price']);
    }

    public function test_find_by_sku_subtypes_empty_without_any(): void
    {
        $product = ProductModel::findBySku('TEST-SKU-001', 'en');
        $this->assertSame([], $product['subtypes']);
    }
```

Add the small helper next to `makeProduct()` (near the other private helpers at the bottom of the class):

```php
    private function skuOf(int $productId): string
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT sku FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        return (string) $stmt->fetchColumn();
    }
```

- [ ] **Step 2: Run to verify it fails**

```bash
php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php --filter test_find_by_sku_resolves_subtype_names_for_requested_lang
```
Expected: FAIL — `Undefined array key "subtypes"`.

- [ ] **Step 3: Implement**

In `findBySku()`, right before `return $product;`, replacing the existing tail of the method:

```php
        $imgs = $pdo->prepare('SELECT filename FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
        $imgs->execute([$product['id']]);
        $product['images'] = $imgs->fetchAll(\PDO::FETCH_COLUMN);

        $subStmt = $pdo->prepare(
            'SELECT ps.id, ps.price, st.name
             FROM product_subtypes ps
             JOIN product_subtype_t st ON st.subtype_id = ps.id AND st.lang_code = ?
             WHERE ps.product_id = ?
             ORDER BY ps.sort_order, ps.id'
        );
        $subStmt->execute([$lang, $product['id']]);
        $product['subtypes'] = $subStmt->fetchAll();

        return $product;
```

(This replaces the two lines currently ending the method — keep the existing `$imgs`/`images` lines as-is, just add the new block before the final `return`.)

- [ ] **Step 4: Run to verify it passes**

```bash
php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php
```
Expected: PASS, whole file.

- [ ] **Step 5: Commit**

```bash
git add src/Models/ProductModel.php tests/Unit/Models/ProductModelTest.php
git commit -m "feat: resolve product subtypes by language in findBySku"
```

---

### Task 4: `ProductModel::allActive()` reports `min_subtype_price`

**Files:**
- Modify: `src/Models/ProductModel.php`
- Test: `tests/Unit/Models/ProductModelTest.php`

**Interfaces:**
- Consumes: `product_subtypes` (Task 1).
- Produces: each row from `allActive()` gains `min_subtype_price` (string decimal, or `null` if the product has no subtypes). Consumed by `shop/index.twig` (Task 10).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Models/ProductModelTest.php`:

```php
    public function test_all_active_reports_min_subtype_price_for_products_with_subtypes(): void
    {
        $productId = $this->makeProduct();
        ProductModel::setSubtypes($productId, [
            ['price' => '1.90', 't' => ['cs' => 'Makarons']],
            ['price' => '1.20', 't' => ['cs' => 'SDM']],
        ]);

        $row = $this->findActiveRow($productId);
        $this->assertSame('1.20', $row['min_subtype_price']);
    }

    public function test_all_active_min_subtype_price_is_null_without_subtypes(): void
    {
        $productId = $this->makeProduct();
        $row       = $this->findActiveRow($productId);
        $this->assertNull($row['min_subtype_price']);
    }

    private function findActiveRow(int $productId): array
    {
        foreach (ProductModel::allActive('en', self::$categoryId) as $row) {
            if ((int) $row['id'] === $productId) {
                return $row;
            }
        }
        $this->fail('Product ' . $productId . ' not found in allActive() results');
    }
```

- [ ] **Step 2: Run to verify it fails**

```bash
php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php --filter test_all_active_reports_min_subtype_price
```
Expected: FAIL — `Undefined array key "min_subtype_price"`.

- [ ] **Step 3: Implement**

In `allActive()`, add the subquery column to the `SELECT`:

```php
        $sql    = '
            SELECT p.id, p.category_id, p.sku, p.price, p.stock_type, p.stock_qty,
                   COALESCE(t.name, p.sku) AS name,
                   t.description,
                   i.filename AS primary_image,
                   (SELECT MIN(price) FROM product_subtypes WHERE product_id = p.id) AS min_subtype_price
            FROM products p
            LEFT JOIN product_t t ON t.product_id = p.id AND t.lang_code = :lang
            LEFT JOIN product_images i ON i.product_id = p.id AND i.is_primary = 1
            WHERE p.is_active = 1
        ';
```

- [ ] **Step 4: Run to verify it passes**

```bash
php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php
```
Expected: PASS, whole file.

- [ ] **Step 5: Commit**

```bash
git add src/Models/ProductModel.php tests/Unit/Models/ProductModelTest.php
git commit -m "feat: report min subtype price from allActive()"
```

---

### Task 5: `Cart::add()` supports a subtype, keyed as a distinct line

**Files:**
- Modify: `src/Services/Cart.php`
- Test: `tests/Unit/Services/CartTest.php`

**Interfaces:**
- Produces: `Cart::add(string $sku, int $qty, string $name, string $price, ?int $subtypeId = null, ?string $subtypeName = null): void`. Session line key is `"{$sku}:{$subtypeId}"` when `$subtypeId !== null`, else `$sku` (unchanged behavior for non-subtype products). Every stored item now also carries `sku`, `subtype_id`, `subtype_name` fields (the last two `null` for plain products) — consumed by `OrderModel::create()` (Task 6).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Services/CartTest.php`:

```php
    public function test_add_with_subtype_creates_distinct_line(): void
    {
        Cart::add('SKU-1', 1, 'Balloon — Macarons', '1.90', 10, 'Macarons');
        Cart::add('SKU-1', 1, 'Balloon — Chrome', '3.40', 20, 'Chrome');
        $items = Cart::items();
        $this->assertArrayHasKey('SKU-1:10', $items);
        $this->assertArrayHasKey('SKU-1:20', $items);
        $this->assertSame(1, $items['SKU-1:10']['qty']);
    }

    public function test_add_with_same_subtype_accumulates_qty(): void
    {
        Cart::add('SKU-1', 1, 'Balloon — Macarons', '1.90', 10, 'Macarons');
        Cart::add('SKU-1', 2, 'Balloon — Macarons', '1.90', 10, 'Macarons');
        $this->assertSame(3, Cart::items()['SKU-1:10']['qty']);
    }

    public function test_add_with_subtype_stores_sku_and_subtype_fields(): void
    {
        Cart::add('SKU-1', 1, 'Balloon — Macarons', '1.90', 10, 'Macarons');
        $item = Cart::items()['SKU-1:10'];
        $this->assertSame('SKU-1', $item['sku']);
        $this->assertSame(10, $item['subtype_id']);
        $this->assertSame('Macarons', $item['subtype_name']);
    }

    public function test_add_without_subtype_still_stores_sku(): void
    {
        Cart::add('SKU-1', 1, 'Red Balloon', '49.00');
        $this->assertSame('SKU-1', Cart::items()['SKU-1']['sku']);
        $this->assertNull(Cart::items()['SKU-1']['subtype_id']);
    }
```

- [ ] **Step 2: Run to verify it fails**

```bash
php vendor/bin/phpunit tests/Unit/Services/CartTest.php --filter test_add_with_subtype_creates_distinct_line
```
Expected: FAIL — `Too few arguments to function App\Services\Cart::add()`.

- [ ] **Step 3: Implement**

Replace `Cart::add()` in `src/Services/Cart.php`:

```php
    public static function add(
        string $sku, int $qty, string $name, string $price,
        ?int $subtypeId = null, ?string $subtypeName = null
    ): void {
        self::boot();
        $key = $subtypeId !== null ? $sku . ':' . $subtypeId : $sku;
        if (isset($_SESSION['cart'][$key])) {
            $_SESSION['cart'][$key]['qty'] += $qty;
        } else {
            $_SESSION['cart'][$key] = [
                'qty'          => $qty,
                'name'         => $name,
                'price'        => $price,
                'sku'          => $sku,
                'subtype_id'   => $subtypeId,
                'subtype_name' => $subtypeName,
            ];
        }
    }
```

- [ ] **Step 4: Run to verify it passes**

```bash
php vendor/bin/phpunit tests/Unit/Services/CartTest.php
```
Expected: PASS, whole file (confirms existing non-subtype tests still pass unchanged).

- [ ] **Step 5: Commit**

```bash
git add src/Services/Cart.php tests/Unit/Services/CartTest.php
git commit -m "feat: support subtype-specific cart lines"
```

---

### Task 6: `OrderModel::create()` persists the chosen subtype

**Files:**
- Modify: `src/Models/OrderModel.php`
- Test: `tests/Unit/Models/OrderModelTest.php`

**Interfaces:**
- Consumes: `order_items.subtype_id`/`subtype_name_snapshot` (Task 1); cart item shape from `Cart::add()` (Task 5) — reads `$item['sku']` (falls back to the loop key if absent, so the pre-existing test fixture that doesn't set `sku` keeps working), `$item['subtype_id']`, `$item['subtype_name']`.
- Produces: no signature change to `OrderModel::create()`; `order_items` rows now carry the subtype when present.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Models/OrderModelTest.php`:

```php
    public function test_create_persists_subtype_id_and_name_snapshot(): void
    {
        $pdo   = \App\Models\Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO categories (slug) VALUES ('test-order-subtype')");
        $catId = $pdo->query("SELECT id FROM categories WHERE slug='test-order-subtype'")->fetch()['id'];

        $sku = 'ORDER-SUB-' . uniqid();
        $pdo->prepare('INSERT INTO products (category_id, sku, price) VALUES (?, ?, 9.99)')
            ->execute([$catId, $sku]);
        $productId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO product_subtypes (product_id, price, sort_order) VALUES (?, ?, 0)')
            ->execute([$productId, '1.90']);
        $subtypeId = (int) $pdo->lastInsertId();

        $orderNumber = OrderModel::create(
            [
                'customer_name'  => 'Subtype Buyer',
                'customer_email' => 'sub@example.com',
                'customer_phone' => '+420000000000',
                'pickup_date'    => '2026-12-31',
                'notes'          => '',
            ],
            [
                $sku . ':' . $subtypeId => [
                    'sku' => $sku, 'subtype_id' => $subtypeId, 'subtype_name' => 'Makarons',
                    'qty' => 2, 'name' => 'Test — Makarons', 'price' => '1.90', 'subtotal' => '3.80',
                ],
            ],
            '3.80'
        );

        $order = OrderModel::findByNumber($orderNumber);
        $this->assertSame($subtypeId, (int) $order['items'][0]['subtype_id']);
        $this->assertSame('Makarons', $order['items'][0]['subtype_name_snapshot']);
        $this->assertSame($productId, (int) $order['items'][0]['product_id']);
    }
```

- [ ] **Step 2: Run to verify it fails**

```bash
php vendor/bin/phpunit tests/Unit/Models/OrderModelTest.php --filter test_create_persists_subtype_id_and_name_snapshot
```
Expected: FAIL — `SQLSTATE[42S22]: Column not found` or a null/mismatch assertion failure (columns exist from Task 1, but `create()` doesn't populate them yet, so `subtype_id` comes back `null`).

- [ ] **Step 3: Implement**

Replace the item-insert block in `OrderModel::create()`:

```php
        $itemStmt = $pdo->prepare('
            INSERT INTO order_items
                (order_id, product_id, subtype_id, quantity, unit_price, product_name_snapshot, subtype_name_snapshot)
            VALUES (?, (SELECT id FROM products WHERE sku = ? LIMIT 1), ?, ?, ?, ?, ?)
        ');
        foreach ($cartItems as $key => $item) {
            $sku = $item['sku'] ?? $key;
            $itemStmt->execute([
                $id, $sku, $item['subtype_id'] ?? null,
                $item['qty'], $item['price'], $item['name'], $item['subtype_name'] ?? null,
            ]);
        }
```

- [ ] **Step 4: Run to verify it passes**

```bash
php vendor/bin/phpunit tests/Unit/Models/OrderModelTest.php
```
Expected: PASS, whole file (confirms the pre-existing `test_create_returns_order_number`/`test_find_by_number_returns_order` fixture — which has no `sku`/`subtype_id` keys — still works via the `?? $key` fallback).

- [ ] **Step 5: Commit**

```bash
git add src/Models/OrderModel.php tests/Unit/Models/OrderModelTest.php
git commit -m "feat: persist chosen subtype on order_items"
```

---

### Task 7: `CartController::add()` requires a subtype when the product has any

**Files:**
- Modify: `src/Controllers/CartController.php`

**Interfaces:**
- Consumes: `ProductModel::findBySku()`'s `subtypes` key (Task 3), `Cart::add()`'s new params (Task 5).
- Produces: no route/signature change — `POST /{lang}/cart/add` now also reads an optional `subtype_id` field from the request body.

- [ ] **Step 1: Implement**

Replace `CartController::add()`:

```php
    public function add(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        $body = (array) $request->getParsedBody();
        $sku  = trim($body['sku'] ?? '');
        $qty  = max(1, (int) ($body['qty'] ?? 1));

        if ($sku) {
            $product = ProductModel::findBySku($sku, $lang);
            if ($product) {
                if (!empty($product['subtypes'])) {
                    $subtypeId = isset($body['subtype_id']) && $body['subtype_id'] !== ''
                        ? (int) $body['subtype_id'] : null;
                    $subtype = null;
                    foreach ($product['subtypes'] as $st) {
                        if ((int) $st['id'] === $subtypeId) {
                            $subtype = $st;
                            break;
                        }
                    }
                    if ($subtype) {
                        Cart::add(
                            $sku, $qty,
                            $product['name'] . ' — ' . $subtype['name'],
                            (string) $subtype['price'],
                            (int) $subtype['id'], $subtype['name']
                        );
                    }
                    // no valid subtype_id posted → do nothing, cart unchanged
                } else {
                    Cart::add($sku, $qty, $product['name'], (string) $product['price']);
                }
            }
        }

        return $response->withHeader('Location', "/{$lang}/cart")->withStatus(302);
    }
```

- [ ] **Step 2: No controller tests exist for this class (project convention)** — verified manually in Task 14's smoke check instead. No commit-blocking test to run here, but do run the full suite to confirm nothing else broke:

```bash
php vendor/bin/phpunit
```
Expected: PASS (all suites green — this task changes no tested surface, so this is a regression check).

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/CartController.php
git commit -m "feat: require subtype selection when adding a subtyped product to cart"
```

---

### Task 8: Public translation keys — `shop.from_price`, `shop.subtype`

**Files:**
- Modify: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`

**Interfaces:**
- Produces: two new keys consumed by Task 9 (`shop.subtype`, product page selector label) and Task 10 (`shop.from_price`, shop grid card price).

- [ ] **Step 1: Add the keys to each file**

In `lang/cs.json`, replace:
```json
  "shop.add_to_cart": "Přidat do košíku",
  "shop.all": "Vše",
  "shop.no_products": "Žádné produkty v této kategorii.",
  "shop.qty": "Množství",
```
with:
```json
  "shop.add_to_cart": "Přidat do košíku",
  "shop.all": "Vše",
  "shop.from_price": "od {price} Kč",
  "shop.no_products": "Žádné produkty v této kategorii.",
  "shop.qty": "Množství",
  "shop.subtype": "Varianta",
```

In `lang/en.json`, replace:
```json
  "shop.add_to_cart": "Add to cart",
  "shop.all": "All",
  "shop.no_products": "No products in this category.",
  "shop.qty": "Quantity",
```
with:
```json
  "shop.add_to_cart": "Add to cart",
  "shop.all": "All",
  "shop.from_price": "from {price} Kč",
  "shop.no_products": "No products in this category.",
  "shop.qty": "Quantity",
  "shop.subtype": "Variant",
```

In `lang/ru.json`, replace:
```json
  "shop.add_to_cart": "В корзину",
  "shop.all": "Все",
  "shop.no_products": "Нет товаров в этой категории.",
  "shop.qty": "Количество",
```
with:
```json
  "shop.add_to_cart": "В корзину",
  "shop.all": "Все",
  "shop.from_price": "от {price} Kč",
  "shop.no_products": "Нет товаров в этой категории.",
  "shop.qty": "Количество",
  "shop.subtype": "Вариант",
```

In `lang/uk.json`, replace:
```json
  "shop.add_to_cart": "До кошика",
  "shop.all": "Усі",
  "shop.no_products": "Немає товарів у цій категорії.",
  "shop.qty": "Кількість",
```
with:
```json
  "shop.add_to_cart": "До кошика",
  "shop.all": "Усі",
  "shop.from_price": "від {price} Kč",
  "shop.no_products": "Немає товарів у цій категорії.",
  "shop.qty": "Кількість",
  "shop.subtype": "Варіант",
```

In `lang/sk.json`, replace:
```json
  "shop.add_to_cart": "Pridať do košíka",
  "shop.all": "Všetko",
  "shop.no_products": "Žiadne produkty v tejto kategórii.",
  "shop.qty": "Množstvo",
```
with:
```json
  "shop.add_to_cart": "Pridať do košíka",
  "shop.all": "Všetko",
  "shop.from_price": "od {price} Kč",
  "shop.no_products": "Žiadne produkty v tejto kategórii.",
  "shop.qty": "Množstvo",
  "shop.subtype": "Variant",
```

- [ ] **Step 2: Verify all 5 files still parse and have identical key sets**

```bash
for f in cs en ru uk sk; do php -r "json_decode(file_get_contents('lang/$f.json'), true, 512, JSON_THROW_ON_ERROR); echo '$f OK\n';"; done
php -r '
$sets = [];
foreach (["cs","en","ru","uk","sk"] as $l) {
    $sets[$l] = array_keys(json_decode(file_get_contents("lang/$l.json"), true));
    sort($sets[$l]);
}
$base = $sets["cs"];
foreach ($sets as $l => $keys) {
    if ($keys !== $base) { echo "MISMATCH: $l\n"; exit(1); }
}
echo "All key sets identical\n";
'
```
Expected: `cs OK` ... `sk OK`, then `All key sets identical`.

- [ ] **Step 3: Commit**

```bash
git add lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json
git commit -m "feat: add shop.from_price and shop.subtype translation keys"
```

---

### Task 9: Product page — subtype selector, live price, JSON-LD

**Files:**
- Modify: `src/Controllers/ShopController.php`
- Modify: `templates/public/shop/product.twig`
- Modify: `www/assets/css/style.css`

**Interfaces:**
- Consumes: `product.subtypes` (Task 3), `shop.subtype`/`shop.add_to_cart`/`shop.qty` translation keys (Task 8, existing).
- Produces: `ShopController::product()` passes `min_subtype_price`/`max_subtype_price` (nullable strings) alongside `product` to the template.

- [ ] **Step 1: Update the controller**

In `src/Controllers/ShopController.php`, replace `product()`:

```php
    public function product(Request $request, Response $response, array $args): Response
    {
        $lang    = $request->getAttribute('lang');
        $product = ProductModel::findBySku($args['slug'], $lang);

        if (!$product) {
            return $response->withStatus(404);
        }

        $subtypePrices = array_column($product['subtypes'], 'price');

        return $this->render($request, $response, 'public/shop/product.twig', [
            'product'          => $product,
            'min_subtype_price' => $subtypePrices ? min($subtypePrices) : null,
            'max_subtype_price' => $subtypePrices ? max($subtypePrices) : null,
        ]);
    }
```

- [ ] **Step 2: Update the JSON-LD block in `templates/public/shop/product.twig`**

Replace the `{% block head %}` contents:

```twig
{% block head %}
{% set base_offer = {
    'priceCurrency': 'CZK',
    'availability': (product.stock_type == 'limited' and product.stock_qty <= 0) ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
    'url': canonical_url
} %}
{% set offer = product.subtypes
    ? base_offer|merge({'@type': 'AggregateOffer', 'lowPrice': min_subtype_price, 'highPrice': max_subtype_price, 'offerCount': product.subtypes|length})
    : base_offer|merge({'@type': 'Offer', 'price': product.price}) %}
{% set product_schema = {
    '@context': 'https://schema.org',
    '@type': 'Product',
    'name': product.name,
    'description': product.description ? product.description|striptags : '',
    'sku': product.sku,
    'offers': offer
} %}
{% if product.images %}
    {% set product_schema = product_schema|merge({'image': base_url ~ '/assets/uploads/products/' ~ product.images[0]}) %}
{% endif %}
<script type="application/ld+json">{{ product_schema|json_encode|raw }}</script>
<script type="application/ld+json">{{ {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    'itemListElement': [
        {'@type': 'ListItem', 'position': 1, 'name': t('nav.home'), 'item': base_url ~ '/' ~ lang ~ '/'},
        {'@type': 'ListItem', 'position': 2, 'name': t('nav.shop'), 'item': base_url ~ '/' ~ lang ~ '/shop'},
        {'@type': 'ListItem', 'position': 3, 'name': product.name, 'item': canonical_url}
    ]
}|json_encode|raw }}</script>
{% endblock %}
```

- [ ] **Step 3: Update the price/form markup in the same file**

Replace the `product-detail-info` block:

```twig
    <div class="product-detail-info">
        <h1>{{ product.name }}</h1>
        {% if product.subtypes %}
        <p class="product-price-lg" id="subtype-price">{{ product.subtypes[0].price|number_format(2, '.', ' ') }} Kč</p>
        {% else %}
        <p class="product-price-lg">{{ product.price|number_format(2, '.', ' ') }} Kč</p>
        {% endif %}
        {% if product.description %}
        <div class="product-description">{{ product.description|nl2br }}</div>
        {% endif %}

        <form action="/{{ lang }}/cart/add" method="POST" class="add-to-cart-form">
            {% if product.subtypes %}
            <div class="qty-row">
                <label for="subtype-select">{{ t('shop.subtype') }}</label>
                <select name="subtype_id" id="subtype-select" class="subtype-select">
                    {% for subtype in product.subtypes %}
                    <option value="{{ subtype.id }}" data-price="{{ subtype.price }}">{{ subtype.name }} — {{ subtype.price|number_format(2, '.', ' ') }} Kč</option>
                    {% endfor %}
                </select>
            </div>
            {% endif %}
            <div class="qty-row">
                <label for="qty">{{ t('shop.qty') }}</label>
                <input type="number" id="qty" name="qty" value="1" min="1" class="qty-input">
            </div>
            <input type="hidden" name="sku" value="{{ product.sku }}">
            <button type="submit" class="btn btn-primary btn-lg">{{ t('shop.add_to_cart') }}</button>
        </form>
    </div>
```

- [ ] **Step 4: Add the price-sync script to the same file's scripts block**

Replace `{% block scripts %}`:

```twig
{% block scripts %}
{{ parent() }}
<script src="/assets/js/product-gallery.js" defer></script>
{% if product.subtypes %}
<script>
document.addEventListener('DOMContentLoaded', function () {
    var select  = document.getElementById('subtype-select');
    var priceEl = document.getElementById('subtype-price');
    if (!select || !priceEl) return;
    select.addEventListener('change', function () {
        var price = parseFloat(select.options[select.selectedIndex].dataset.price);
        priceEl.textContent = price.toLocaleString('cs-CZ', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' Kč';
    });
});
</script>
{% endif %}
{% endblock %}
```

- [ ] **Step 5: Add minimal select styling to `www/assets/css/style.css`**

Add next to the existing `.qty-input` rule (around line 140):

```css
.subtype-select { padding: .5rem; border: 1px solid var(--border); font-size: 1rem; }
```

And add `.subtype-select` to the existing 480px iOS-zoom-prevention rule (around line 306):

```css
    .contact-form input, .contact-form textarea, .add-to-cart-form .qty-input, .subtype-select { font-size: 16px; }
```

- [ ] **Step 6: Manual verification**

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/cs/shop
```
Expected: `200`. Then, in the admin panel (once Task 12/13 land, or temporarily by inserting a test row directly via `docker compose exec -T db mysql ...`), open a product with subtypes at `http://localhost:8080/cs/shop/{sku}` in a browser and confirm: the `<select>` lists each subtype with its price, changing the selection updates the displayed price, and view-source shows valid `AggregateOffer` JSON-LD.

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/ShopController.php templates/public/shop/product.twig www/assets/css/style.css
git commit -m "feat: subtype selector and pricing on the product page"
```

---

### Task 10: Shop grid — "from X Kč" price on cards with subtypes

**Files:**
- Modify: `templates/public/shop/index.twig`

**Interfaces:**
- Consumes: `product.min_subtype_price` (Task 4), `shop.from_price` (Task 8).

- [ ] **Step 1: Update the price line**

Replace:
```twig
                <div class="product-info">
                    <h3>{{ product.name }}</h3>
                    <p class="product-price">{{ product.price|number_format(2, '.', ' ') }} Kč</p>
                </div>
```
with:
```twig
                <div class="product-info">
                    <h3>{{ product.name }}</h3>
                    {% if product.min_subtype_price is not null %}
                    <p class="product-price">{{ t('shop.from_price', {price: product.min_subtype_price|number_format(2, '.', ' ')}) }}</p>
                    {% else %}
                    <p class="product-price">{{ product.price|number_format(2, '.', ' ') }} Kč</p>
                    {% endif %}
                </div>
```

- [ ] **Step 2: Manual verification**

```bash
curl -s http://localhost:8080/cs/shop | grep -o 'class="product-price"[^<]*<[^>]*>[^<]*' | head -5
```
Expected: existing plain-price products unaffected; once Task 12/13 land and a product has subtypes, its card shows "od X Kč" instead.

- [ ] **Step 3: Commit**

```bash
git add templates/public/shop/index.twig
git commit -m "feat: show \"from\" price on shop cards for subtyped products"
```

---

### Task 11: Admin translation keys for the subtypes form section

**Files:**
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`

**Interfaces:**
- Produces: keys consumed by Task 12 (`products.form.subtypes`, `products.form.subtype_name_label`, `products.form.subtype_price`, `products.form.subtype_add`, `products.form.subtype_remove`).

- [ ] **Step 1: Add the keys to each file**

In `lang/admin/cs.json`, replace:
```json
  "products.form.stock_unlimited": "Neomezeno",
  "products.form.title_edit": "Upravit produkt",
```
with:
```json
  "products.form.stock_unlimited": "Neomezeno",
  "products.form.subtype_add": "+ Přidat variantu",
  "products.form.subtype_name_label": "Název varianty",
  "products.form.subtype_price": "Cena varianty (Kč)",
  "products.form.subtype_remove": "Odebrat",
  "products.form.subtypes": "Varianty",
  "products.form.title_edit": "Upravit produkt",
```

In `lang/admin/en.json`, replace:
```json
  "products.form.stock_unlimited": "Unlimited",
  "products.form.title_edit": "Edit product",
```
with:
```json
  "products.form.stock_unlimited": "Unlimited",
  "products.form.subtype_add": "+ Add variant",
  "products.form.subtype_name_label": "Variant name",
  "products.form.subtype_price": "Variant price (CZK)",
  "products.form.subtype_remove": "Remove",
  "products.form.subtypes": "Variants",
  "products.form.title_edit": "Edit product",
```

In `lang/admin/ru.json`, replace:
```json
  "products.form.stock_unlimited": "Неограничено",
  "products.form.title_edit": "Изменить товар",
```
with:
```json
  "products.form.stock_unlimited": "Неограничено",
  "products.form.subtype_add": "+ Добавить вариант",
  "products.form.subtype_name_label": "Название варианта",
  "products.form.subtype_price": "Цена варианта (Kč)",
  "products.form.subtype_remove": "Удалить",
  "products.form.subtypes": "Варианты",
  "products.form.title_edit": "Изменить товар",
```

In `lang/admin/uk.json`, replace:
```json
  "products.form.stock_unlimited": "Необмежено",
  "products.form.title_edit": "Редагувати товар",
```
with:
```json
  "products.form.stock_unlimited": "Необмежено",
  "products.form.subtype_add": "+ Додати варіант",
  "products.form.subtype_name_label": "Назва варіанта",
  "products.form.subtype_price": "Ціна варіанта (Kč)",
  "products.form.subtype_remove": "Видалити",
  "products.form.subtypes": "Варіанти",
  "products.form.title_edit": "Редагувати товар",
```

In `lang/admin/sk.json`, replace:
```json
  "products.form.stock_unlimited": "Neobmedzené",
  "products.form.title_edit": "Upraviť produkt",
```
with:
```json
  "products.form.stock_unlimited": "Neobmedzené",
  "products.form.subtype_add": "+ Pridať variantu",
  "products.form.subtype_name_label": "Názov varianty",
  "products.form.subtype_price": "Cena varianty (Kč)",
  "products.form.subtype_remove": "Odobrať",
  "products.form.subtypes": "Varianty",
  "products.form.title_edit": "Upraviť produkt",
```

- [ ] **Step 2: Verify all 5 files still parse and have identical key sets**

```bash
for f in cs en ru uk sk; do php -r "json_decode(file_get_contents('lang/admin/$f.json'), true, 512, JSON_THROW_ON_ERROR); echo '$f OK\n';"; done
php -r '
$sets = [];
foreach (["cs","en","ru","uk","sk"] as $l) {
    $sets[$l] = array_keys(json_decode(file_get_contents("lang/admin/$l.json"), true));
    sort($sets[$l]);
}
$base = $sets["cs"];
foreach ($sets as $l => $keys) {
    if ($keys !== $base) { echo "MISMATCH: $l\n"; exit(1); }
}
echo "All key sets identical\n";
'
```
Expected: `cs OK` ... `sk OK`, then `All key sets identical`.

- [ ] **Step 3: Commit**

```bash
git add lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/uk.json lang/admin/sk.json
git commit -m "feat: add admin translation keys for product subtypes"
```

---

### Task 12: Admin product form — repeatable subtype rows

**Files:**
- Modify: `templates/admin/products/form.twig`
- Modify: `www/assets/css/admin.css`

**Interfaces:**
- Consumes: `product.subtypes` (Task 2, shape `{id, price, sort_order, t: {lang: name}}`), `admin_lang` (existing request attribute), translation keys from Task 11.
- Produces: form posts `subtypes[{i}][name]` (string) and `subtypes[{i}][price]` (string) per row — consumed by Task 13's `buildSubtypes()`.

- [ ] **Step 1: Add the Subtypes section markup**

In `templates/admin/products/form.twig`, insert right after the closing `{% endfor %}` of the language-tabs loop (i.e., right before the `</div>` that closes `.product-form-main`):

```twig
            <h3>{{ t('products.form.subtypes') }}</h3>
            <div id="subtype-rows">
                {% for subtype in product.subtypes ?? [] %}
                <div class="subtype-row">
                    <input type="text" name="subtypes[{{ loop.index0 }}][name]" value="{{ subtype.t[admin_lang] ?? '' }}" placeholder="{{ t('products.form.subtype_name_label') }}">
                    <input type="number" name="subtypes[{{ loop.index0 }}][price]" step="0.01" min="0" value="{{ subtype.price }}" placeholder="{{ t('products.form.subtype_price') }}">
                    <button type="button" class="btn-link subtype-remove-btn">{{ t('products.form.subtype_remove') }}</button>
                </div>
                {% endfor %}
            </div>
            <button type="button" id="subtype-add-btn" class="btn btn-secondary">{{ t('products.form.subtype_add') }}</button>

            <template id="subtype-row-template">
                <div class="subtype-row">
                    <input type="text" name="subtypes[__INDEX__][name]" placeholder="{{ t('products.form.subtype_name_label') }}">
                    <input type="number" name="subtypes[__INDEX__][price]" step="0.01" min="0" placeholder="{{ t('products.form.subtype_price') }}">
                    <button type="button" class="btn-link subtype-remove-btn">{{ t('products.form.subtype_remove') }}</button>
                </div>
            </template>
```

- [ ] **Step 2: Add the add/remove-row script**

In the same file's `{% block scripts %}`, append (before the closing `</script>` at the end of the existing script block — i.e., as its own IIFE alongside the existing ones):

```javascript
// Subtype rows — add/remove
(function () {
    var container = document.getElementById('subtype-rows');
    var addBtn    = document.getElementById('subtype-add-btn');
    var template  = document.getElementById('subtype-row-template');
    if (!container || !addBtn || !template) return;

    var nextIndex = container.querySelectorAll('.subtype-row').length;

    function bindRemove(row) {
        row.querySelector('.subtype-remove-btn').addEventListener('click', function () {
            row.remove();
        });
    }

    container.querySelectorAll('.subtype-row').forEach(bindRemove);

    addBtn.addEventListener('click', function () {
        var html    = template.innerHTML.replace(/__INDEX__/g, nextIndex);
        var wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();
        var row = wrapper.firstElementChild;
        container.appendChild(row);
        bindRemove(row);
        nextIndex++;
    });
})();
```

- [ ] **Step 3: Add row styling to `www/assets/css/admin.css`**

Add near the other `.product-form-*` rules:

```css
.subtype-row { display:flex; gap:0.75rem; align-items:center; margin-bottom:0.5rem; }
.subtype-row input[type="text"] { flex:2; }
.subtype-row input[type="number"] { flex:1; }
```

- [ ] **Step 4: Manual verification**

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/admin/login
```
Expected: `200`. Then, logged into the admin panel, open a product's edit form, click "+ Přidat variantu" a few times, confirm rows appear and "Odebrat" removes them, and that pre-existing subtypes (once Task 13 wires saving) render pre-filled on reload.

- [ ] **Step 5: Commit**

```bash
git add templates/admin/products/form.twig www/assets/css/admin.css
git commit -m "feat: admin UI for managing product subtypes"
```

---

### Task 13: Wire subtype saving into `Admin\ProductController`

**Files:**
- Modify: `src/Controllers/Admin/ProductController.php`

**Interfaces:**
- Consumes: `subtypes[{i}][name]`/`subtypes[{i}][price]` POST fields (Task 12), `Translator::autoFill()` (existing), `ProductModel::setSubtypes()` (Task 2).
- Produces: `createSubmit()`/`editSubmit()` now persist subtypes on every save.

- [ ] **Step 1: Add the `buildSubtypes()` helper**

In `src/Controllers/Admin/ProductController.php`, add as a private method (near `handleImageUpload()`):

```php
    private function buildSubtypes(array $rows, string $adminLang): array
    {
        $subtypes = [];
        foreach ($rows as $row) {
            $name = trim($row['name'] ?? '');
            if ($name === '') continue;

            $t = \App\Services\Translator::autoFill(
                [$adminLang => ['name' => $name]],
                $adminLang, self::LANGS, ['name']
            );
            $subtypes[] = [
                'price' => $row['price'] ?? '0.00',
                't'     => array_map(fn ($fields) => $fields['name'] ?? '', $t),
            ];
        }
        return $subtypes;
    }
```

- [ ] **Step 2: Call it from `createSubmit()`**

Right after the existing `ProductModel::setTranslations($id, $translations);` line, add:

```php
        ProductModel::setSubtypes($id, $this->buildSubtypes(
            $body['subtypes'] ?? [], $request->getAttribute('admin_lang', 'cs')
        ));
```

- [ ] **Step 3: Call it from `editSubmit()`**

Right after the existing `ProductModel::setTranslations($id, $body['t'] ?? []);` line, add:

```php
        ProductModel::setSubtypes($id, $this->buildSubtypes(
            $body['subtypes'] ?? [], $request->getAttribute('admin_lang', 'cs')
        ));
```

- [ ] **Step 4: Run the full suite as a regression check**

```bash
php vendor/bin/phpunit
```
Expected: PASS — this task only touches the untested controller, so this run confirms no model/service regressions.

- [ ] **Step 5: Manual verification**

In the admin panel: edit a product, add two subtype rows (e.g. "Makarons" / 1.90 and "Chrom" / 3.40) in Czech, save, then switch the admin language (via the existing language switcher) and reopen the product — confirm the subtype names appear auto-translated (MyMemory API — requires network; if it fails, the row's other-language slots simply stay blank, which is the existing `autoFill` failure behavior). Confirm removing all subtype rows and saving clears them (product reverts to using its base price).

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/Admin/ProductController.php
git commit -m "feat: save product subtypes from the admin form"
```

---

### Task 14: Full suite run and end-to-end smoke test

**Files:** none (verification only)

- [ ] **Step 1: Run the full automated test suite**

```bash
php vendor/bin/phpunit --testdox
```
Expected: all suites green, including every test added in Tasks 2–6.

- [ ] **Step 2: End-to-end manual walkthrough**

1. In the admin panel, create a product "Latexové balónky 50ks" with no base-price relevance beyond the fallback, category any, and add 5 subtypes matching the original example (Makarons 1.90, Pastel 1.80, ŠDM 1.20, Metalik 1.90, Chrom 3.40). Save, mark active.
2. Visit `http://localhost:8080/cs/shop` — confirm the card shows "od 60 Kč"-style text... actually with unit prices given (not packs) the min is 1.20 Kč, so confirm the card shows "od 1,20 Kč".
3. Open the product page, confirm the subtype `<select>` lists all 5 with correct prices, and changing the selection updates the displayed price.
4. Select "Chrom", qty 3, add to cart. Confirm `http://localhost:8080/cs/cart` shows one line "Latexové balónky 50ks — Chrom" at 3.40 Kč × 3 = 10.20 Kč.
5. Go back to the product, add "Makarons" qty 2 as well — confirm the cart now shows *two* separate lines (not merged).
6. Complete checkout (GoPay dev bypass is active with empty `gopay_go_id`) and confirm the order confirmation / order status page shows both line items with their subtype names in the product column.
7. In the admin Orders list, open the new order's detail page and confirm both subtype line items appear correctly.

- [ ] **Step 3: Report results**

No commit for this task — it's a verification pass. If any step fails, return to the relevant task above and fix before considering the feature complete.
