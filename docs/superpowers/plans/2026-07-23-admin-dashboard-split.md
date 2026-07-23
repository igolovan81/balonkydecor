# Admin Dashboard Split Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the single admin dashboard into three focused dashboards — orders, products & categories, customers — plus a trimmed overview landing page, moving all raw SQL out of controllers and into model methods.

**Architecture:** Four new/extended model classes (`OrderModel`, `ProductModel`, `CategoryModel`, `CustomerModel`) each gain small static aggregation methods. Three new thin controllers (`OrderDashboardController`, `ProductDashboardController`, `CustomerDashboardController`) each render one new template. The existing `DashboardController`/`admin/dashboard.twig` is trimmed to a 3-card overview linking into the new pages. No charting library — trend data renders as CSS bar charts computed server-side.

**Tech Stack:** PHP 8.1 / Slim 4 / PDO (backend), Twig 3 (templates), plain CSS (`www/assets/css/admin.css`, no build step), PHPUnit 11 against real Docker MySQL (model tests only — controllers/templates verified by hand per project convention).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-23-admin-dashboard-split-design.md`.
- TDD: every new model method gets a failing PHPUnit test first, run against real Docker MySQL — no mocks (`.claude/rules/unit-testing.md`).
- Every query uses a prepared statement with bound parameters; the only literal SQL fragment is the hardcoded `LOW_STOCK_THRESHOLD` constant (`.claude/rules/database.md`).
- Shared dev DB: tests must tolerate leftover rows. Aggregate-count tests use a before/after delta on a fresh `uniqid()`-suffixed fixture, never a raw `assertSame` on a global count.
- All 5 admin language files (`lang/admin/{cs,en,ru,sk,uk}.json`) must end with identical key sets — verified by a script in the translations task.
- No charting library, no build step, no npm (`.claude/rules/frontend.md`) — trend widgets are plain `<div>` bars with `height:N%` computed in the controller.
- Controllers stay untested (project convention) — each page gets a manual browser/curl check instead of a controller test harness.
- **Deviations from the spec, decided during planning:**
  - `OrderModel::dashboardStats()` drops the spec's separate `total_count` key — it was identical to `orders_total`, so the controller reuses `orders_total` as the GoPay-rate denominator instead of returning a duplicate value.
  - `ProductModel::recentActivity()` takes `(string $lang, int $limit = 10)`, not just `(int $limit = 10)` — resolving the translated product name via `product_t` requires a language, same as every other public-facing `ProductModel` method.
  - The overview page reuses the existing `dashboard.stats.orders_today` / `dashboard.stats.products_active` keys (plus one new `dashboard.stats.customers_total`) as its card labels instead of adding separate `dashboard.overview.view_*` keys — the whole card is already a link, so a second "view X" label was redundant.
- Full suite (`php vendor/bin/phpunit --testdox`) must be green before every commit that touches PHP.

---

### Task 1: `OrderModel` dashboard methods (TDD)

**Files:**
- Modify: `src/Models/OrderModel.php` (append after `forCustomer()`, currently the last method, ending at line 109 with the class closing brace on line 110)
- Test: `tests/Unit/Models/OrderModelTest.php` (append before the class closing brace on line 140)

**Interfaces:**
- Produces: `OrderModel::dashboardStats(): array` (`orders_today`, `orders_pending`, `orders_total`, `gopay_count`), `OrderModel::statusBreakdown(): array` (keys `pending`/`paid`/`ready`/`completed`/`cancelled`, always all 5 present), `OrderModel::revenueByDay(int $days = 30): array` (list of `['date' => 'Y-m-d', 'total' => float]`, oldest first, one entry per day). Consumed by Task 6's `OrderDashboardController`.

- [ ] **Step 1: Write the failing tests**

Add these three methods to `tests/Unit/Models/OrderModelTest.php`, just before the final closing `}` on line 140:

```php
    public function test_dashboardStats_reflects_new_order(): void
    {
        $before = OrderModel::dashboardStats();

        OrderModel::create(
            [
                'customer_name'  => 'Dashboard Stats Buyer',
                'customer_email' => 'dash-stats-' . uniqid() . '@example.com',
                'customer_phone' => '+420000000002',
                'pickup_date'    => '2026-12-31',
                'notes'          => '',
            ],
            ['SKU-DASH-STATS' => ['qty' => 1, 'name' => 'Dash Stats Balloon', 'price' => '5.00', 'subtotal' => '5.00']],
            '5.00'
        );

        $after = OrderModel::dashboardStats();

        $this->assertSame($before['orders_today'] + 1, $after['orders_today']);
        $this->assertSame($before['orders_pending'] + 1, $after['orders_pending']);
        $this->assertSame($before['orders_total'] + 1, $after['orders_total']);
        $this->assertSame($before['gopay_count'], $after['gopay_count']);
    }

    public function test_statusBreakdown_has_all_five_statuses_and_reflects_new_order(): void
    {
        $before = OrderModel::statusBreakdown();

        OrderModel::create(
            [
                'customer_name'  => 'Status Breakdown Buyer',
                'customer_email' => 'status-breakdown-' . uniqid() . '@example.com',
                'customer_phone' => '+420000000003',
                'pickup_date'    => '2026-12-31',
                'notes'          => '',
            ],
            ['SKU-STATUS-BREAKDOWN' => ['qty' => 1, 'name' => 'Status Balloon', 'price' => '5.00', 'subtotal' => '5.00']],
            '5.00'
        );

        $after = OrderModel::statusBreakdown();

        $this->assertSame(['pending', 'paid', 'ready', 'completed', 'cancelled'], array_keys($after));
        $this->assertSame($before['pending'] + 1, $after['pending']);
    }

    public function test_revenueByDay_includes_todays_order_and_zero_fills_range(): void
    {
        $today  = date('Y-m-d');
        $before = OrderModel::revenueByDay(7);
        $beforeToday = end($before)['total'];

        OrderModel::create(
            [
                'customer_name'  => 'Revenue Buyer',
                'customer_email' => 'revenue-' . uniqid() . '@example.com',
                'customer_phone' => '+420000000004',
                'pickup_date'    => '2026-12-31',
                'notes'          => '',
            ],
            ['SKU-REVENUE' => ['qty' => 1, 'name' => 'Revenue Balloon', 'price' => '12.34', 'subtotal' => '12.34']],
            '12.34'
        );

        $after = OrderModel::revenueByDay(7);

        $this->assertCount(7, $after);
        $this->assertSame($today, end($after)['date']);
        $this->assertEqualsWithDelta($beforeToday + 12.34, end($after)['total'], 0.001);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker compose up -d
until docker compose exec -T db mysqladmin ping -ubalonky -pbalonky --silent 2>/dev/null; do sleep 1; done
php vendor/bin/phpunit --filter 'test_dashboardStats_reflects_new_order|test_statusBreakdown_has_all_five_statuses_and_reflects_new_order|test_revenueByDay_includes_todays_order_and_zero_fills_range' tests/Unit/Models/OrderModelTest.php
```
Expected: 3 errors — `Call to undefined method App\Models\OrderModel::dashboardStats()` (and similarly for the other two).

- [ ] **Step 3: Write the minimal implementation**

Add these three methods to `src/Models/OrderModel.php`, after `forCustomer()` (before the class's closing `}` on line 110):

```php
    public static function dashboardStats(): array
    {
        $pdo = Database::getConnection();
        return [
            'orders_today'   => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
            'orders_pending' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn(),
            'orders_total'   => (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
            'gopay_count'    => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE gopay_payment_id IS NOT NULL")->fetchColumn(),
        ];
    }

    public static function statusBreakdown(): array
    {
        $pdo    = Database::getConnection();
        $counts = array_fill_keys(['pending', 'paid', 'ready', 'completed', 'cancelled'], 0);
        $stmt   = $pdo->query('SELECT status, COUNT(*) AS c FROM orders GROUP BY status');
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    public static function revenueByDay(int $days = 30): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT DATE(created_at) AS day, SUM(total_amount) AS total
             FROM orders
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             GROUP BY DATE(created_at)'
        );
        $stmt->bindValue(':days', $days - 1, \PDO::PARAM_INT);
        $stmt->execute();
        $byDay = [];
        foreach ($stmt->fetchAll() as $row) {
            $byDay[$row['day']] = (float) $row['total'];
        }

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date       = date('Y-m-d', strtotime("-{$i} days"));
            $result[]   = ['date' => $date, 'total' => $byDay[$date] ?? 0.0];
        }
        return $result;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php vendor/bin/phpunit --filter 'test_dashboardStats_reflects_new_order|test_statusBreakdown_has_all_five_statuses_and_reflects_new_order|test_revenueByDay_includes_todays_order_and_zero_fills_range' tests/Unit/Models/OrderModelTest.php
```
Expected: `OK (3 tests, ...)`

- [ ] **Step 5: Run the full unit suite to confirm no regressions**

```bash
php vendor/bin/phpunit --testdox
```
Expected: all green, 3 more tests than before.

- [ ] **Step 6: Commit**

```bash
git add src/Models/OrderModel.php tests/Unit/Models/OrderModelTest.php
git commit -m "feat: add OrderModel dashboard aggregation methods"
```

---

### Task 2: `ProductModel` dashboard methods (TDD)

**Files:**
- Modify: `src/Models/ProductModel.php` (add a class constant near the top; append 3 methods after `clone()`, currently the last method, ending at line 502 with the class closing brace on line 503)
- Test: `tests/Unit/Models/ProductModelTest.php` (add `use App\Models\OrderModel;` import; append 3 tests before the class closing brace on line 922)

**Interfaces:**
- Consumes: `OrderModel::create()` (Task 1's suite already exercises this; reused here only in the test file) — no production dependency on Task 1.
- Produces: `ProductModel::dashboardStats(): array` (`active_count`, `low_stock_count`), `ProductModel::topSellers(int $limit = 10): array` (list of `['name' => string, 'qty_sold' => int]`), `ProductModel::recentActivity(string $lang, int $limit = 10): array` (rows with `id`, `sku`, `name`, `updated_at`, ordered by `updated_at DESC`). Consumed by Task 7's `ProductDashboardController`.

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/Models/ProductModelTest.php`, change line 4 from:
```php
use App\Models\ProductModel;
```
to:
```php
use App\Models\ProductModel;
use App\Models\OrderModel;
```

Then add these three methods just before the final closing `}` on line 922:

```php
    public function test_dashboardStats_reflects_active_and_low_stock_products(): void
    {
        $before = ProductModel::dashboardStats();

        $this->makeProduct(); // default is_active=1, stock_type=unlimited

        $pdo         = Database::getConnection();
        $lowStockSku = 'LOW-STOCK-' . strtoupper(uniqid());
        $pdo->prepare(
            "INSERT INTO products (category_id, sku, price, is_active, stock_type, stock_qty)
             VALUES (?, ?, 9.99, 1, 'limited', 2)"
        )->execute([self::$categoryId, $lowStockSku]);

        $after = ProductModel::dashboardStats();

        $this->assertSame($before['active_count'] + 2, $after['active_count']);
        $this->assertSame($before['low_stock_count'] + 1, $after['low_stock_count']);
    }

    public function test_topSellers_includes_new_order_item_with_correct_qty(): void
    {
        $name = 'Top Seller Test ' . uniqid();

        OrderModel::create(
            [
                'customer_name'  => 'Top Seller Buyer',
                'customer_email' => 'top-seller-' . uniqid() . '@example.com',
                'customer_phone' => '+420000000005',
                'pickup_date'    => '2026-12-31',
                'notes'          => '',
            ],
            ['SKU-TOPSELLER' => ['qty' => 7, 'name' => $name, 'price' => '5.00', 'subtotal' => '35.00']],
            '35.00'
        );

        $sellers = ProductModel::topSellers(1000);
        $row     = current(array_filter($sellers, fn ($r) => $r['name'] === $name));

        $this->assertNotFalse($row);
        $this->assertSame(7, $row['qty_sold']);
    }

    public function test_recentActivity_orders_by_updated_at_descending(): void
    {
        $pdo = Database::getConnection();

        $oldSku = 'RECENT-OLD-' . strtoupper(uniqid());
        $pdo->prepare('INSERT INTO products (category_id, sku, price) VALUES (?, ?, 9.99)')
            ->execute([self::$categoryId, $oldSku]);
        $oldId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE products SET updated_at = NOW() - INTERVAL 1 DAY WHERE id = ?')->execute([$oldId]);

        $newSku = 'RECENT-NEW-' . strtoupper(uniqid());
        $pdo->prepare('INSERT INTO products (category_id, sku, price) VALUES (?, ?, 9.99)')
            ->execute([self::$categoryId, $newSku]);

        $rows   = ProductModel::recentActivity('en', 1000);
        $skus   = array_column($rows, 'sku');
        $oldPos = array_search($oldSku, $skus);
        $newPos = array_search($newSku, $skus);

        $this->assertNotFalse($oldPos);
        $this->assertNotFalse($newPos);
        $this->assertLessThan($oldPos, $newPos);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php vendor/bin/phpunit --filter 'test_dashboardStats_reflects_active_and_low_stock_products|test_topSellers_includes_new_order_item_with_correct_qty|test_recentActivity_orders_by_updated_at_descending' tests/Unit/Models/ProductModelTest.php
```
Expected: 3 errors — undefined methods `dashboardStats`, `topSellers`, `recentActivity`.

- [ ] **Step 3: Write the minimal implementation**

In `src/Models/ProductModel.php`, add a class constant right after the opening `class ProductModel` line (currently line 4):

```php
class ProductModel
{
    private const LOW_STOCK_THRESHOLD = 5;

```

Then add these three methods after `clone()` (before the class's closing `}`, currently line 503):

```php
    public static function dashboardStats(): array
    {
        $pdo = Database::getConnection();

        $active = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM products WHERE is_active = 1 AND stock_type = 'limited' AND stock_qty <= :threshold"
        );
        $stmt->bindValue(':threshold', self::LOW_STOCK_THRESHOLD, \PDO::PARAM_INT);
        $stmt->execute();

        return ['active_count' => $active, 'low_stock_count' => (int) $stmt->fetchColumn()];
    }

    public static function topSellers(int $limit = 10): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT product_name_snapshot AS name, SUM(quantity) AS qty_sold
             FROM order_items
             GROUP BY product_name_snapshot
             ORDER BY qty_sold DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(
            fn (array $row) => ['name' => $row['name'], 'qty_sold' => (int) $row['qty_sold']],
            $stmt->fetchAll()
        );
    }

    public static function recentActivity(string $lang, int $limit = 10): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT p.id, p.sku, p.updated_at, COALESCE(t.name, p.sku) AS name
             FROM products p
             LEFT JOIN product_t t ON t.product_id = p.id AND t.lang_code = :lang
             ORDER BY p.updated_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':lang', $lang);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php vendor/bin/phpunit --filter 'test_dashboardStats_reflects_active_and_low_stock_products|test_topSellers_includes_new_order_item_with_correct_qty|test_recentActivity_orders_by_updated_at_descending' tests/Unit/Models/ProductModelTest.php
```
Expected: `OK (3 tests, ...)`

- [ ] **Step 5: Run the full unit suite to confirm no regressions**

```bash
php vendor/bin/phpunit --testdox
```
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/Models/ProductModel.php tests/Unit/Models/ProductModelTest.php
git commit -m "feat: add ProductModel dashboard aggregation methods"
```

---

### Task 3: `CategoryModel::withProductCounts()` (TDD)

**Files:**
- Modify: `src/Models/CategoryModel.php` (append after `setTranslations()`, currently the last method, ending at line 146 with the class closing brace on line 147)
- Test: `tests/Unit/Models/CategoryModelTest.php` (append before the class closing brace on line 161)

**Interfaces:**
- Produces: `CategoryModel::withProductCounts(string $lang): array` — rows `['id' => int, 'slug' => string, 'name' => string, 'product_count' => int]`, ordered by `product_count` descending. Consumed by Task 7's `ProductDashboardController`.

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Unit/Models/CategoryModelTest.php`, just before the final closing `}` on line 161:

```php
    public function test_withProductCounts_reflects_products_in_category(): void
    {
        $pdo  = Database::getConnection();
        $slug = 'test-cat-counts-' . uniqid();
        $pdo->prepare('INSERT INTO categories (slug) VALUES (?)')->execute([$slug]);
        $catId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO category_t (category_id, lang_code, name) VALUES (?, 'en', 'Counts Category')")
            ->execute([$catId]);

        foreach (range(1, 3) as $i) {
            $pdo->prepare('INSERT INTO products (category_id, sku, price) VALUES (?, ?, 9.99)')
                ->execute([$catId, 'COUNT-TEST-' . $i . '-' . uniqid()]);
        }

        $rows = CategoryModel::withProductCounts('en');
        $row  = current(array_filter($rows, fn ($r) => $r['id'] === $catId));

        $this->assertNotFalse($row);
        $this->assertSame('Counts Category', $row['name']);
        $this->assertSame(3, $row['product_count']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php vendor/bin/phpunit --filter test_withProductCounts_reflects_products_in_category tests/Unit/Models/CategoryModelTest.php
```
Expected: FAIL — `Call to undefined method App\Models\CategoryModel::withProductCounts()`

- [ ] **Step 3: Write the minimal implementation**

Add this method to `src/Models/CategoryModel.php`, after `setTranslations()` (before the class's closing `}` on line 147):

```php
    public static function withProductCounts(string $lang): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT c.id, c.slug, COALESCE(t.name, c.slug) AS name, COUNT(p.id) AS product_count
             FROM categories c
             LEFT JOIN category_t t ON t.category_id = c.id AND t.lang_code = :lang
             LEFT JOIN products p ON p.category_id = c.id
             GROUP BY c.id, c.slug, t.name
             ORDER BY product_count DESC'
        );
        $stmt->execute(['lang' => $lang]);
        return array_map(
            fn (array $row) => [
                'id'            => (int) $row['id'],
                'slug'          => $row['slug'],
                'name'          => $row['name'],
                'product_count' => (int) $row['product_count'],
            ],
            $stmt->fetchAll()
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php vendor/bin/phpunit --filter test_withProductCounts_reflects_products_in_category tests/Unit/Models/CategoryModelTest.php
```
Expected: `OK (1 test, 2 assertions)`

- [ ] **Step 5: Run the full unit suite to confirm no regressions**

```bash
php vendor/bin/phpunit --testdox
```
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/Models/CategoryModel.php tests/Unit/Models/CategoryModelTest.php
git commit -m "feat: add CategoryModel::withProductCounts()"
```

---

### Task 4: `CustomerModel` dashboard methods (TDD)

**Files:**
- Modify: `src/Models/CustomerModel.php` (append after `delete()`, currently the last method, ending at line 74 with the class closing brace on line 75)
- Test: `tests/Unit/Models/CustomerModelTest.php` (append before the class closing brace on line 127)

**Interfaces:**
- Produces: `CustomerModel::dashboardStats(): array` (`total`, `new_this_week`, `new_this_month`), `CustomerModel::signupsByDay(int $days = 30): array` (list of `['date' => 'Y-m-d', 'count' => int]`), `CustomerModel::recent(int $limit = 10): array` (rows `id`, `email`, `name`, `phone`, `created_at`, ordered by `created_at DESC`). Consumed by Task 8's `CustomerDashboardController` and Task 9's overview `DashboardController`.

- [ ] **Step 1: Write the failing tests**

Add these three methods to `tests/Unit/Models/CustomerModelTest.php`, just before the final closing `}` on line 127:

```php
    public function test_dashboardStats_reflects_new_customer(): void
    {
        $before = CustomerModel::dashboardStats();

        CustomerModel::create('dash-stats-' . uniqid() . '@example.com', self::$hash);

        $after = CustomerModel::dashboardStats();

        $this->assertSame($before['total'] + 1, $after['total']);
        $this->assertSame($before['new_this_week'] + 1, $after['new_this_week']);
        $this->assertSame($before['new_this_month'] + 1, $after['new_this_month']);
    }

    public function test_signupsByDay_includes_todays_signup_and_zero_fills_range(): void
    {
        $today       = date('Y-m-d');
        $before      = CustomerModel::signupsByDay(7);
        $beforeToday = end($before)['count'];

        CustomerModel::create('signup-day-' . uniqid() . '@example.com', self::$hash);

        $after = CustomerModel::signupsByDay(7);

        $this->assertCount(7, $after);
        $this->assertSame($today, end($after)['date']);
        $this->assertSame($beforeToday + 1, end($after)['count']);
    }

    public function test_recent_orders_by_created_at_descending(): void
    {
        $pdo = Database::getConnection();

        $oldEmail = 'recent-old-' . uniqid() . '@example.com';
        $oldId    = CustomerModel::create($oldEmail, self::$hash);
        $pdo->prepare('UPDATE customers SET created_at = NOW() - INTERVAL 1 DAY WHERE id = ?')->execute([$oldId]);

        $newEmail = 'recent-new-' . uniqid() . '@example.com';
        CustomerModel::create($newEmail, self::$hash);

        $rows   = CustomerModel::recent(1000);
        $emails = array_column($rows, 'email');
        $oldPos = array_search($oldEmail, $emails);
        $newPos = array_search($newEmail, $emails);

        $this->assertNotFalse($oldPos);
        $this->assertNotFalse($newPos);
        $this->assertLessThan($oldPos, $newPos);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php vendor/bin/phpunit --filter 'test_dashboardStats_reflects_new_customer|test_signupsByDay_includes_todays_signup_and_zero_fills_range|test_recent_orders_by_created_at_descending' tests/Unit/Models/CustomerModelTest.php
```
Expected: 3 errors — undefined methods `dashboardStats`, `signupsByDay`, `recent`.

- [ ] **Step 3: Write the minimal implementation**

Add these three methods to `src/Models/CustomerModel.php`, after `delete()` (before the class's closing `}` on line 75):

```php
    public static function dashboardStats(): array
    {
        $pdo = Database::getConnection();
        return [
            'total'          => (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
            'new_this_week'  => (int) $pdo->query('SELECT COUNT(*) FROM customers WHERE created_at >= NOW() - INTERVAL 7 DAY')->fetchColumn(),
            'new_this_month' => (int) $pdo->query('SELECT COUNT(*) FROM customers WHERE created_at >= NOW() - INTERVAL 30 DAY')->fetchColumn(),
        ];
    }

    public static function signupsByDay(int $days = 30): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT DATE(created_at) AS day, COUNT(*) AS c
             FROM customers
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             GROUP BY DATE(created_at)'
        );
        $stmt->bindValue(':days', $days - 1, \PDO::PARAM_INT);
        $stmt->execute();
        $byDay = [];
        foreach ($stmt->fetchAll() as $row) {
            $byDay[$row['day']] = (int) $row['c'];
        }

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date     = date('Y-m-d', strtotime("-{$i} days"));
            $result[] = ['date' => $date, 'count' => $byDay[$date] ?? 0];
        }
        return $result;
    }

    public static function recent(int $limit = 10): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, email, name, phone, created_at FROM customers ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php vendor/bin/phpunit --filter 'test_dashboardStats_reflects_new_customer|test_signupsByDay_includes_todays_signup_and_zero_fills_range|test_recent_orders_by_created_at_descending' tests/Unit/Models/CustomerModelTest.php
```
Expected: `OK (3 tests, ...)`

- [ ] **Step 5: Run the full unit suite to confirm no regressions**

```bash
php vendor/bin/phpunit --testdox
```
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/Models/CustomerModel.php tests/Unit/Models/CustomerModelTest.php
git commit -m "feat: add CustomerModel dashboard aggregation methods"
```

---

### Task 5: Admin CSS — chart bars, linked stat cards, nav heading

**Files:**
- Modify: `www/assets/css/admin.css`

**Interfaces:**
- Produces: `.stat-card--link` (a `.stat-card` that's an `<a>`), `.chart-bars` / `.chart-bar` (CSS bar-chart container/bar), `.admin-nav-heading` (small caps sidebar section label). Consumed by Tasks 6-9's templates and Task 6-8's nav markup.

- [ ] **Step 1: Add `.admin-nav-heading`**

Current lines 6-7 are:
```css
.admin-nav a { color:#c0c0d0; text-decoration:none; padding:0.6rem 1.25rem; font-size:0.9rem; transition:background 0.15s; }
.admin-nav a:hover, .admin-nav a.active { background:#2a2a4e; color:#fff; }
```

Add a new rule directly after them:
```css
.admin-nav a { color:#c0c0d0; text-decoration:none; padding:0.6rem 1.25rem; font-size:0.9rem; transition:background 0.15s; }
.admin-nav a:hover, .admin-nav a.active { background:#2a2a4e; color:#fff; }
.admin-nav-heading { padding:0.9rem 1.25rem 0.3rem; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:#6a6a94; }
```

- [ ] **Step 2: Add `.stat-card--link` and chart-bar styles**

Current lines 99-103 are:
```css
/* Dashboard cards */
.stat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:1rem; margin-bottom:2rem; }
.stat-card { background:#fff; border-radius:8px; padding:1.25rem; box-shadow:0 1px 4px rgba(0,0,0,.08); }
.stat-card .stat-value { font-size:2rem; font-weight:700; color:#e91e8c; }
.stat-card .stat-label { font-size:0.85rem; color:#666; margin-top:0.25rem; }
```

Replace them with:
```css
/* Dashboard cards */
.stat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:1rem; margin-bottom:2rem; }
.stat-card { background:#fff; border-radius:8px; padding:1.25rem; box-shadow:0 1px 4px rgba(0,0,0,.08); }
.stat-card .stat-value { font-size:2rem; font-weight:700; color:#e91e8c; }
.stat-card .stat-label { font-size:0.85rem; color:#666; margin-top:0.25rem; }
.stat-card--link { display:block; text-decoration:none; color:inherit; transition:box-shadow 0.15s; }
.stat-card--link:hover, .stat-card--link:focus-visible { box-shadow:0 2px 12px rgba(0,0,0,.18); }

/* Dashboard trend charts (plain CSS bars, no charting library) */
.chart-bars { display:flex; align-items:flex-end; gap:3px; height:120px; margin-bottom:2rem; }
.chart-bar { flex:1; background:#e91e8c; border-radius:2px 2px 0 0; min-height:2px; }
```

- [ ] **Step 3: Manual check — CSS is valid and admin pages still render**

```bash
docker compose up -d
php -S localhost:8080 -t www &
SERVER_PID=$!
sleep 1
curl -s -o /dev/null -w "admin.css: %{http_code}\n" http://localhost:8080/assets/css/admin.css
kill $SERVER_PID
```
Expected: `admin.css: 200`

- [ ] **Step 4: Commit**

```bash
git add www/assets/css/admin.css
git commit -m "feat: add dashboard chart-bar and linked stat-card CSS"
```

---

### Task 6: Orders dashboard page

**Files:**
- Create: `src/Controllers/Admin/OrderDashboardController.php`
- Create: `templates/admin/dashboard-orders.twig`
- Modify: `src/routes.php` (add `use` import at line 17; add route after line 45)
- Modify: `templates/layout/admin-base.twig` (add nav link after line 26)

**Interfaces:**
- Consumes: `OrderModel::dashboardStats()`, `OrderModel::statusBreakdown()`, `OrderModel::revenueByDay()` (Task 1); `OrderModel::adminList(int $page, int $perPage, string $status = '')` (existing method). Reads translation keys `dashboard.orders.title`, `dashboard.orders.stats.gopay_rate`, `dashboard.orders.status_breakdown`, `dashboard.orders.revenue_trend`, plus the existing `dashboard.stats.orders_today/pending/total`, `dashboard.recent_orders`, `dashboard.col.*`, `dashboard.no_orders`, `orders.status.*`, `nav.dashboard_orders` — all added/reused in Task 10.
- Produces: route `GET /admin/dashboard/orders`, linked from the overview page (Task 9) and the sidebar nav.

- [ ] **Step 1: Create the controller**

Create `src/Controllers/Admin/OrderDashboardController.php`:

```php
<?php
namespace App\Controllers\Admin;

use App\Models\OrderModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OrderDashboardController extends AdminBaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $stats  = OrderModel::dashboardStats();
        $status = OrderModel::statusBreakdown();

        $revenue = OrderModel::revenueByDay(30);
        $max     = 0.0;
        foreach ($revenue as $day) {
            $max = max($max, $day['total']);
        }
        foreach ($revenue as &$day) {
            $day['pct'] = $max > 0 ? (int) round(($day['total'] / $max) * 100) : 0;
        }
        unset($day);

        $gopayRate = $stats['orders_total'] > 0
            ? (int) round(100 * $stats['gopay_count'] / $stats['orders_total'])
            : 0;

        return $this->renderAdmin($request, $response, 'admin/dashboard-orders.twig', [
            'stats'      => $stats,
            'gopay_rate' => $gopayRate,
            'status'     => $status,
            'revenue'    => $revenue,
            'recent'     => OrderModel::adminList(1, 10)['orders'],
        ]);
    }
}
```

- [ ] **Step 2: Create the template**

Create `templates/admin/dashboard-orders.twig`:

```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ t('dashboard.orders.title') }}{% endblock %}
{% block content %}
<div class="admin-topbar"><h1>{{ t('dashboard.orders.title') }}</h1></div>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value">{{ stats.orders_today }}</div>
        <div class="stat-label">{{ t('dashboard.stats.orders_today') }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">{{ stats.orders_pending }}</div>
        <div class="stat-label">{{ t('dashboard.stats.orders_pending') }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">{{ stats.orders_total }}</div>
        <div class="stat-label">{{ t('dashboard.stats.orders_total') }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">{{ gopay_rate }}%</div>
        <div class="stat-label">{{ t('dashboard.orders.stats.gopay_rate') }}</div>
    </div>
</div>

<h2>{{ t('dashboard.orders.status_breakdown') }}</h2>
<div class="stat-grid">
    {% for s, count in status %}
    <div class="stat-card">
        <div class="stat-value">{{ count }}</div>
        <div class="stat-label"><span class="badge badge-{{ s }}">{{ t('orders.status.' ~ s) }}</span></div>
    </div>
    {% endfor %}
</div>

<h2>{{ t('dashboard.orders.revenue_trend') }}</h2>
<div class="chart-bars">
    {% for day in revenue %}
    <div class="chart-bar" style="height:{{ day.pct }}%" title="{{ day.date }}: {{ day.total|number_format(2, '.', ' ') }} Kč" role="img" aria-label="{{ day.date }}: {{ day.total|number_format(2, '.', ' ') }} Kč"></div>
    {% endfor %}
</div>

<h2>{{ t('dashboard.recent_orders') }}</h2>
<table class="admin-table">
    <thead>
        <tr>
            <th>{{ t('dashboard.col.number') }}</th>
            <th>{{ t('dashboard.col.customer') }}</th>
            <th>{{ t('dashboard.col.total') }}</th>
            <th>{{ t('dashboard.col.status') }}</th>
            <th>{{ t('dashboard.col.created') }}</th>
        </tr>
    </thead>
    <tbody>
    {% for o in recent %}
    <tr>
        <td><a href="/admin/orders/{{ o.order_number }}">{{ o.order_number }}</a></td>
        <td>{{ o.customer_name }}</td>
        <td>{{ o.total_amount|number_format(2, '.', ' ') }} Kč</td>
        <td><span class="badge badge-{{ o.status }}">{{ t('orders.status.' ~ o.status) }}</span></td>
        <td>{{ o.created_at }}</td>
    </tr>
    {% else %}
    <tr><td colspan="5">{{ t('dashboard.no_orders') }}</td></tr>
    {% endfor %}
    </tbody>
</table>
{% endblock %}
```

- [ ] **Step 3: Wire the route**

In `src/routes.php`, change line 17 from:
```php
use App\Controllers\Admin\DashboardController;
```
to:
```php
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\OrderDashboardController;
```

Then, directly after line 45 (`$group->get('/dashboard', DashboardController::class . ':index');`), add:
```php
    $group->get('/dashboard/orders', OrderDashboardController::class . ':index');
```

- [ ] **Step 4: Add the sidebar nav link**

In `templates/layout/admin-base.twig`, current line 26 is:
```twig
            <a href="/admin">{{ t('nav.dashboard') }}</a>
```

Add directly after it:
```twig
            <a href="/admin">{{ t('nav.dashboard') }}</a>
            <a href="/admin/dashboard/orders">{{ t('nav.dashboard_orders') }}</a>
```

- [ ] **Step 5: Manual smoke check (controllers/templates aren't unit-tested per project convention)**

```bash
docker compose up -d
php -S localhost:8080 -t www &
SERVER_PID=$!
sleep 1
curl -s -o /dev/null -w "orders dashboard (logged out): %{http_code}\n" http://localhost:8080/admin/dashboard/orders
kill $SERVER_PID
```
Expected: `orders dashboard (logged out): 302` (redirected to `/admin/login` by `AuthMiddleware`). Full rendered verification with real labels happens after Task 10 lands the translations — see the plan's final manual verification section.

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/Admin/OrderDashboardController.php templates/admin/dashboard-orders.twig src/routes.php templates/layout/admin-base.twig
git commit -m "feat: add orders dashboard page"
```

---

### Task 7: Products & categories dashboard page

**Files:**
- Create: `src/Controllers/Admin/ProductDashboardController.php`
- Create: `templates/admin/dashboard-products.twig`
- Modify: `src/routes.php` (add `use` import after line 17's new line from Task 6; add route after Task 6's new route line)
- Modify: `templates/layout/admin-base.twig` (add nav link after Task 6's new nav line)

**Interfaces:**
- Consumes: `ProductModel::dashboardStats()`, `ProductModel::topSellers()`, `ProductModel::recentActivity()` (Task 2); `CategoryModel::withProductCounts()` (Task 3). Reads translation keys `dashboard.products.title`, `dashboard.products.stats.categories`, `dashboard.products.stats.low_stock`, `dashboard.products.category_breakdown`, `dashboard.products.col.category`, `dashboard.products.col.count`, `dashboard.products.top_sellers`, `dashboard.products.col.product`, `dashboard.products.col.qty_sold`, `dashboard.products.recent_activity`, `dashboard.products.col.updated`, `dashboard.products.no_data`, plus existing `dashboard.stats.products_active`, `nav.dashboard_products` — added in Task 10.
- Produces: route `GET /admin/dashboard/products`.

- [ ] **Step 1: Create the controller**

Create `src/Controllers/Admin/ProductDashboardController.php`:

```php
<?php
namespace App\Controllers\Admin;

use App\Models\ProductModel;
use App\Models\CategoryModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProductDashboardController extends AdminBaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang = (string) $request->getAttribute('admin_lang', 'cs');

        return $this->renderAdmin($request, $response, 'admin/dashboard-products.twig', [
            'stats'      => ProductModel::dashboardStats(),
            'categories' => CategoryModel::withProductCounts($lang),
            'sellers'    => ProductModel::topSellers(10),
            'recent'     => ProductModel::recentActivity($lang, 10),
        ]);
    }
}
```

- [ ] **Step 2: Create the template**

Create `templates/admin/dashboard-products.twig`:

```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ t('dashboard.products.title') }}{% endblock %}
{% block content %}
<div class="admin-topbar"><h1>{{ t('dashboard.products.title') }}</h1></div>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value">{{ stats.active_count }}</div>
        <div class="stat-label">{{ t('dashboard.stats.products_active') }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">{{ categories|length }}</div>
        <div class="stat-label">{{ t('dashboard.products.stats.categories') }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">{{ stats.low_stock_count }}</div>
        <div class="stat-label">{{ t('dashboard.products.stats.low_stock') }}</div>
    </div>
</div>

<h2>{{ t('dashboard.products.category_breakdown') }}</h2>
<table class="admin-table">
    <thead><tr><th>{{ t('dashboard.products.col.category') }}</th><th>{{ t('dashboard.products.col.count') }}</th></tr></thead>
    <tbody>
    {% for c in categories %}
    <tr><td>{{ c.name }}</td><td>{{ c.product_count }}</td></tr>
    {% else %}
    <tr><td colspan="2">{{ t('dashboard.products.no_data') }}</td></tr>
    {% endfor %}
    </tbody>
</table>

<h2>{{ t('dashboard.products.top_sellers') }}</h2>
<table class="admin-table">
    <thead><tr><th>{{ t('dashboard.products.col.product') }}</th><th>{{ t('dashboard.products.col.qty_sold') }}</th></tr></thead>
    <tbody>
    {% for s in sellers %}
    <tr><td>{{ s.name }}</td><td>{{ s.qty_sold }}</td></tr>
    {% else %}
    <tr><td colspan="2">{{ t('dashboard.products.no_data') }}</td></tr>
    {% endfor %}
    </tbody>
</table>

<h2>{{ t('dashboard.products.recent_activity') }}</h2>
<table class="admin-table">
    <thead><tr><th>{{ t('dashboard.products.col.product') }}</th><th>{{ t('dashboard.products.col.updated') }}</th></tr></thead>
    <tbody>
    {% for p in recent %}
    <tr><td><a href="/admin/products/{{ p.id }}/edit">{{ p.name }}</a></td><td>{{ p.updated_at }}</td></tr>
    {% else %}
    <tr><td colspan="2">{{ t('dashboard.products.no_data') }}</td></tr>
    {% endfor %}
    </tbody>
</table>
{% endblock %}
```

- [ ] **Step 3: Wire the route**

In `src/routes.php`, change the `use` line added by Task 6:
```php
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\OrderDashboardController;
```
to:
```php
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\OrderDashboardController;
use App\Controllers\Admin\ProductDashboardController;
```

Then, directly after the route Task 6 added (`$group->get('/dashboard/orders', OrderDashboardController::class . ':index');`), add:
```php
    $group->get('/dashboard/products', ProductDashboardController::class . ':index');
```

- [ ] **Step 4: Add the sidebar nav link**

In `templates/layout/admin-base.twig`, directly after the line Task 6 added (`<a href="/admin/dashboard/orders">{{ t('nav.dashboard_orders') }}</a>`), add:
```twig
            <a href="/admin/dashboard/products">{{ t('nav.dashboard_products') }}</a>
```

- [ ] **Step 5: Manual smoke check**

```bash
docker compose up -d
php -S localhost:8080 -t www &
SERVER_PID=$!
sleep 1
curl -s -o /dev/null -w "products dashboard (logged out): %{http_code}\n" http://localhost:8080/admin/dashboard/products
kill $SERVER_PID
```
Expected: `products dashboard (logged out): 302`

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/Admin/ProductDashboardController.php templates/admin/dashboard-products.twig src/routes.php templates/layout/admin-base.twig
git commit -m "feat: add products and categories dashboard page"
```

---

### Task 8: Customers dashboard page

**Files:**
- Create: `src/Controllers/Admin/CustomerDashboardController.php`
- Create: `templates/admin/dashboard-customers.twig`
- Modify: `src/routes.php` (add `use` import after Task 7's new line; add route after Task 7's new route line)
- Modify: `templates/layout/admin-base.twig` (add nav link after Task 7's new nav line)

**Interfaces:**
- Consumes: `CustomerModel::dashboardStats()`, `CustomerModel::signupsByDay()`, `CustomerModel::recent()` (Task 4). Reads translation keys `dashboard.customers.title`, `dashboard.customers.stats.total/new_week/new_month`, `dashboard.customers.signup_trend`, `dashboard.customers.recent_registrations`, `dashboard.customers.col.email/name/phone/registered`, `dashboard.customers.no_customers`, `nav.dashboard_customers` — added in Task 10.
- Produces: route `GET /admin/dashboard/customers` — this is the first admin-visible listing of the `customers` table.

- [ ] **Step 1: Create the controller**

Create `src/Controllers/Admin/CustomerDashboardController.php`:

```php
<?php
namespace App\Controllers\Admin;

use App\Models\CustomerModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CustomerDashboardController extends AdminBaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $signups = CustomerModel::signupsByDay(30);
        $max     = 0;
        foreach ($signups as $day) {
            $max = max($max, $day['count']);
        }
        foreach ($signups as &$day) {
            $day['pct'] = $max > 0 ? (int) round(($day['count'] / $max) * 100) : 0;
        }
        unset($day);

        return $this->renderAdmin($request, $response, 'admin/dashboard-customers.twig', [
            'stats'   => CustomerModel::dashboardStats(),
            'signups' => $signups,
            'recent'  => CustomerModel::recent(10),
        ]);
    }
}
```

- [ ] **Step 2: Create the template**

Create `templates/admin/dashboard-customers.twig`:

```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ t('dashboard.customers.title') }}{% endblock %}
{% block content %}
<div class="admin-topbar"><h1>{{ t('dashboard.customers.title') }}</h1></div>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value">{{ stats.total }}</div>
        <div class="stat-label">{{ t('dashboard.customers.stats.total') }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">{{ stats.new_this_week }}</div>
        <div class="stat-label">{{ t('dashboard.customers.stats.new_week') }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">{{ stats.new_this_month }}</div>
        <div class="stat-label">{{ t('dashboard.customers.stats.new_month') }}</div>
    </div>
</div>

<h2>{{ t('dashboard.customers.signup_trend') }}</h2>
<div class="chart-bars">
    {% for day in signups %}
    <div class="chart-bar" style="height:{{ day.pct }}%" title="{{ day.date }}: {{ day.count }}" role="img" aria-label="{{ day.date }}: {{ day.count }}"></div>
    {% endfor %}
</div>

<h2>{{ t('dashboard.customers.recent_registrations') }}</h2>
<table class="admin-table">
    <thead>
        <tr>
            <th>{{ t('dashboard.customers.col.email') }}</th>
            <th>{{ t('dashboard.customers.col.name') }}</th>
            <th>{{ t('dashboard.customers.col.phone') }}</th>
            <th>{{ t('dashboard.customers.col.registered') }}</th>
        </tr>
    </thead>
    <tbody>
    {% for c in recent %}
    <tr>
        <td>{{ c.email }}</td>
        <td>{{ c.name }}</td>
        <td>{{ c.phone }}</td>
        <td>{{ c.created_at }}</td>
    </tr>
    {% else %}
    <tr><td colspan="4">{{ t('dashboard.customers.no_customers') }}</td></tr>
    {% endfor %}
    </tbody>
</table>
{% endblock %}
```

- [ ] **Step 3: Wire the route**

In `src/routes.php`, change the `use` block from Task 7:
```php
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\OrderDashboardController;
use App\Controllers\Admin\ProductDashboardController;
```
to:
```php
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\CustomerDashboardController;
use App\Controllers\Admin\OrderDashboardController;
use App\Controllers\Admin\ProductDashboardController;
```

Then, directly after the route Task 7 added (`$group->get('/dashboard/products', ProductDashboardController::class . ':index');`), add:
```php
    $group->get('/dashboard/customers', CustomerDashboardController::class . ':index');
```

- [ ] **Step 4: Add the sidebar nav link**

In `templates/layout/admin-base.twig`, directly after the line Task 7 added (`<a href="/admin/dashboard/products">{{ t('nav.dashboard_products') }}</a>`), add:
```twig
            <a href="/admin/dashboard/customers">{{ t('nav.dashboard_customers') }}</a>
```

- [ ] **Step 5: Manual smoke check**

```bash
docker compose up -d
php -S localhost:8080 -t www &
SERVER_PID=$!
sleep 1
curl -s -o /dev/null -w "customers dashboard (logged out): %{http_code}\n" http://localhost:8080/admin/dashboard/customers
kill $SERVER_PID
```
Expected: `customers dashboard (logged out): 302`

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/Admin/CustomerDashboardController.php templates/admin/dashboard-customers.twig src/routes.php templates/layout/admin-base.twig
git commit -m "feat: add customers dashboard page"
```

---

### Task 9: Trim the overview dashboard

**Files:**
- Modify: `src/Controllers/Admin/DashboardController.php` (replace entire contents)
- Modify: `templates/admin/dashboard.twig` (replace entire contents)

**Interfaces:**
- Consumes: `OrderModel::dashboardStats()['orders_today']`, `ProductModel::dashboardStats()['active_count']`, `CustomerModel::dashboardStats()['total']` (Tasks 1, 2, 4). Reuses translation keys `dashboard.title`, `dashboard.stats.orders_today`, `dashboard.stats.products_active` (existing) plus new `dashboard.stats.customers_total` (Task 10).
- Produces: nothing new consumed by later tasks — `/admin` and `/admin/dashboard` already route here (unchanged in `routes.php`).

- [ ] **Step 1: Replace the controller**

Replace the entire contents of `src/Controllers/Admin/DashboardController.php` with:

```php
<?php
namespace App\Controllers\Admin;

use App\Models\CustomerModel;
use App\Models\OrderModel;
use App\Models\ProductModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardController extends AdminBaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        return $this->renderAdmin($request, $response, 'admin/dashboard.twig', [
            'orders_today'    => OrderModel::dashboardStats()['orders_today'],
            'products_active' => ProductModel::dashboardStats()['active_count'],
            'customers_total' => CustomerModel::dashboardStats()['total'],
        ]);
    }
}
```

- [ ] **Step 2: Replace the template**

Replace the entire contents of `templates/admin/dashboard.twig` with:

```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ t('dashboard.title') }}{% endblock %}
{% block content %}
<div class="admin-topbar"><h1>{{ t('dashboard.title') }}</h1></div>
<div class="stat-grid">
    <a href="/admin/dashboard/orders" class="stat-card stat-card--link">
        <div class="stat-value">{{ orders_today }}</div>
        <div class="stat-label">{{ t('dashboard.stats.orders_today') }}</div>
    </a>
    <a href="/admin/dashboard/products" class="stat-card stat-card--link">
        <div class="stat-value">{{ products_active }}</div>
        <div class="stat-label">{{ t('dashboard.stats.products_active') }}</div>
    </a>
    <a href="/admin/dashboard/customers" class="stat-card stat-card--link">
        <div class="stat-value">{{ customers_total }}</div>
        <div class="stat-label">{{ t('dashboard.stats.customers_total') }}</div>
    </a>
</div>
{% endblock %}
```

- [ ] **Step 3: Manual smoke check**

```bash
docker compose up -d
php -S localhost:8080 -t www &
SERVER_PID=$!
sleep 1
curl -s -o /dev/null -w "overview (logged out): %{http_code}\n" http://localhost:8080/admin
kill $SERVER_PID
```
Expected: `overview (logged out): 302`

- [ ] **Step 4: Run the full unit suite to confirm no regressions**

```bash
php vendor/bin/phpunit --testdox
```
Expected: all green (this task touches no models, so no test count change).

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/Admin/DashboardController.php templates/admin/dashboard.twig
git commit -m "refactor: trim overview dashboard to 3 linked stat cards"
```

---

### Task 10: Translations (all 5 languages)

**Files:**
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/sk.json`, `lang/admin/uk.json`

**Interfaces:**
- Produces: every `dashboard.orders.*`, `dashboard.products.*`, `dashboard.customers.*`, `dashboard.stats.customers_total`, and `nav.dashboard_orders/products/customers` key referenced by Tasks 6-9's templates and nav markup.

All 5 files share identical structure (367 lines, alphabetically sorted keys). Two insertion points per file: inside the `dashboard.*` block (three separate insertions, kept alphabetical) and inside the `nav.*` block (one insertion).

- [ ] **Step 1: `lang/admin/en.json`**

Current lines 41-44 are:
```json
  "dashboard.col.total": "Total",
  "dashboard.no_orders": "No orders.",
  "dashboard.recent_orders": "Recent orders",
  "dashboard.stats.orders_pending": "Pending orders",
```

Replace with:
```json
  "dashboard.col.total": "Total",
  "dashboard.customers.col.email": "Email",
  "dashboard.customers.col.name": "Name",
  "dashboard.customers.col.phone": "Phone",
  "dashboard.customers.col.registered": "Registered",
  "dashboard.customers.no_customers": "No customers yet.",
  "dashboard.customers.recent_registrations": "Recent registrations",
  "dashboard.customers.signup_trend": "Signups — last 30 days",
  "dashboard.customers.stats.new_month": "New this month",
  "dashboard.customers.stats.new_week": "New this week",
  "dashboard.customers.stats.total": "Registered customers",
  "dashboard.customers.title": "Customers dashboard",
  "dashboard.no_orders": "No orders.",
  "dashboard.orders.revenue_trend": "Revenue — last 30 days",
  "dashboard.orders.stats.gopay_rate": "Paid via GoPay",
  "dashboard.orders.status_breakdown": "Orders by status",
  "dashboard.orders.title": "Orders dashboard",
  "dashboard.products.category_breakdown": "Products by category",
  "dashboard.products.col.category": "Category",
  "dashboard.products.col.count": "Products",
  "dashboard.products.col.product": "Product",
  "dashboard.products.col.qty_sold": "Qty sold",
  "dashboard.products.col.updated": "Updated",
  "dashboard.products.no_data": "No data yet.",
  "dashboard.products.recent_activity": "Recently added or updated",
  "dashboard.products.stats.categories": "Categories",
  "dashboard.products.stats.low_stock": "Low stock",
  "dashboard.products.title": "Products dashboard",
  "dashboard.products.top_sellers": "Top sellers",
  "dashboard.recent_orders": "Recent orders",
  "dashboard.stats.customers_total": "Registered customers",
  "dashboard.stats.orders_pending": "Pending orders",
```

Then, current lines around `nav.dashboard`/`nav.gallery` are:
```json
  "nav.dashboard": "Dashboard",
  "nav.gallery": "Completed Projects",
```

Replace with:
```json
  "nav.dashboard": "Dashboard",
  "nav.dashboard_customers": "Customers Dashboard",
  "nav.dashboard_orders": "Orders Dashboard",
  "nav.dashboard_products": "Products Dashboard",
  "nav.gallery": "Completed Projects",
```

- [ ] **Step 2: `lang/admin/cs.json`**

Current lines 41-44:
```json
  "dashboard.col.total": "Celkem",
  "dashboard.no_orders": "Žádné objednávky.",
  "dashboard.recent_orders": "Poslední objednávky",
  "dashboard.stats.orders_pending": "Čekající objednávky",
```

Replace with:
```json
  "dashboard.col.total": "Celkem",
  "dashboard.customers.col.email": "E-mail",
  "dashboard.customers.col.name": "Jméno",
  "dashboard.customers.col.phone": "Telefon",
  "dashboard.customers.col.registered": "Registrace",
  "dashboard.customers.no_customers": "Zatím žádní zákazníci.",
  "dashboard.customers.recent_registrations": "Poslední registrace",
  "dashboard.customers.signup_trend": "Registrace — posledních 30 dní",
  "dashboard.customers.stats.new_month": "Nové tento měsíc",
  "dashboard.customers.stats.new_week": "Nové tento týden",
  "dashboard.customers.stats.total": "Registrovaní zákazníci",
  "dashboard.customers.title": "Dashboard zákazníků",
  "dashboard.no_orders": "Žádné objednávky.",
  "dashboard.orders.revenue_trend": "Tržby — posledních 30 dní",
  "dashboard.orders.stats.gopay_rate": "Zaplaceno přes GoPay",
  "dashboard.orders.status_breakdown": "Objednávky podle stavu",
  "dashboard.orders.title": "Dashboard objednávek",
  "dashboard.products.category_breakdown": "Produkty podle kategorie",
  "dashboard.products.col.category": "Kategorie",
  "dashboard.products.col.count": "Produkty",
  "dashboard.products.col.product": "Produkt",
  "dashboard.products.col.qty_sold": "Prodáno ks",
  "dashboard.products.col.updated": "Upraveno",
  "dashboard.products.no_data": "Zatím žádná data.",
  "dashboard.products.recent_activity": "Nedávno přidané nebo upravené",
  "dashboard.products.stats.categories": "Kategorie",
  "dashboard.products.stats.low_stock": "Docházející zásoby",
  "dashboard.products.title": "Dashboard produktů",
  "dashboard.products.top_sellers": "Nejprodávanější",
  "dashboard.recent_orders": "Poslední objednávky",
  "dashboard.stats.customers_total": "Registrovaní zákazníci",
  "dashboard.stats.orders_pending": "Čekající objednávky",
```

Current `nav.dashboard`/`nav.gallery` lines:
```json
  "nav.dashboard": "Dashboard",
  "nav.gallery": "Naše realizace",
```

Replace with:
```json
  "nav.dashboard": "Dashboard",
  "nav.dashboard_customers": "Dashboard zákazníků",
  "nav.dashboard_orders": "Dashboard objednávek",
  "nav.dashboard_products": "Dashboard produktů",
  "nav.gallery": "Naše realizace",
```

- [ ] **Step 3: `lang/admin/sk.json`**

Current lines 41-44:
```json
  "dashboard.col.total": "Celkom",
  "dashboard.no_orders": "Žiadne objednávky.",
  "dashboard.recent_orders": "Posledné objednávky",
  "dashboard.stats.orders_pending": "Čakajúce objednávky",
```

Replace with:
```json
  "dashboard.col.total": "Celkom",
  "dashboard.customers.col.email": "E-mail",
  "dashboard.customers.col.name": "Meno",
  "dashboard.customers.col.phone": "Telefón",
  "dashboard.customers.col.registered": "Registrácia",
  "dashboard.customers.no_customers": "Zatiaľ žiadni zákazníci.",
  "dashboard.customers.recent_registrations": "Posledné registrácie",
  "dashboard.customers.signup_trend": "Registrácie — posledných 30 dní",
  "dashboard.customers.stats.new_month": "Noví tento mesiac",
  "dashboard.customers.stats.new_week": "Noví tento týždeň",
  "dashboard.customers.stats.total": "Registrovaní zákazníci",
  "dashboard.customers.title": "Dashboard zákazníkov",
  "dashboard.no_orders": "Žiadne objednávky.",
  "dashboard.orders.revenue_trend": "Tržby — posledných 30 dní",
  "dashboard.orders.stats.gopay_rate": "Zaplatené cez GoPay",
  "dashboard.orders.status_breakdown": "Objednávky podľa stavu",
  "dashboard.orders.title": "Dashboard objednávok",
  "dashboard.products.category_breakdown": "Produkty podľa kategórie",
  "dashboard.products.col.category": "Kategória",
  "dashboard.products.col.count": "Produkty",
  "dashboard.products.col.product": "Produkt",
  "dashboard.products.col.qty_sold": "Predané ks",
  "dashboard.products.col.updated": "Upravené",
  "dashboard.products.no_data": "Zatiaľ žiadne dáta.",
  "dashboard.products.recent_activity": "Nedávno pridané alebo upravené",
  "dashboard.products.stats.categories": "Kategórie",
  "dashboard.products.stats.low_stock": "Dochádzajúce zásoby",
  "dashboard.products.title": "Dashboard produktov",
  "dashboard.products.top_sellers": "Najpredávanejšie",
  "dashboard.recent_orders": "Posledné objednávky",
  "dashboard.stats.customers_total": "Registrovaní zákazníci",
  "dashboard.stats.orders_pending": "Čakajúce objednávky",
```

Current `nav.dashboard`/`nav.gallery` lines:
```json
  "nav.dashboard": "Dashboard",
  "nav.gallery": "Naše realizácie",
```

Replace with:
```json
  "nav.dashboard": "Dashboard",
  "nav.dashboard_customers": "Dashboard zákazníkov",
  "nav.dashboard_orders": "Dashboard objednávok",
  "nav.dashboard_products": "Dashboard produktov",
  "nav.gallery": "Naše realizácie",
```

- [ ] **Step 4: `lang/admin/ru.json`**

Current lines 41-44:
```json
  "dashboard.col.total": "Итого",
  "dashboard.no_orders": "Нет заказов.",
  "dashboard.recent_orders": "Последние заказы",
  "dashboard.stats.orders_pending": "Ожидающие заказы",
```

Replace with:
```json
  "dashboard.col.total": "Итого",
  "dashboard.customers.col.email": "Email",
  "dashboard.customers.col.name": "Имя",
  "dashboard.customers.col.phone": "Телефон",
  "dashboard.customers.col.registered": "Регистрация",
  "dashboard.customers.no_customers": "Пока нет клиентов.",
  "dashboard.customers.recent_registrations": "Последние регистрации",
  "dashboard.customers.signup_trend": "Регистрации — последние 30 дней",
  "dashboard.customers.stats.new_month": "Новые за месяц",
  "dashboard.customers.stats.new_week": "Новые за неделю",
  "dashboard.customers.stats.total": "Зарегистрированные клиенты",
  "dashboard.customers.title": "Панель клиентов",
  "dashboard.no_orders": "Нет заказов.",
  "dashboard.orders.revenue_trend": "Выручка — последние 30 дней",
  "dashboard.orders.stats.gopay_rate": "Оплачено через GoPay",
  "dashboard.orders.status_breakdown": "Заказы по статусу",
  "dashboard.orders.title": "Панель заказов",
  "dashboard.products.category_breakdown": "Товары по категориям",
  "dashboard.products.col.category": "Категория",
  "dashboard.products.col.count": "Товары",
  "dashboard.products.col.product": "Товар",
  "dashboard.products.col.qty_sold": "Продано шт.",
  "dashboard.products.col.updated": "Обновлено",
  "dashboard.products.no_data": "Пока нет данных.",
  "dashboard.products.recent_activity": "Недавно добавленные или изменённые",
  "dashboard.products.stats.categories": "Категории",
  "dashboard.products.stats.low_stock": "Заканчивается на складе",
  "dashboard.products.title": "Панель товаров",
  "dashboard.products.top_sellers": "Хиты продаж",
  "dashboard.recent_orders": "Последние заказы",
  "dashboard.stats.customers_total": "Зарегистрированные клиенты",
  "dashboard.stats.orders_pending": "Ожидающие заказы",
```

Current `nav.dashboard`/`nav.gallery` lines:
```json
  "nav.dashboard": "Панель",
  "nav.gallery": "Архив оказанных услуг",
```

Replace with:
```json
  "nav.dashboard": "Панель",
  "nav.dashboard_customers": "Панель клиентов",
  "nav.dashboard_orders": "Панель заказов",
  "nav.dashboard_products": "Панель товаров",
  "nav.gallery": "Архив оказанных услуг",
```

- [ ] **Step 5: `lang/admin/uk.json`**

Current lines 41-44:
```json
  "dashboard.col.total": "Разом",
  "dashboard.no_orders": "Немає замовлень.",
  "dashboard.recent_orders": "Останні замовлення",
  "dashboard.stats.orders_pending": "Очікуючі замовлення",
```

Replace with:
```json
  "dashboard.col.total": "Разом",
  "dashboard.customers.col.email": "Email",
  "dashboard.customers.col.name": "Ім'я",
  "dashboard.customers.col.phone": "Телефон",
  "dashboard.customers.col.registered": "Реєстрація",
  "dashboard.customers.no_customers": "Поки немає клієнтів.",
  "dashboard.customers.recent_registrations": "Останні реєстрації",
  "dashboard.customers.signup_trend": "Реєстрації — останні 30 днів",
  "dashboard.customers.stats.new_month": "Нові за місяць",
  "dashboard.customers.stats.new_week": "Нові за тиждень",
  "dashboard.customers.stats.total": "Зареєстровані клієнти",
  "dashboard.customers.title": "Панель клієнтів",
  "dashboard.no_orders": "Немає замовлень.",
  "dashboard.orders.revenue_trend": "Виручка — останні 30 днів",
  "dashboard.orders.stats.gopay_rate": "Оплачено через GoPay",
  "dashboard.orders.status_breakdown": "Замовлення за статусом",
  "dashboard.orders.title": "Панель замовлень",
  "dashboard.products.category_breakdown": "Товари за категоріями",
  "dashboard.products.col.category": "Категорія",
  "dashboard.products.col.count": "Товари",
  "dashboard.products.col.product": "Товар",
  "dashboard.products.col.qty_sold": "Продано шт.",
  "dashboard.products.col.updated": "Оновлено",
  "dashboard.products.no_data": "Поки немає даних.",
  "dashboard.products.recent_activity": "Нещодавно додані або змінені",
  "dashboard.products.stats.categories": "Категорії",
  "dashboard.products.stats.low_stock": "Закінчується на складі",
  "dashboard.products.title": "Панель товарів",
  "dashboard.products.top_sellers": "Хіти продажів",
  "dashboard.recent_orders": "Останні замовлення",
  "dashboard.stats.customers_total": "Зареєстровані клієнти",
  "dashboard.stats.orders_pending": "Очікуючі замовлення",
```

Current `nav.dashboard`/`nav.gallery` lines:
```json
  "nav.dashboard": "Панель",
  "nav.gallery": "Архів наданих послуг",
```

Replace with:
```json
  "nav.dashboard": "Панель",
  "nav.dashboard_customers": "Панель клієнтів",
  "nav.dashboard_orders": "Панель замовлень",
  "nav.dashboard_products": "Панель товарів",
  "nav.gallery": "Архів наданих послуг",
```

- [ ] **Step 6: Verify all 5 files parse and have identical key sets**

```bash
for f in lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/sk.json lang/admin/uk.json; do
  php -r "json_decode(file_get_contents('$f'), true, 512, JSON_THROW_ON_ERROR); echo '$f OK\n';"
done
php -r '
$files = ["lang/admin/cs.json","lang/admin/en.json","lang/admin/ru.json","lang/admin/sk.json","lang/admin/uk.json"];
$sets = array_map(fn($f) => array_keys(json_decode(file_get_contents($f), true)), $files);
$base = $sets[0];
sort($base);
foreach ($sets as $i => $s) {
    sort($s);
    if ($s !== $base) { echo $files[$i] . " key set MISMATCH\n"; exit(1); }
}
echo "All 5 files have identical key sets\n";
'
```
Expected: `<file> OK` five times, then `All 5 files have identical key sets`.

- [ ] **Step 7: Commit**

```bash
git add lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/sk.json lang/admin/uk.json
git commit -m "feat: add dashboard split translations to all 5 admin languages"
```

---

### Task 11: Full suite + manual browser verification

**Files:** none (verification only)

- [ ] **Step 1: Run the full unit suite**

```bash
docker compose up -d
until docker compose exec -T db mysqladmin ping -ubalonky -pbalonky --silent 2>/dev/null; do sleep 1; done
php vendor/bin/phpunit --testdox
```
Expected: all green, 10 more tests than the pre-plan baseline (3 new `OrderModel` tests + 3 new `ProductModel` tests + 1 new `CategoryModel` test + 3 new `CustomerModel` tests, from Tasks 1-4).

- [ ] **Step 2: Manual browser verification**

Per `.claude/rules/unit-testing.md`, Twig/CSS aren't unit-tested — verify by hand:

```bash
docker compose up -d
php -S localhost:8080 -t www
```

1. Log in at `http://localhost:8080/admin/login` with an existing admin user (or `/admin/setup` if the `users` table is empty).
2. On `/admin`, confirm 3 clickable stat cards: "Orders today", "Active products", "Registered customers", each linking to its own dashboard.
3. Click into `/admin/dashboard/orders` — confirm 4 stat cards (today/pending/total/GoPay %), a 5-status breakdown row, a bar chart of the last 30 days' revenue (hover a bar to see its tooltip), and the recent-orders table.
4. Click into `/admin/dashboard/products` — confirm 3 stat cards (active/categories/low-stock), a category breakdown table, a top-sellers table, and a recent-activity table (create or edit a product first if the store has none yet, to see a non-empty row).
5. Click into `/admin/dashboard/customers` — confirm 3 stat cards, a 30-day signup bar chart, and a recent-registrations table (register a test customer via `/cs/register` first if the `customers` table is empty).
6. Confirm the sidebar shows "Dashboard", "Orders Dashboard", "Products Dashboard", "Customers Dashboard" as four distinct links above the existing "Products"/"Categories"/"Orders" CRUD links.
7. Switch the admin language (footer language switcher) to at least one other language (e.g. Czech) and repeat steps 2-4 to confirm translations render (no raw key names visible, no empty labels).
8. Confirm the low-stock stat reacts: in `/admin/products`, set a product to "limited" stock with quantity ≤ 5, then revisit `/admin/dashboard/products` and confirm "Low stock" incremented by 1.

No further commit for this task — it's verification only.
