# Category/Product Audit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Track who created/last edited each category and product, and when, and surface it in the admin UI.

**Architecture:** One migration adds `created_by`/`updated_by`/timestamp columns (FK to `users`, `ON DELETE SET NULL`) to `categories` and `products`. `CategoryModel`/`ProductModel` gain a `$userId` parameter on `create()`/`update()` and expose joined `created_by_email`/`updated_by_email` on admin reads. Controllers pass the session's admin user id through. Templates render the audit trail in the index tables and edit forms.

**Tech Stack:** PHP 8 / Slim 4, PDO/MySQL 8, Twig 3, PHPUnit 11 against real Docker MySQL.

## Global Constraints

- Prepared statements with bound parameters only; no SQL string interpolation of request data (`.claude/rules/database.md`).
- Migration file name: `database/migrations/V014__category_product_audit.sql`, idempotent-safe `ALTER TABLE ... ADD COLUMN` (never edit/delete already-applied migrations).
- `created_by`/`updated_by` are `INT NULL` FK → `users(id)` `ON DELETE SET NULL`.
- `updated_at` is DB-managed (`DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`) — app code never sets it.
- All 5 admin lang files (`lang/admin/{cs,en,ru,uk,sk}.json`) must gain the same new keys, kept alphabetically sorted (existing convention).
- Public-facing model methods (`allWithTranslation`, `allActive`, `findBySku`) are untouched — audit data is admin-only.
- Run `php vendor/bin/phpunit` (whole suite) before considering any task done; must be fully green.
- Local dev DB (`docker compose up -d`) must be running for model tests.

---

### Task 1: Migration — add audit columns

**Files:**
- Create: `database/migrations/V014__category_product_audit.sql`

**Interfaces:**
- Produces: columns `categories.created_by`, `categories.created_at`, `categories.updated_by`, `categories.updated_at`; `products.created_by`, `products.updated_by`, `products.updated_at` (products already has `created_at`). All later tasks read/write these columns directly via PDO.

- [ ] **Step 1: Write the migration file**

```sql
ALTER TABLE `categories`
  ADD COLUMN `created_by` int NULL AFTER `sort_order`,
  ADD COLUMN `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `created_by`,
  ADD COLUMN `updated_by` int NULL AFTER `created_at`,
  ADD COLUMN `updated_at` datetime NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `updated_by`;

ALTER TABLE `categories`
  ADD CONSTRAINT `fk_categories_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_categories_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

ALTER TABLE `products`
  ADD COLUMN `created_by` int NULL AFTER `created_at`,
  ADD COLUMN `updated_by` int NULL AFTER `created_by`,
  ADD COLUMN `updated_at` datetime NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `updated_by`;

ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_products_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
```

- [ ] **Step 2: Ensure the local DB and app server are running**

Run: `docker compose up -d && until docker compose exec -T db mysqladmin ping -ubalonky -pbalonky --silent 2>/dev/null; do sleep 1; done`
Expected: no error output, loop exits once MySQL responds.

Run (only if nothing is already listening): `curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/cs/`
- If it prints `200`, a server is already running — skip starting a new one.
- If it fails to connect, start one in the background: `php -S localhost:8080 -t www` (background task).

- [ ] **Step 3: Apply the migration**

```bash
MIGRATE_TOKEN=$(php -r "echo (require 'config/settings.php')['migrate_token'];")
curl -s "http://localhost:8080/migrate.php?token=${MIGRATE_TOKEN}" | python3 -m json.tool
```
Expected: `{"applied": ["V014__category_product_audit"], "count": 1}`.

- [ ] **Step 4: Verify the schema**

```bash
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e "DESCRIBE categories; DESCRIBE products;"
```
Expected: `categories` shows `created_by`, `created_at`, `updated_by`, `updated_at`; `products` shows `created_by`, `updated_by`, `updated_at` alongside the existing `created_at`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/V014__category_product_audit.sql
git commit -m "feat: add created_by/updated_by audit columns to categories and products"
```

---

### Task 2: `CategoryModel` — audit-aware create/update + reads

**Files:**
- Modify: `src/Models/CategoryModel.php`
- Test: `tests/Unit/Models/CategoryModelTest.php`

**Interfaces:**
- Consumes: `categories.created_by`/`created_at`/`updated_by`/`updated_at` columns (Task 1); `users.email`.
- Produces: `CategoryModel::create(array $data, int $userId): int`, `CategoryModel::update(int $id, array $data, int $userId): void` — both now **require** `$userId` as the third positional argument. `CategoryModel::all()` and `CategoryModel::findById()` rows gain `created_by_email`, `created_at`, `updated_by_email`, `updated_at` keys (in addition to existing keys). Consumed by Task 4 (`CategoryController`) and Task 7 (templates).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Models/CategoryModelTest.php` (add a fixture user and new test methods; keep existing tests as-is):

```php
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO categories (slug, sort_order) VALUES ('test-cat', 99)");
        $pdo->exec("INSERT IGNORE INTO category_t (category_id, lang_code, name)
                    SELECT id, 'en', 'Test Category' FROM categories WHERE slug='test-cat'");

        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('category-audit-test@example.com', 'x', 'editor')");
        self::$userId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='category-audit-test@example.com'"
        )->fetch()['id'];
    }
```

```php
    public function test_create_records_creator_and_updater(): void
    {
        $id = CategoryModel::create(['slug' => 'audit-cat-' . uniqid(), 'sort_order' => 1], self::$userId);
        $category = CategoryModel::findById($id);
        $this->assertSame(self::$userId, (int) $category['created_by']);
        $this->assertSame(self::$userId, (int) $category['updated_by']);
        $this->assertSame('category-audit-test@example.com', $category['created_by_email']);
        $this->assertSame('category-audit-test@example.com', $category['updated_by_email']);
        $this->assertNotEmpty($category['created_at']);
        $this->assertNotEmpty($category['updated_at']);
    }

    public function test_update_changes_updated_by_but_not_created_by(): void
    {
        $id = CategoryModel::create(['slug' => 'audit-cat-' . uniqid(), 'sort_order' => 1], self::$userId);

        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('category-audit-editor2@example.com', 'x', 'editor')");
        $secondUserId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='category-audit-editor2@example.com'"
        )->fetch()['id'];

        CategoryModel::update($id, ['slug' => 'audit-cat-updated-' . uniqid(), 'sort_order' => 2], $secondUserId);

        $category = CategoryModel::findById($id);
        $this->assertSame(self::$userId, (int) $category['created_by']);
        $this->assertSame($secondUserId, (int) $category['updated_by']);
    }

    public function test_all_includes_audit_columns(): void
    {
        CategoryModel::create(['slug' => 'audit-cat-' . uniqid(), 'sort_order' => 1], self::$userId);
        $rows = CategoryModel::all();
        $this->assertNotEmpty($rows);
        foreach (['created_by_email', 'created_at', 'updated_by_email', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $rows[0]);
        }
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Models/CategoryModelTest.php --testdox`
Expected: FAIL — `CategoryModel::create()` doesn't accept a second argument the way the test expects (missing `created_by`/`updated_by` columns in the result, `ArgumentCountError`, or undefined array keys).

- [ ] **Step 3: Implement `CategoryModel::create()` / `update()` / `all()` / `findById()`**

Replace the four methods in `src/Models/CategoryModel.php`:

```php
    public static function all(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query(
            'SELECT c.*, ct.name AS name,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM categories c
             LEFT JOIN category_t ct ON ct.category_id = c.id AND ct.lang_code = \'cs\'
             LEFT JOIN users creator ON creator.id = c.created_by
             LEFT JOIN users updater ON updater.id = c.updated_by
             ORDER BY c.sort_order, c.id'
        )->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT c.*,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM categories c
             LEFT JOIN users creator ON creator.id = c.created_by
             LEFT JOIN users updater ON updater.id = c.updated_by
             WHERE c.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data, int $userId): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO categories (slug, sort_order, created_by, updated_by) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$data['slug'], (int) ($data['sort_order'] ?? 0), $userId, $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data, int $userId): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE categories SET slug = ?, sort_order = ?, updated_by = ? WHERE id = ?'
        );
        $stmt->execute([$data['slug'], (int) ($data['sort_order'] ?? 0), $userId, $id]);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Models/CategoryModelTest.php --testdox`
Expected: PASS (all tests, including the pre-existing `test_returns_array` / `test_each_row_has_expected_keys`).

- [ ] **Step 5: Commit**

```bash
git add src/Models/CategoryModel.php tests/Unit/Models/CategoryModelTest.php
git commit -m "feat: track created_by/updated_by on CategoryModel"
```

---

### Task 3: `ProductModel` — audit-aware create/update + reads

**Files:**
- Modify: `src/Models/ProductModel.php`
- Test: `tests/Unit/Models/ProductModelTest.php`

**Interfaces:**
- Consumes: `products.created_by`/`updated_by`/`updated_at` columns (Task 1); `users.email`.
- Produces: `ProductModel::create(array $data, int $userId): int`, `ProductModel::update(int $id, array $data, int $userId): void` — both now **require** `$userId` as the third positional argument. `ProductModel::all()` / `findById()` rows gain `created_by_email`, `updated_by_email`, `updated_at` (`created_at` already existed). Consumed by Task 5 (`ProductController`) and Task 7 (templates).

- [ ] **Step 1: Update existing test call sites and add a fixture user**

In `tests/Unit/Models/ProductModelTest.php`:

Add a fixture user and its id, alongside the existing `$categoryId`:

```php
    private static int $categoryId;
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO categories (slug) VALUES ('test-products')");
        $row = $pdo->query("SELECT id FROM categories WHERE slug='test-products'")->fetch();
        self::$categoryId = (int) $row['id'];

        $pdo->exec("INSERT IGNORE INTO products (category_id, sku, price) VALUES (" . self::$categoryId . ", 'TEST-SKU-001', 9.99)");
        $id = $pdo->query("SELECT id FROM products WHERE sku='TEST-SKU-001'")->fetch()['id'];
        $pdo->exec("INSERT IGNORE INTO product_t (product_id, lang_code, name) VALUES ({$id}, 'en', 'Test Product')");

        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('product-audit-test@example.com', 'x', 'editor')");
        self::$userId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='product-audit-test@example.com'"
        )->fetch()['id'];
    }
```

Update every existing `ProductModel::create([...])` call to pass `self::$userId` as the second argument, and the one `ProductModel::update(...)` call to pass it as the third argument. Concretely, in each of these methods, change the `create()`/`update()` call:

- `test_create_persists_limited_stock`: `ProductModel::create([...], self::$userId);`
- `test_create_defaults_to_unlimited_when_stock_fields_omitted`: `ProductModel::create([...], self::$userId);`
- `test_create_clamps_negative_stock_qty_to_zero`: `ProductModel::create([...], self::$userId);`
- `test_create_forces_zero_qty_when_unlimited`: `ProductModel::create([...], self::$userId);`
- `test_update_persists_limited_stock`: both the `create()` and the `update()` call get `self::$userId` appended.
- `test_add_image_becomes_primary_when_product_has_no_images`: `ProductModel::create([...], self::$userId);`
- `test_add_image_does_not_displace_existing_primary`: `ProductModel::create([...], self::$userId);`

(The private `makeProduct()` helper uses a raw `INSERT` via PDO, not `ProductModel::create()` — leave it unchanged.)

Then add new audit-specific tests:

```php
    public function test_create_records_creator_and_updater(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-AUDIT-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
        ], self::$userId);

        $product = ProductModel::findById($id);
        $this->assertSame(self::$userId, (int) $product['created_by']);
        $this->assertSame(self::$userId, (int) $product['updated_by']);
        $this->assertSame('product-audit-test@example.com', $product['created_by_email']);
        $this->assertSame('product-audit-test@example.com', $product['updated_by_email']);
        $this->assertNotEmpty($product['updated_at']);
    }

    public function test_update_changes_updated_by_but_not_created_by(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-AUDIT-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
        ], self::$userId);

        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('product-audit-editor2@example.com', 'x', 'editor')");
        $secondUserId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='product-audit-editor2@example.com'"
        )->fetch()['id'];

        ProductModel::update($id, [
            'sku'         => 'TEST-AUDIT-UPDATED-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
        ], $secondUserId);

        $product = ProductModel::findById($id);
        $this->assertSame(self::$userId, (int) $product['created_by']);
        $this->assertSame($secondUserId, (int) $product['updated_by']);
    }

    public function test_all_includes_audit_columns(): void
    {
        ProductModel::create([
            'sku'         => 'TEST-AUDIT-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
        ], self::$userId);

        $rows = ProductModel::all();
        $this->assertNotEmpty($rows);
        foreach (['created_by_email', 'updated_by_email', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $rows[0]);
        }
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php --testdox`
Expected: FAIL — `ArgumentCountError: Too few arguments to function App\Models\ProductModel::create()` (or similar) on the updated call sites, plus the new audit tests failing on missing keys.

- [ ] **Step 3: Implement `ProductModel::create()` / `update()` / `all()` / `findById()`**

Replace the four methods in `src/Models/ProductModel.php`:

```php
    public static function all(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query(
            'SELECT p.*,
                    (SELECT filename FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image,
                    ct.name AS category_name,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM products p
             LEFT JOIN category_t ct ON ct.category_id = p.category_id AND ct.lang_code = \'cs\'
             LEFT JOIN users creator ON creator.id = p.created_by
             LEFT JOIN users updater ON updater.id = p.updated_by
             ORDER BY p.id DESC'
        )->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT p.*,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM products p
             LEFT JOIN users creator ON creator.id = p.created_by
             LEFT JOIN users updater ON updater.id = p.updated_by
             WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        if (!$product) return null;
        $imgs = $pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
        $imgs->execute([$id]);
        $product['images'] = $imgs->fetchAll();
        return $product;
    }

    public static function create(array $data, int $userId): int
    {
        $pdo       = Database::getConnection();
        $stockType = ($data['stock_type'] ?? '') === 'limited' ? 'limited' : 'unlimited';
        $stockQty  = $stockType === 'limited' ? max(0, (int) ($data['stock_qty'] ?? 0)) : 0;
        $stmt = $pdo->prepare(
            'INSERT INTO products (sku, price, category_id, is_active, stock_type, stock_qty, sort_order, created_by, updated_by)
             VALUES (:sku, :price, :category_id, :is_active, :stock_type, :stock_qty, 0, :created_by, :updated_by)'
        );
        $stmt->execute([
            'sku'         => $data['sku'],
            'price'       => $data['price'],
            'category_id' => $data['category_id'] ?: 1,
            'is_active'   => (int) ($data['is_active'] ?? 1),
            'stock_type'  => $stockType,
            'stock_qty'   => $stockQty,
            'created_by'  => $userId,
            'updated_by'  => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data, int $userId): void
    {
        $pdo       = Database::getConnection();
        $stockType = ($data['stock_type'] ?? '') === 'limited' ? 'limited' : 'unlimited';
        $stockQty  = $stockType === 'limited' ? max(0, (int) ($data['stock_qty'] ?? 0)) : 0;
        $stmt = $pdo->prepare(
            'UPDATE products SET sku = :sku, price = :price, category_id = :category_id, is_active = :is_active,
                                  stock_type = :stock_type, stock_qty = :stock_qty, updated_by = :updated_by WHERE id = :id'
        );
        $stmt->execute([
            'sku'         => $data['sku'],
            'price'       => $data['price'],
            'category_id' => $data['category_id'] ?: 1,
            'is_active'   => (int) ($data['is_active'] ?? 1),
            'stock_type'  => $stockType,
            'stock_qty'   => $stockQty,
            'updated_by'  => $userId,
            'id'          => $id,
        ]);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Models/ProductModelTest.php --testdox`
Expected: PASS (all tests).

- [ ] **Step 5: Commit**

```bash
git add src/Models/ProductModel.php tests/Unit/Models/ProductModelTest.php
git commit -m "feat: track created_by/updated_by on ProductModel"
```

---

### Task 4: `CategoryController` — wire session user id through

**Files:**
- Modify: `src/Controllers/Admin/CategoryController.php:28-63`

**Interfaces:**
- Consumes: `CategoryModel::create(array $data, int $userId): int` and `CategoryModel::update(int $id, array $data, int $userId): void` (Task 2); `$_SESSION['admin_user']['id']` (set by `AuthController` on login, session already started by `AuthMiddleware` for every route in this controller's group).
- Produces: no new public interface — internal wiring only.

- [ ] **Step 1: Update `createSubmit()` and `editSubmit()`**

```php
    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $id     = CategoryModel::create(
            ['slug' => trim($body['slug'] ?? ''), 'sort_order' => (int) ($body['sort_order'] ?? 0)],
            $userId
        );
        $translations = \App\Services\Translator::autoFill(
            $body['t'] ?? [],
            $request->getAttribute('admin_lang', 'cs'),
            self::LANGS,
            self::TRANSLATABLE_FIELDS
        );
        CategoryModel::setTranslations($id, $translations);
        $this->flash('success', 'categories.flash.created');
        return $this->redirect($response, '/admin/categories');
    }
```

```php
    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id     = (int) $args['id'];
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        CategoryModel::update(
            $id,
            ['slug' => trim($body['slug'] ?? ''), 'sort_order' => (int) ($body['sort_order'] ?? 0)],
            $userId
        );
        CategoryModel::setTranslations($id, $body['t'] ?? []);
        $this->flash('success', 'categories.flash.updated');
        return $this->redirect($response, '/admin/categories');
    }
```

- [ ] **Step 2: Manually verify via the running app**

With the local server up (Task 1, Step 2) and logged into `/admin/login`:

```bash
curl -s -c /tmp/admin-cookie.txt -X POST http://localhost:8080/admin/login \
  --data-urlencode "email=<your admin email>" --data-urlencode "password=<your admin password>" -o /dev/null -w "%{http_code}\n"
curl -s -b /tmp/admin-cookie.txt -X POST http://localhost:8080/admin/categories/new \
  --data-urlencode "slug=audit-smoke-test" --data-urlencode "sort_order=1" -o /dev/null -w "%{http_code}\n"
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor \
  -e "SELECT slug, created_by, updated_by, created_at, updated_at FROM categories WHERE slug='audit-smoke-test';"
```
Expected: the row shows a non-NULL `created_by`/`updated_by` matching the logged-in admin's user id, and populated timestamps.
(There's no seeded admin credential in this plan — use whatever admin account already exists locally, or skip this manual check and rely on Task 8's full walkthrough if none is set up yet.)

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/Admin/CategoryController.php
git commit -m "feat: record admin user on category create/update"
```

---

### Task 5: `ProductController` — wire session user id through

**Files:**
- Modify: `src/Controllers/Admin/ProductController.php:33-86`

**Interfaces:**
- Consumes: `ProductModel::create(array $data, int $userId): int` and `ProductModel::update(int $id, array $data, int $userId): void` (Task 3); `$_SESSION['admin_user']['id']`.
- Produces: no new public interface — internal wiring only.

- [ ] **Step 1: Update `createSubmit()` and `editSubmit()`**

```php
    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $id     = ProductModel::create([
            'sku'         => trim($body['sku'] ?? ''),
            'price'       => $body['price'] ?? '0.00',
            'category_id' => $body['category_id'] ?? 1,
            'is_active'   => isset($body['is_active']) ? 1 : 0,
            'stock_type'  => $body['stock_type'] ?? 'unlimited',
            'stock_qty'   => $body['stock_qty'] ?? 0,
        ], $userId);
        $translations = \App\Services\Translator::autoFill(
            $body['t'] ?? [],
            $request->getAttribute('admin_lang', 'cs'),
            self::LANGS,
            self::TRANSLATABLE_FIELDS
        );
        ProductModel::setTranslations($id, $translations);
        $this->handleImageUpload($request, $id, true);
        $this->flash('success', 'products.flash.created');
        return $this->redirect($response, '/admin/products');
    }
```

```php
    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id     = (int) $args['id'];
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        ProductModel::update($id, [
            'sku'         => trim($body['sku'] ?? ''),
            'price'       => $body['price'] ?? '0.00',
            'category_id' => $body['category_id'] ?? 1,
            'is_active'   => isset($body['is_active']) ? 1 : 0,
            'stock_type'  => $body['stock_type'] ?? 'unlimited',
            'stock_qty'   => $body['stock_qty'] ?? 0,
        ], $userId);
        ProductModel::setTranslations($id, $body['t'] ?? []);
        $this->handleImageUpload($request, $id, false);
        $this->flash('success', 'products.flash.updated');
        return $this->redirect($response, '/admin/products');
    }
```

- [ ] **Step 2: Commit**

```bash
git add src/Controllers/Admin/ProductController.php
git commit -m "feat: record admin user on product create/update"
```

---

### Task 6: Admin translations — audit strings in all 5 lang files

**Files:**
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`

**Interfaces:**
- Produces: translation keys `common.audit.unknown_user`, `categories.col.updated`, `categories.audit.created`, `categories.audit.updated`, `products.col.updated`, `products.audit.created`, `products.audit.updated` — each takes `{user}`/`{date}` placeholders (via `App\Services\I18n::t($key, ['user' => ..., 'date' => ...])`, already supported). Consumed by Task 7 templates.

- [ ] **Step 1: Add keys to `lang/admin/cs.json`**

Insert `"common.audit.unknown_user": "neznámý uživatel",` immediately before the existing `"common.flash.forbidden"` line (alphabetical: `audit` < `flash`).

Insert immediately before `"categories.col.actions"` (alphabetical: `audit` < `col`):
```json
  "categories.audit.created": "Vytvořil(a) {user} dne {date}",
  "categories.audit.updated": "Naposledy upravil(a) {user} dne {date}",
```

Insert immediately after `"categories.col.slug"` and before `"categories.confirm_delete"` (it's still a `col.*` key, sorting after the other `col.*` entries, and `col.*` < `confirm_delete` alphabetically):
```json
  "categories.col.updated": "Naposledy upraveno",
```

Insert immediately before `"products.col.actions"` (alphabetical: `audit` < `col`):
```json
  "products.audit.created": "Vytvořil(a) {user} dne {date}",
  "products.audit.updated": "Naposledy upravil(a) {user} dne {date}",
```

Insert immediately after `"products.col.stock"` and before `"products.confirm_delete"`:
```json
  "products.col.updated": "Naposledy upraveno",
```

- [ ] **Step 2: Add the same keys (translated) to the other 4 files, at the same alphabetical positions**

`lang/admin/en.json`:
```json
  "common.audit.unknown_user": "unknown user",
  "categories.audit.created": "Created by {user} on {date}",
  "categories.audit.updated": "Last updated by {user} on {date}",
  "categories.col.updated": "Last updated",
  "products.audit.created": "Created by {user} on {date}",
  "products.audit.updated": "Last updated by {user} on {date}",
  "products.col.updated": "Last updated",
```

`lang/admin/ru.json`:
```json
  "common.audit.unknown_user": "неизвестный пользователь",
  "categories.audit.created": "Создал(а) {user} {date}",
  "categories.audit.updated": "Последнее обновление: {user}, {date}",
  "categories.col.updated": "Последнее обновление",
  "products.audit.created": "Создал(а) {user} {date}",
  "products.audit.updated": "Последнее обновление: {user}, {date}",
  "products.col.updated": "Последнее обновление",
```

`lang/admin/uk.json`:
```json
  "common.audit.unknown_user": "невідомий користувач",
  "categories.audit.created": "Створив(ла) {user} {date}",
  "categories.audit.updated": "Останнє оновлення: {user}, {date}",
  "categories.col.updated": "Останнє оновлення",
  "products.audit.created": "Створив(ла) {user} {date}",
  "products.audit.updated": "Останнє оновлення: {user}, {date}",
  "products.col.updated": "Останнє оновлення",
```

`lang/admin/sk.json`:
```json
  "common.audit.unknown_user": "neznámy používateľ",
  "categories.audit.created": "Vytvoril(a) {user} dňa {date}",
  "categories.audit.updated": "Naposledy upravil(a) {user} dňa {date}",
  "categories.col.updated": "Naposledy upravené",
  "products.audit.created": "Vytvoril(a) {user} dňa {date}",
  "products.audit.updated": "Naposledy upravil(a) {user} dňa {date}",
  "products.col.updated": "Naposledy upravené",
```

- [ ] **Step 3: Verify all 5 files stay valid JSON with identical key sets**

```bash
python3 -c "
import json
files = ['cs','en','ru','uk','sk']
keysets = {}
for l in files:
    d = json.load(open(f'lang/admin/{l}.json'))
    keysets[l] = set(d.keys())
base = keysets['cs']
for l in files:
    assert keysets[l] == base, f'{l} differs: {keysets[l] ^ base}'
print('OK, all files have', len(base), 'identical keys')
"
```
Expected: `OK, all files have 244 identical keys` (237 existing + 7 new).

- [ ] **Step 4: Commit**

```bash
git add lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/uk.json lang/admin/sk.json
git commit -m "feat: add audit translation keys to admin languages"
```

---

### Task 7: Admin templates — show audit info

**Files:**
- Modify: `templates/admin/categories/index.twig`
- Modify: `templates/admin/categories/form.twig`
- Modify: `templates/admin/products/index.twig`
- Modify: `templates/admin/products/form.twig`
- Modify: `www/assets/css/admin.css`

**Interfaces:**
- Consumes: `cat.created_by_email`/`cat.created_at`/`cat.updated_by_email`/`cat.updated_at` and `p.created_by_email`/`p.created_at`/`p.updated_by_email`/`p.updated_at` (Tasks 2–3); translation keys from Task 6.

- [ ] **Step 1: Add an "Updated" column to `templates/admin/categories/index.twig`**

```twig
        <tr>
            <th>{{ t('categories.col.id') }}</th>
            <th>{{ t('categories.col.name') }}</th>
            <th>{{ t('categories.col.slug') }}</th>
            <th>{{ t('categories.col.order') }}</th>
            <th>{{ t('categories.col.updated') }}</th>
            <th>{{ t('categories.col.actions') }}</th>
        </tr>
```

```twig
    <tr>
        <td>{{ cat.id }}</td>
        <td>{{ cat.name ?? '—' }}</td>
        <td>{{ cat.slug }}</td>
        <td>{{ cat.sort_order }}</td>
        <td class="audit-meta">{{ cat.updated_by_email ?? t('common.audit.unknown_user') }}<br>{{ cat.updated_at }}</td>
        <td>
```

Update the `{% else %}` row's `colspan` from `5` to `6`.

- [ ] **Step 2: Add an audit line to `templates/admin/categories/form.twig`**

Insert right after the `admin-topbar` `</div>` and before the `<form ...>` tag:

```twig
{% if category %}
<p class="audit-meta">
    {{ t('categories.audit.created', {user: category.created_by_email ?? t('common.audit.unknown_user'), date: category.created_at}) }}
    ·
    {{ t('categories.audit.updated', {user: category.updated_by_email ?? t('common.audit.unknown_user'), date: category.updated_at}) }}
</p>
{% endif %}
```

- [ ] **Step 3: Add an "Updated" column to `templates/admin/products/index.twig`**

```twig
        <tr>
            <th>{{ t('products.col.image') }}</th>
            <th>{{ t('products.col.sku') }}</th>
            <th>{{ t('products.col.category') }}</th>
            <th>{{ t('products.col.price') }}</th>
            <th>{{ t('products.col.stock') }}</th>
            <th>{{ t('products.col.active') }}</th>
            <th>{{ t('products.col.updated') }}</th>
            <th>{{ t('products.col.actions') }}</th>
        </tr>
```

```twig
        <td>{{ p.is_active ? '✓' : '—' }}</td>
        <td class="audit-meta">{{ p.updated_by_email ?? t('common.audit.unknown_user') }}<br>{{ p.updated_at }}</td>
        <td>
```

Update the `{% else %}` row's `colspan` from `7` to `8`.

- [ ] **Step 4: Add an audit line to `templates/admin/products/form.twig`**

Insert right after the `admin-topbar` `</div>` and before the `<form ...>` tag:

```twig
{% if product %}
<p class="audit-meta">
    {{ t('products.audit.created', {user: product.created_by_email ?? t('common.audit.unknown_user'), date: product.created_at}) }}
    ·
    {{ t('products.audit.updated', {user: product.updated_by_email ?? t('common.audit.unknown_user'), date: product.updated_at}) }}
</p>
{% endif %}
```

- [ ] **Step 5: Add the `.audit-meta` style to `www/assets/css/admin.css`**

Insert right after the `.admin-table tr:hover td { background:#fafafa; }` line (line 18):

```css
.audit-meta { font-size:0.85rem; color:#666; }
```

- [ ] **Step 6: Manually verify in the browser**

With the local server running (Task 1, Step 2), log into `/admin/login`, then visit:
- `http://localhost:8080/admin/categories` — confirm the new "Last updated"/"Naposledy upraveno" column renders with an email + timestamp (or "unknown user" for pre-existing rows).
- `http://localhost:8080/admin/categories/{id}/edit` for an existing category — confirm the audit line renders above the form.
- `http://localhost:8080/admin/products` and `http://localhost:8080/admin/products/{id}/edit` — same checks.
- Create a new category and a new product through the admin UI, then reload their edit pages — confirm both "Created by" and "Last updated by" show your logged-in email.

- [ ] **Step 7: Commit**

```bash
git add templates/admin/categories/index.twig templates/admin/categories/form.twig \
        templates/admin/products/index.twig templates/admin/products/form.twig \
        www/assets/css/admin.css
git commit -m "feat: show category/product audit info in admin UI"
```

---

### Task 8: Full suite verification

**Files:** none (verification only)

**Interfaces:**
- Consumes: everything from Tasks 1–7.

- [ ] **Step 1: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests pass, zero failures/errors.

- [ ] **Step 2: Re-run the local smoke check**

```bash
curl -s -o /dev/null -w "CS homepage: %{http_code}\n"  http://localhost:8080/cs/
curl -s -o /dev/null -w "Admin login: %{http_code}\n"  http://localhost:8080/admin/login
curl -s -o /dev/null -w "Categories:   %{http_code}\n" http://localhost:8080/admin/categories
curl -s -o /dev/null -w "Products:     %{http_code}\n" http://localhost:8080/admin/products
```
Expected: all four return `200` (admin pages will redirect to login with a `302` if not authenticated in this shell session — that's fine, it means routing didn't break; the manual browser check in Task 7 Step 6 already confirmed the authenticated view).

- [ ] **Step 3: Final commit if any stragglers remain**

```bash
git status
```
Expected: clean working tree (everything already committed task-by-task). If anything is outstanding, commit it with a clear message.
