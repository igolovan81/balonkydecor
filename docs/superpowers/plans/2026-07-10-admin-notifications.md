# Admin Change Notifications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Notify admins/editors in-app when a colleague creates, updates, or deletes a category, product, or service.

**Architecture:** A new `notifications` table stores one row per recipient (fan-out on write, written at the moment of the action). A `NotificationModel` (static, PDO) provides the CRUD; a thin `Notifier` service wraps it so `CategoryController`/`ProductController`/`ServiceController` gain a single call each. A new `NotificationController` exposes a history page plus two small JSON endpoints that a vanilla-JS file polls/calls to drive a bell+badge in the admin sidebar.

**Tech Stack:** Slim 4, Twig 3, PDO/MySQL 8, vanilla JS (no build step) — same stack as the rest of the app.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-10-admin-notifications-design.md` — read it before starting; this plan implements it exactly.
- New migration file: `database/migrations/V016__notifications.sql` (next version after `V015`). Never edit an applied migration.
- All translation keys must be added to **all 5** `lang/admin/{cs,en,ru,uk,sk}.json` files, keeping each file's keys alphabetically sorted (existing convention — verify with the check command in Task 5).
- Models are static classes over `Database::getConnection()`; prepared statements with bound parameters always; `LIMIT`/`OFFSET` values use `bindValue(..., \PDO::PARAM_INT)` (native prepares are on — `PDO::ATTR_EMULATE_PREPARES => false` — so these can't be plain `?` placeholders bound as strings).
- Admin controllers extend `AdminBaseController`; use `$this->renderAdmin(...)`, `$this->flash('success'|'error', 'translation.key')`, `$this->redirect(...)` (POST-redirect-GET).
- Run `php vendor/bin/phpunit` (full suite) before every commit that touches PHP — it must stay green.
- Docker MySQL must be running for model/service tests: `docker compose up -d`.

---

## Task 1: Migration — `notifications` table

**Files:**
- Create: `database/migrations/V016__notifications.sql`

**Interfaces:**
- Produces: table `notifications(id, recipient_id, actor_id, actor_label, entity_type, entity_id, entity_label, action, is_read, created_at)` — consumed by `NotificationModel` in Task 2.

- [ ] **Step 1: Write the migration**

```sql
CREATE TABLE `notifications` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `recipient_id` INT NOT NULL,
  `actor_id`     INT NULL,
  `actor_label`  VARCHAR(255) NOT NULL,
  `entity_type`  ENUM('category','product','service') NOT NULL,
  `entity_id`    INT NOT NULL,
  `entity_label` VARCHAR(255) NOT NULL,
  `action`       ENUM('created','updated','deleted') NOT NULL,
  `is_read`      TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_notifications_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notifications_actor` FOREIGN KEY (`actor_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_notifications_recipient_unread` (`recipient_id`, `is_read`),
  INDEX `idx_notifications_recipient_created` (`recipient_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Start MySQL if it isn't running**

```bash
docker compose up -d
until docker compose exec -T db mysqladmin ping -ubalonky -pbalonky --silent 2>/dev/null; do sleep 1; done
```

- [ ] **Step 3: Apply the migration to the local DB**

If the local PHP server isn't already running, start it first: `php -S localhost:8080 -t www` (background).

```bash
MIGRATE_TOKEN=$(php -r "echo (require 'config/settings.php')['migrate_token'];")
curl -s "http://localhost:8080/migrate.php?token=${MIGRATE_TOKEN}" | python3 -m json.tool
```

Expected: `{"applied": ["V016__notifications"], "count": 1}` (or `count: 0` only if it was already applied by a previous run).

- [ ] **Step 4: Verify the table exists with the right shape**

```bash
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e "DESCRIBE notifications;"
```

Expected: 10 columns matching Step 1 (`id`, `recipient_id`, `actor_id`, `actor_label`, `entity_type`, `entity_id`, `entity_label`, `action`, `is_read`, `created_at`).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/V016__notifications.sql
git commit -m "feat: add notifications table migration"
```

---

## Task 2: `NotificationModel`

**Files:**
- Create: `src/Models/NotificationModel.php`
- Test: `tests/Unit/Models/NotificationModelTest.php`

**Interfaces:**
- Consumes: table `notifications` from Task 1; `Database::getConnection()`.
- Produces (used by `Notifier` in Task 3 and `NotificationController` in Task 5):
  - `NotificationModel::create(string $entityType, int $entityId, string $entityLabel, string $action, int $actorId, string $actorLabel): void`
  - `NotificationModel::unreadCount(int $userId): int`
  - `NotificationModel::recentAndMarkRead(int $userId, int $limit = 20): array` — returns rows (assoc arrays with all table columns), newest first, and marks every previously-unread row for that user as read as a side effect.
  - `NotificationModel::forUser(int $userId, int $page = 1, int $perPage = 20): array` — returns `['items' => array, 'total' => int, 'pages' => int]`, newest first, does not affect `is_read`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Models/NotificationModelTest.php`:

```php
<?php
namespace Tests\Unit\Models;

use App\Models\Database;
use App\Models\NotificationModel;
use PHPUnit\Framework\TestCase;

class NotificationModelTest extends TestCase
{
    private static int $actorId;
    private static int $recipientId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('notif-actor@example.com', 'x', 'editor')");
        self::$actorId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='notif-actor@example.com'"
        )->fetch()['id'];

        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('notif-recipient@example.com', 'x', 'admin')");
        self::$recipientId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='notif-recipient@example.com'"
        )->fetch()['id'];
    }

    public function test_create_notifies_every_user_except_the_actor(): void
    {
        $label = 'Test Category ' . uniqid();
        NotificationModel::create('category', 999, $label, 'created', self::$actorId, 'notif-actor@example.com');

        $rows  = NotificationModel::recentAndMarkRead(self::$recipientId, 50);
        $match = array_values(array_filter($rows, fn($r) => $r['entity_label'] === $label));

        $this->assertNotEmpty($match);
        $this->assertSame('category', $match[0]['entity_type']);
        $this->assertSame(999, (int) $match[0]['entity_id']);
        $this->assertSame('created', $match[0]['action']);
        $this->assertSame('notif-actor@example.com', $match[0]['actor_label']);
    }

    public function test_create_does_not_notify_the_actor(): void
    {
        $label = 'Self Test ' . uniqid();
        NotificationModel::create('product', 999, $label, 'updated', self::$actorId, 'notif-actor@example.com');

        $rows  = NotificationModel::recentAndMarkRead(self::$actorId, 50);
        $match = array_filter($rows, fn($r) => $r['entity_label'] === $label);

        $this->assertEmpty($match);
    }

    public function test_recent_and_mark_read_zeroes_the_unread_count(): void
    {
        $label = 'Unread Test ' . uniqid();
        NotificationModel::create('service', 999, $label, 'deleted', self::$actorId, 'notif-actor@example.com');

        $this->assertGreaterThan(0, NotificationModel::unreadCount(self::$recipientId));

        NotificationModel::recentAndMarkRead(self::$recipientId, 50);

        $this->assertSame(0, NotificationModel::unreadCount(self::$recipientId));
    }

    public function test_recent_and_mark_read_orders_newest_first(): void
    {
        $first  = 'Order Test A ' . uniqid();
        $second = 'Order Test B ' . uniqid();
        NotificationModel::create('category', 1001, $first, 'created', self::$actorId, 'notif-actor@example.com');
        NotificationModel::create('category', 1002, $second, 'created', self::$actorId, 'notif-actor@example.com');

        $labels        = array_column(NotificationModel::recentAndMarkRead(self::$recipientId, 50), 'entity_label');
        $secondIndex   = array_search($second, $labels, true);
        $firstIndex    = array_search($first, $labels, true);

        $this->assertLessThan($firstIndex, $secondIndex);
    }

    public function test_for_user_paginates(): void
    {
        for ($i = 0; $i < 3; $i++) {
            NotificationModel::create(
                'product', 2000 + $i, 'Page Test ' . uniqid(), 'updated', self::$actorId, 'notif-actor@example.com'
            );
        }

        $page1 = NotificationModel::forUser(self::$recipientId, 1, 2);

        $this->assertCount(2, $page1['items']);
        $this->assertGreaterThanOrEqual(3, $page1['total']);
        $this->assertGreaterThanOrEqual(2, $page1['pages']);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php vendor/bin/phpunit tests/Unit/Models/NotificationModelTest.php
```

Expected: FAIL with `Class "App\Models\NotificationModel" not found`.

- [ ] **Step 3: Implement `NotificationModel`**

Create `src/Models/NotificationModel.php`:

```php
<?php
namespace App\Models;

class NotificationModel
{
    public static function create(
        string $entityType,
        int $entityId,
        string $entityLabel,
        string $action,
        int $actorId,
        string $actorLabel
    ): void {
        $pdo = Database::getConnection();

        $recipients = $pdo->prepare('SELECT id FROM users WHERE id != ?');
        $recipients->execute([$actorId]);
        $recipientIds = $recipients->fetchAll(\PDO::FETCH_COLUMN);

        if (!$recipientIds) return;

        $stmt = $pdo->prepare(
            'INSERT INTO notifications (recipient_id, actor_id, actor_label, entity_type, entity_id, entity_label, action)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($recipientIds as $recipientId) {
            $stmt->execute([$recipientId, $actorId, $actorLabel, $entityType, $entityId, $entityLabel, $action]);
        }
    }

    public static function unreadCount(int $userId): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public static function recentAndMarkRead(int $userId, int $limit = 20): array
    {
        $pdo = Database::getConnection();

        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE recipient_id = ? AND is_read = 0')
            ->execute([$userId]);

        $stmt = $pdo->prepare(
            'SELECT * FROM notifications WHERE recipient_id = :uid ORDER BY created_at DESC, id DESC LIMIT :limit'
        );
        $stmt->bindValue(':uid', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function forUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        $pdo = Database::getConnection();

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_id = ?');
        $countStmt->execute([$userId]);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt   = $pdo->prepare(
            'SELECT * FROM notifications WHERE recipient_id = :uid
             ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':uid', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
php vendor/bin/phpunit tests/Unit/Models/NotificationModelTest.php
```

Expected: `OK (5 tests, ...)`.

- [ ] **Step 5: Run the full suite to confirm no regressions**

```bash
php vendor/bin/phpunit
```

Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/Models/NotificationModel.php tests/Unit/Models/NotificationModelTest.php
git commit -m "feat: add NotificationModel"
```

---

## Task 3: `Notifier` service

**Files:**
- Create: `src/Services/Notifier.php`
- Test: `tests/Unit/Services/NotifierTest.php`

**Interfaces:**
- Consumes: `NotificationModel::create()` (Task 2).
- Produces (used by controllers in Task 4):
  `Notifier::notify(string $entityType, int $entityId, string $entityLabel, string $action, int $actorId, string $actorLabel): void`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/NotifierTest.php`:

```php
<?php
namespace Tests\Unit\Services;

use App\Models\Database;
use App\Models\NotificationModel;
use App\Services\Notifier;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase
{
    private static int $actorId;
    private static int $recipientId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('notifier-actor@example.com', 'x', 'editor')");
        self::$actorId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='notifier-actor@example.com'"
        )->fetch()['id'];

        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('notifier-recipient@example.com', 'x', 'admin')");
        self::$recipientId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='notifier-recipient@example.com'"
        )->fetch()['id'];
    }

    public function test_notify_creates_a_notification_with_the_given_fields(): void
    {
        $label = 'Notifier Test ' . uniqid();
        Notifier::notify('product', 4242, $label, 'updated', self::$actorId, 'notifier-actor@example.com');

        $rows  = NotificationModel::recentAndMarkRead(self::$recipientId, 50);
        $match = array_values(array_filter($rows, fn($r) => $r['entity_label'] === $label));

        $this->assertNotEmpty($match);
        $this->assertSame('product', $match[0]['entity_type']);
        $this->assertSame(4242, (int) $match[0]['entity_id']);
        $this->assertSame('updated', $match[0]['action']);
        $this->assertSame('notifier-actor@example.com', $match[0]['actor_label']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php vendor/bin/phpunit tests/Unit/Services/NotifierTest.php
```

Expected: FAIL with `Class "App\Services\Notifier" not found`.

- [ ] **Step 3: Implement `Notifier`**

Create `src/Services/Notifier.php`:

```php
<?php
namespace App\Services;

use App\Models\NotificationModel;

class Notifier
{
    public static function notify(
        string $entityType,
        int $entityId,
        string $entityLabel,
        string $action,
        int $actorId,
        string $actorLabel
    ): void {
        NotificationModel::create($entityType, $entityId, $entityLabel, $action, $actorId, $actorLabel);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php vendor/bin/phpunit tests/Unit/Services/NotifierTest.php
```

Expected: `OK (1 test, ...)`.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Notifier.php tests/Unit/Services/NotifierTest.php
git commit -m "feat: add Notifier service"
```

---

## Task 4: Wire `Notifier` into Category/Product/Service admin controllers

**Files:**
- Modify: `src/Controllers/Admin/CategoryController.php`
- Modify: `src/Controllers/Admin/ProductController.php`
- Modify: `src/Controllers/Admin/ServiceController.php`

**Interfaces:**
- Consumes: `\App\Services\Notifier::notify(...)` (Task 3, called fully-qualified inline — matches this file's existing style of calling `\App\Services\Translator::autoFill(...)` without a `use` import).
- Produces: no new interfaces — this task only adds side effects to existing methods.

Controllers are not unit-tested per project convention (`.claude/rules/unit-testing.md`); this task is verified by the full suite staying green plus a manual DB check.

- [ ] **Step 1: Update `CategoryController`**

In `src/Controllers/Admin/CategoryController.php`, add a private label helper and call `Notifier::notify` from `createSubmit`, `editSubmit`, and `delete`. Replace the whole class body with:

```php
<?php
namespace App\Controllers\Admin;

use App\Models\CategoryModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CategoryController extends AdminBaseController
{
    private const LANGS               = ['cs', 'sk', 'en', 'uk', 'ru'];
    private const TRANSLATABLE_FIELDS = ['name', 'description'];

    public function index(Request $request, Response $response, array $args): Response
    {
        $categories = CategoryModel::all();
        return $this->renderAdmin($request, $response, 'admin/categories/index.twig', compact('categories'));
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        return $this->renderAdmin($request, $response, 'admin/categories/form.twig', [
            'category'     => null,
            'translations' => [],
            'langs'        => self::LANGS,
        ]);
    }

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
        \App\Services\Notifier::notify(
            'category', $id, $this->categoryLabel($translations, $body),
            'created', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'categories.flash.created');
        return $this->redirect($response, '/admin/categories');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $category = CategoryModel::findById((int) $args['id']);
        if (!$category) return $response->withStatus(404);
        $translations = CategoryModel::getTranslations((int) $args['id']);
        return $this->renderAdmin($request, $response, 'admin/categories/form.twig', [
            'category'     => $category,
            'translations' => $translations,
            'langs'        => self::LANGS,
        ]);
    }

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
        $translations = $body['t'] ?? [];
        CategoryModel::setTranslations($id, $translations);
        \App\Services\Notifier::notify(
            'category', $id, $this->categoryLabel($translations, $body),
            'updated', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'categories.flash.updated');
        return $this->redirect($response, '/admin/categories');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if (CategoryModel::hasProducts($id)) {
            $this->flash('error', 'categories.flash.delete_blocked');
            return $this->redirect($response, '/admin/categories');
        }
        $category     = CategoryModel::findById($id);
        $translations = CategoryModel::getTranslations($id);
        CategoryModel::delete($id);
        if ($category) {
            $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
            \App\Services\Notifier::notify(
                'category', $id, $this->categoryLabel($translations, $category),
                'deleted', $userId, $_SESSION['admin_user']['email'] ?? ''
            );
        }
        $this->flash('success', 'categories.flash.deleted');
        return $this->redirect($response, '/admin/categories');
    }

    private function categoryLabel(array $translations, array $data): string
    {
        $name = $translations['cs']['name'] ?? '';
        if ($name !== '') return $name;
        $slug = trim($data['slug'] ?? '');
        return $slug !== '' ? $slug : 'category';
    }
}
```

- [ ] **Step 2: Update `ProductController`**

In `src/Controllers/Admin/ProductController.php`, use the SKU as the label (no translation lookup needed) and notify from `createSubmit`, `editSubmit`, and `delete`:

```php
<?php
namespace App\Controllers\Admin;

use App\Models\CategoryModel;
use App\Models\ProductModel;
use App\Services\ImageUploader;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProductController extends AdminBaseController
{
    private const LANGS                = ['cs', 'en', 'ru', 'uk', 'sk'];
    private const TRANSLATABLE_FIELDS  = ['name', 'description', 'meta_title', 'meta_desc'];
    private const UPLOAD_DIR           = __DIR__ . '/../../../www/assets/uploads/products';

    public function index(Request $request, Response $response, array $args): Response
    {
        $products = ProductModel::all();
        return $this->renderAdmin($request, $response, 'admin/products/index.twig', compact('products'));
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        $categories = CategoryModel::allWithTranslation('cs');
        return $this->renderAdmin($request, $response, 'admin/products/form.twig', [
            'product'      => null,
            'translations' => [],
            'categories'   => $categories,
            'langs'        => self::LANGS,
        ]);
    }

    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $sku    = trim($body['sku'] ?? '');
        $id     = ProductModel::create([
            'sku'         => $sku,
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
        \App\Services\Notifier::notify(
            'product', $id, $sku, 'created', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'products.flash.created');
        return $this->redirect($response, '/admin/products');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $product = ProductModel::findById((int) $args['id']);
        if (!$product) return $response->withStatus(404);
        $translations = ProductModel::getTranslations((int) $args['id']);
        $categories   = CategoryModel::allWithTranslation('cs');
        return $this->renderAdmin($request, $response, 'admin/products/form.twig', [
            'product'      => $product,
            'translations' => $translations,
            'categories'   => $categories,
            'langs'        => self::LANGS,
        ]);
    }

    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id     = (int) $args['id'];
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $sku    = trim($body['sku'] ?? '');
        ProductModel::update($id, [
            'sku'         => $sku,
            'price'       => $body['price'] ?? '0.00',
            'category_id' => $body['category_id'] ?? 1,
            'is_active'   => isset($body['is_active']) ? 1 : 0,
            'stock_type'  => $body['stock_type'] ?? 'unlimited',
            'stock_qty'   => $body['stock_qty'] ?? 0,
        ], $userId);
        ProductModel::setTranslations($id, $body['t'] ?? []);
        $this->handleImageUpload($request, $id, false);
        \App\Services\Notifier::notify(
            'product', $id, $sku, 'updated', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'products.flash.updated');
        return $this->redirect($response, '/admin/products');
    }

    public function deleteImage(Request $request, Response $response, array $args): Response
    {
        $filename = ProductModel::deleteImage((int) $args['image_id']);
        if ($filename) {
            @unlink(self::UPLOAD_DIR . '/' . $filename);
            @unlink(self::UPLOAD_DIR . '/thumb_' . $filename);
        }
        $this->flash('success', 'products.flash.image_deleted');
        return $this->redirect($response, '/admin/products/' . $args['id'] . '/edit');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id      = (int) $args['id'];
        $product = ProductModel::findById($id);
        if ($product) {
            foreach ($product['images'] as $img) {
                @unlink(self::UPLOAD_DIR . '/' . $img['filename']);
                @unlink(self::UPLOAD_DIR . '/thumb_' . $img['filename']);
            }
            ProductModel::delete($id);
            $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
            \App\Services\Notifier::notify(
                'product', $id, $product['sku'], 'deleted', $userId, $_SESSION['admin_user']['email'] ?? ''
            );
        }
        $this->flash('success', 'products.flash.deleted');
        return $this->redirect($response, '/admin/products');
    }

    private function handleImageUpload(Request $request, int $productId, bool $isPrimary): void
    {
        $files = $request->getUploadedFiles();
        $file  = $files['image'] ?? null;
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) return;

        $tmp      = ['tmp_name' => $file->getStream()->getMetadata('uri'), 'error' => $file->getError()];
        $filename = ImageUploader::upload($tmp, self::UPLOAD_DIR);
        ProductModel::addImage($productId, $filename, $isPrimary);
    }
}
```

- [ ] **Step 3: Update `ServiceController`**

In `src/Controllers/Admin/ServiceController.php`, use the same `cs`-name-with-fallback pattern as categories (services have no `slug`, so fall back to `#{id}`):

```php
<?php
namespace App\Controllers\Admin;

use App\Models\ServiceModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ServiceController extends AdminBaseController
{
    private const LANGS               = ['cs', 'sk', 'en', 'uk', 'ru'];
    private const TRANSLATABLE_FIELDS = ['name', 'description', 'features'];

    public function index(Request $request, Response $response, array $args): Response
    {
        $services = ServiceModel::all();
        return $this->renderAdmin($request, $response, 'admin/services/index.twig', compact('services'));
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        return $this->renderAdmin($request, $response, 'admin/services/form.twig', [
            'service'      => null,
            'translations' => [],
            'langs'        => self::LANGS,
        ]);
    }

    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $id   = ServiceModel::create([
            'price_from' => trim($body['price_from'] ?? ''),
            'sort_order' => (int) ($body['sort_order'] ?? 0),
        ]);
        $translations = \App\Services\Translator::autoFill(
            $body['t'] ?? [],
            $request->getAttribute('admin_lang', 'cs'),
            self::LANGS,
            self::TRANSLATABLE_FIELDS
        );
        ServiceModel::setTranslations($id, $translations);
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        \App\Services\Notifier::notify(
            'service', $id, $this->serviceLabel($translations, $id),
            'created', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'services.flash.created');
        return $this->redirect($response, '/admin/services');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $service = ServiceModel::findById((int) $args['id']);
        if (!$service) return $response->withStatus(404);
        $translations = ServiceModel::getTranslations((int) $args['id']);
        return $this->renderAdmin($request, $response, 'admin/services/form.twig', [
            'service'      => $service,
            'translations' => $translations,
            'langs'        => self::LANGS,
        ]);
    }

    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $body = (array) $request->getParsedBody();
        ServiceModel::update($id, [
            'price_from' => trim($body['price_from'] ?? ''),
            'sort_order' => (int) ($body['sort_order'] ?? 0),
        ]);
        $translations = $body['t'] ?? [];
        ServiceModel::setTranslations($id, $translations);
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        \App\Services\Notifier::notify(
            'service', $id, $this->serviceLabel($translations, $id),
            'updated', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'services.flash.updated');
        return $this->redirect($response, '/admin/services');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id           = (int) $args['id'];
        $translations = ServiceModel::getTranslations($id);
        ServiceModel::delete($id);
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        \App\Services\Notifier::notify(
            'service', $id, $this->serviceLabel($translations, $id),
            'deleted', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'services.flash.deleted');
        return $this->redirect($response, '/admin/services');
    }

    private function serviceLabel(array $translations, int $id): string
    {
        $name = $translations['cs']['name'] ?? '';
        return $name !== '' ? $name : ('#' . $id);
    }
}
```

- [ ] **Step 4: Run the full test suite**

```bash
php vendor/bin/phpunit
```

Expected: all green (these controllers have no unit tests per project convention, so this just confirms no other test broke).

- [ ] **Step 5: Manual verification**

With the local server running and logged into `/admin` (see Task 1 Step 3 for starting it), create a category through the UI, then check the DB directly:

```bash
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e \
  "SELECT recipient_id, entity_type, action, entity_label, actor_label FROM notifications ORDER BY id DESC LIMIT 5;"
```

Expected: a row per other admin/editor user (none for the user who just created the category), with `entity_type='category'`, `action='created'`, and `entity_label` matching the name you typed.

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/Admin/CategoryController.php src/Controllers/Admin/ProductController.php src/Controllers/Admin/ServiceController.php
git commit -m "feat: notify admins/editors on category/product/service changes"
```

---

## Task 5: `NotificationController`, routes, translations, history page

**Files:**
- Create: `src/Controllers/Admin/NotificationController.php`
- Create: `templates/admin/notifications/index.twig`
- Modify: `src/routes.php`
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`

**Interfaces:**
- Consumes: `NotificationModel::forUser()`, `NotificationModel::unreadCount()`, `NotificationModel::recentAndMarkRead()` (Task 2); `$request->getAttribute('admin_i18n')` (existing `I18n` instance, has `t(string $key, array $params = []): string`).
- Produces (used by the JS in Task 7): `GET /admin/notifications/unread-count` → `{"count": int}`; `POST /admin/notifications/open` → `{"items": [{"id": int, "message": string, "url": string|null, "created_at": string}]}`.

- [ ] **Step 1: Add translation keys**

All 5 files keep an alphabetically sorted flat key list. Insert `"nav.notifications"` between the existing `"nav.logout"` and `"nav.orders"` lines, and insert the `notifications.*` block between the existing `"nav.users"` and `"orders.col.created"` lines.

`lang/admin/cs.json` — change:
```json
  "nav.logout": "Odhlásit se",
  "nav.orders": "Objednávky",
```
to:
```json
  "nav.logout": "Odhlásit se",
  "nav.notifications": "Oznámení",
  "nav.orders": "Objednávky",
```
and change:
```json
  "nav.users": "Uživatelé",
  "orders.col.created": "Vytvořena",
```
to:
```json
  "nav.users": "Uživatelé",
  "notifications.bell.aria": "Oznámení",
  "notifications.col.date": "Datum",
  "notifications.col.message": "Zpráva",
  "notifications.empty": "Žádná oznámení.",
  "notifications.msg.category_created": "{actor} vytvořil(a) kategorii „{label}“",
  "notifications.msg.category_deleted": "{actor} smazal(a) kategorii „{label}“",
  "notifications.msg.category_updated": "{actor} upravil(a) kategorii „{label}“",
  "notifications.msg.product_created": "{actor} vytvořil(a) produkt „{label}“",
  "notifications.msg.product_deleted": "{actor} smazal(a) produkt „{label}“",
  "notifications.msg.product_updated": "{actor} upravil(a) produkt „{label}“",
  "notifications.msg.service_created": "{actor} vytvořil(a) službu „{label}“",
  "notifications.msg.service_deleted": "{actor} smazal(a) službu „{label}“",
  "notifications.msg.service_updated": "{actor} upravil(a) službu „{label}“",
  "notifications.page.title": "Oznámení",
  "notifications.view_all": "Zobrazit vše",
  "orders.col.created": "Vytvořena",
```

`lang/admin/en.json` — change:
```json
  "nav.logout": "Log out",
  "nav.orders": "Orders",
```
to:
```json
  "nav.logout": "Log out",
  "nav.notifications": "Notifications",
  "nav.orders": "Orders",
```
and change:
```json
  "nav.users": "Users",
  "orders.col.created": "Created",
```
to:
```json
  "nav.users": "Users",
  "notifications.bell.aria": "Notifications",
  "notifications.col.date": "Date",
  "notifications.col.message": "Message",
  "notifications.empty": "No notifications.",
  "notifications.msg.category_created": "{actor} created category “{label}”",
  "notifications.msg.category_deleted": "{actor} deleted category “{label}”",
  "notifications.msg.category_updated": "{actor} updated category “{label}”",
  "notifications.msg.product_created": "{actor} created product “{label}”",
  "notifications.msg.product_deleted": "{actor} deleted product “{label}”",
  "notifications.msg.product_updated": "{actor} updated product “{label}”",
  "notifications.msg.service_created": "{actor} created service “{label}”",
  "notifications.msg.service_deleted": "{actor} deleted service “{label}”",
  "notifications.msg.service_updated": "{actor} updated service “{label}”",
  "notifications.page.title": "Notifications",
  "notifications.view_all": "View all",
  "orders.col.created": "Created",
```

`lang/admin/ru.json` — change:
```json
  "nav.logout": "Выйти",
  "nav.orders": "Заказы",
```
to:
```json
  "nav.logout": "Выйти",
  "nav.notifications": "Уведомления",
  "nav.orders": "Заказы",
```
and change:
```json
  "nav.users": "Пользователи",
  "orders.col.created": "Создан",
```
to:
```json
  "nav.users": "Пользователи",
  "notifications.bell.aria": "Уведомления",
  "notifications.col.date": "Дата",
  "notifications.col.message": "Сообщение",
  "notifications.empty": "Нет уведомлений.",
  "notifications.msg.category_created": "{actor} создал(а) категорию «{label}»",
  "notifications.msg.category_deleted": "{actor} удалил(а) категорию «{label}»",
  "notifications.msg.category_updated": "{actor} изменил(а) категорию «{label}»",
  "notifications.msg.product_created": "{actor} создал(а) товар «{label}»",
  "notifications.msg.product_deleted": "{actor} удалил(а) товар «{label}»",
  "notifications.msg.product_updated": "{actor} изменил(а) товар «{label}»",
  "notifications.msg.service_created": "{actor} создал(а) услугу «{label}»",
  "notifications.msg.service_deleted": "{actor} удалил(а) услугу «{label}»",
  "notifications.msg.service_updated": "{actor} изменил(а) услугу «{label}»",
  "notifications.page.title": "Уведомления",
  "notifications.view_all": "Показать все",
  "orders.col.created": "Создан",
```

`lang/admin/uk.json` — change:
```json
  "nav.logout": "Вийти",
  "nav.orders": "Замовлення",
```
to:
```json
  "nav.logout": "Вийти",
  "nav.notifications": "Сповіщення",
  "nav.orders": "Замовлення",
```
and change:
```json
  "nav.users": "Користувачі",
  "orders.col.created": "Створено",
```
to:
```json
  "nav.users": "Користувачі",
  "notifications.bell.aria": "Сповіщення",
  "notifications.col.date": "Дата",
  "notifications.col.message": "Повідомлення",
  "notifications.empty": "Немає сповіщень.",
  "notifications.msg.category_created": "{actor} створив(ла) категорію «{label}»",
  "notifications.msg.category_deleted": "{actor} видалив(ла) категорію «{label}»",
  "notifications.msg.category_updated": "{actor} оновив(ла) категорію «{label}»",
  "notifications.msg.product_created": "{actor} створив(ла) товар «{label}»",
  "notifications.msg.product_deleted": "{actor} видалив(ла) товар «{label}»",
  "notifications.msg.product_updated": "{actor} оновив(ла) товар «{label}»",
  "notifications.msg.service_created": "{actor} створив(ла) послугу «{label}»",
  "notifications.msg.service_deleted": "{actor} видалив(ла) послугу «{label}»",
  "notifications.msg.service_updated": "{actor} оновив(ла) послугу «{label}»",
  "notifications.page.title": "Сповіщення",
  "notifications.view_all": "Переглянути всі",
  "orders.col.created": "Створено",
```

`lang/admin/sk.json` — change:
```json
  "nav.logout": "Odhlásiť sa",
  "nav.orders": "Objednávky",
```
to:
```json
  "nav.logout": "Odhlásiť sa",
  "nav.notifications": "Oznámenia",
  "nav.orders": "Objednávky",
```
and change:
```json
  "nav.users": "Používatelia",
  "orders.col.created": "Vytvorená",
```
to:
```json
  "nav.users": "Používatelia",
  "notifications.bell.aria": "Oznámenia",
  "notifications.col.date": "Dátum",
  "notifications.col.message": "Správa",
  "notifications.empty": "Žiadne oznámenia.",
  "notifications.msg.category_created": "{actor} vytvoril(a) kategóriu „{label}“",
  "notifications.msg.category_deleted": "{actor} vymazal(a) kategóriu „{label}“",
  "notifications.msg.category_updated": "{actor} upravil(a) kategóriu „{label}“",
  "notifications.msg.product_created": "{actor} vytvoril(a) produkt „{label}“",
  "notifications.msg.product_deleted": "{actor} vymazal(a) produkt „{label}“",
  "notifications.msg.product_updated": "{actor} upravil(a) produkt „{label}“",
  "notifications.msg.service_created": "{actor} vytvoril(a) službu „{label}“",
  "notifications.msg.service_deleted": "{actor} vymazal(a) službu „{label}“",
  "notifications.msg.service_updated": "{actor} upravil(a) službu „{label}“",
  "notifications.page.title": "Oznámenia",
  "notifications.view_all": "Zobraziť všetky",
  "orders.col.created": "Vytvorená",
```

- [ ] **Step 2: Verify all 5 files still have identical, sorted key sets**

```bash
php -r '
foreach (["cs","en","ru","uk","sk"] as $f) {
    $d = json_decode(file_get_contents("lang/admin/$f.json"), true);
    $keys = array_keys($d);
    $sorted = $keys; sort($sorted);
    echo "$f: " . count($keys) . " keys, sorted=" . ($keys === $sorted ? "yes" : "NO") . "\n";
}
$sets = [];
foreach (["cs","en","ru","uk","sk"] as $f) {
    $sets[$f] = array_keys(json_decode(file_get_contents("lang/admin/$f.json"), true));
}
$diff = array_diff($sets["cs"], $sets["en"]);
echo "cs vs en diff: " . count($diff) . "\n";
'
```

Expected: `260 keys, sorted=yes` for all 5 (244 existing + 16 new), and `cs vs en diff: 0`.

- [ ] **Step 3: Add routes**

In `src/routes.php`, add the import near the other `Admin\...` imports:

```php
use App\Controllers\Admin\NotificationController;
```

Add a new section inside the `$app->group('/admin', ...)` block, after the `// Services` section and before `// Pages`:

```php
    // Notifications
    $group->get('/notifications',              NotificationController::class . ':index');
    $group->get('/notifications/unread-count', NotificationController::class . ':unreadCount');
    $group->post('/notifications/open',        NotificationController::class . ':open');
```

- [ ] **Step 4: Implement `NotificationController`**

Create `src/Controllers/Admin/NotificationController.php`:

```php
<?php
namespace App\Controllers\Admin;

use App\Models\NotificationModel;
use App\Services\I18n;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class NotificationController extends AdminBaseController
{
    private const ENTITY_URL_SEGMENT = [
        'category' => 'categories',
        'product'  => 'products',
        'service'  => 'services',
    ];

    public function index(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $page   = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $data   = NotificationModel::forUser($userId, $page, 20);
        $i18n   = $request->getAttribute('admin_i18n');

        $notifications = array_map(fn(array $row) => $this->formatNotification($row, $i18n), $data['items']);

        return $this->renderAdmin($request, $response, 'admin/notifications/index.twig', [
            'notifications' => $notifications,
            'page'          => $page,
            'pages'         => $data['pages'],
        ]);
    }

    public function unreadCount(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $response->getBody()->write(json_encode(['count' => NotificationModel::unreadCount($userId)]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function open(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $i18n   = $request->getAttribute('admin_i18n');
        $rows   = NotificationModel::recentAndMarkRead($userId, 20);

        $items = array_map(fn(array $row) => $this->formatNotification($row, $i18n), $rows);

        $response->getBody()->write(json_encode(['items' => $items]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function formatNotification(array $row, ?I18n $i18n): array
    {
        $key     = "notifications.msg.{$row['entity_type']}_{$row['action']}";
        $message = $i18n ? $i18n->t($key, ['actor' => $row['actor_label'], 'label' => $row['entity_label']]) : $key;

        $url = null;
        if ($row['action'] !== 'deleted' && isset(self::ENTITY_URL_SEGMENT[$row['entity_type']])) {
            $url = '/admin/' . self::ENTITY_URL_SEGMENT[$row['entity_type']] . '/' . $row['entity_id'] . '/edit';
        }

        return [
            'id'         => (int) $row['id'],
            'message'    => $message,
            'url'        => $url,
            'created_at' => $row['created_at'],
        ];
    }
}
```

- [ ] **Step 5: Create the history page template**

Create `templates/admin/notifications/index.twig`:

```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ t('notifications.page.title') }}{% endblock %}
{% block content %}
<div class="admin-topbar"><h1>{{ t('notifications.page.title') }}</h1></div>
<table class="admin-table">
    <thead>
        <tr>
            <th>{{ t('notifications.col.message') }}</th>
            <th>{{ t('notifications.col.date') }}</th>
        </tr>
    </thead>
    <tbody>
    {% for n in notifications %}
    <tr>
        <td>{% if n.url %}<a href="{{ n.url }}">{{ n.message }}</a>{% else %}{{ n.message }}{% endif %}</td>
        <td>{{ n.created_at }}</td>
    </tr>
    {% else %}
    <tr><td colspan="2">{{ t('notifications.empty') }}</td></tr>
    {% endfor %}
    </tbody>
</table>
{% if pages > 1 %}
<div class="pagination" style="margin-top:1rem;">
    {% for p in 1..pages %}
    <a href="?page={{ p }}" class="{% if p == page %}active{% endif %}">{{ p }}</a>
    {% endfor %}
</div>
{% endif %}
{% endblock %}
```

- [ ] **Step 6: Run the full test suite**

```bash
php vendor/bin/phpunit
```

Expected: all green.

- [ ] **Step 7: Manual verification**

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/admin/notifications
```

Expected: `302` (redirects to `/admin/login` — you're not authenticated in curl). Log in via the browser instead and visit `http://localhost:8080/admin/notifications` — expect a `200` page listing any notifications created in Task 4's manual check, newest first.

- [ ] **Step 8: Commit**

```bash
git add src/Controllers/Admin/NotificationController.php templates/admin/notifications/index.twig src/routes.php lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/uk.json lang/admin/sk.json
git commit -m "feat: add notifications history page and JSON endpoints"
```

---

## Task 6: Bell + badge UI in the admin sidebar

**Files:**
- Modify: `src/Controllers/Admin/AdminBaseController.php`
- Modify: `templates/layout/admin-base.twig`
- Modify: `www/assets/css/admin.css`

**Interfaces:**
- Consumes: `NotificationModel::unreadCount()` (Task 2).
- Produces: Twig variable `unread_notifications_count` (int) available on every admin page rendered via `renderAdmin()`; DOM elements `#notifBell`, `#notifBadge`, `#notifDropdown`, `#notifList` consumed by the JS in Task 7.

- [ ] **Step 1: Inject the unread count in `AdminBaseController::renderAdmin`**

In `src/Controllers/Admin/AdminBaseController.php`, change:

```php
    protected function renderAdmin(Request $request, Response $response, string $template, array $data = []): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $flash = $this->getFlash();
        $i18n  = $request->getAttribute('admin_i18n');
        $env   = $this->twig->getEnvironment();
        if ($i18n && !$env->hasExtension(\App\Twig\I18nExtension::class)) {
            $env->addExtension(new \App\Twig\I18nExtension($i18n));
        }
        return $this->twig->render($response, $template, array_merge([
            'flash'          => $flash,
            'session_role'   => $_SESSION['admin_user']['role']  ?? '',
            'session_email'  => $_SESSION['admin_user']['email'] ?? '',
            'admin_lang'     => $request->getAttribute('admin_lang', 'cs'),
        ], $data));
    }
```

to:

```php
    protected function renderAdmin(Request $request, Response $response, string $template, array $data = []): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $flash  = $this->getFlash();
        $i18n   = $request->getAttribute('admin_i18n');
        $env    = $this->twig->getEnvironment();
        if ($i18n && !$env->hasExtension(\App\Twig\I18nExtension::class)) {
            $env->addExtension(new \App\Twig\I18nExtension($i18n));
        }
        $userId       = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $unreadCount  = $userId ? \App\Models\NotificationModel::unreadCount($userId) : 0;
        return $this->twig->render($response, $template, array_merge([
            'flash'                      => $flash,
            'session_role'               => $_SESSION['admin_user']['role']  ?? '',
            'session_email'              => $_SESSION['admin_user']['email'] ?? '',
            'admin_lang'                 => $request->getAttribute('admin_lang', 'cs'),
            'unread_notifications_count' => $unreadCount,
        ], $data));
    }
```

- [ ] **Step 2: Add the bell markup and a sidebar nav link**

In `templates/layout/admin-base.twig`, change:

```twig
        <nav class="admin-nav">
            <a href="/admin">{{ t('nav.dashboard') }}</a>
```

to:

```twig
        <nav class="admin-nav">
            <div class="admin-notif-wrap">
                <button type="button" class="admin-notif-bell" id="notifBell" aria-haspopup="true" aria-expanded="false" aria-label="{{ t('notifications.bell.aria') }}">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <span>{{ t('nav.notifications') }}</span>
                    <span class="admin-notif-badge" id="notifBadge"{% if unread_notifications_count == 0 %} hidden{% endif %}>{{ unread_notifications_count > 99 ? '99+' : unread_notifications_count }}</span>
                </button>
                <div class="admin-notif-dropdown" id="notifDropdown" hidden>
                    <div class="admin-notif-list" id="notifList" data-empty-text="{{ t('notifications.empty') }}"></div>
                    <a href="/admin/notifications" class="admin-notif-viewall">{{ t('notifications.view_all') }}</a>
                </div>
            </div>
            <a href="/admin">{{ t('nav.dashboard') }}</a>
```

Also add a plain nav link to the history page next to Settings, changing:

```twig
            <a href="/admin/settings">{{ t('nav.settings') }}</a>
```

to:

```twig
            <a href="/admin/notifications">{{ t('nav.notifications') }}</a>
            <a href="/admin/settings">{{ t('nav.settings') }}</a>
```

- [ ] **Step 3: Add bell/dropdown styles**

Append to `www/assets/css/admin.css` (after the existing "Sidebar footer" block at the end of the file):

```css
/* Notifications bell */
.admin-notif-wrap { position: relative; padding: 0 1.25rem 0.75rem; }
.admin-notif-bell {
    display: flex; align-items: center; gap: 0.5rem; width: 100%;
    background: none; border: none; color: #c0c0d0; font-size: 0.9rem;
    cursor: pointer; padding: 0.6rem 0; text-align: left;
}
.admin-notif-bell:hover { color: #fff; }
.admin-notif-bell svg { flex-shrink: 0; }
.admin-notif-badge {
    margin-left: auto; background: #e91e8c; color: #fff;
    font-size: 0.7rem; font-weight: 700; border-radius: 10px;
    padding: 0.1rem 0.45rem; line-height: 1.3;
}
.admin-notif-badge[hidden] { display: none; }
.admin-notif-dropdown {
    position: absolute; top: 0; left: 100%; margin-left: 0.5rem; width: 320px;
    max-height: 420px; overflow-y: auto; background: #fff; color: #333;
    border-radius: 8px; box-shadow: 0 4px 24px rgba(0,0,0,.25); z-index: 50;
}
.admin-notif-dropdown[hidden] { display: none; }
.admin-notif-item {
    display: block; padding: 0.75rem 1rem; border-bottom: 1px solid #eee;
    font-size: 0.85rem; text-decoration: none; color: #333;
}
.admin-notif-item:hover { background: #fafafa; }
.admin-notif-item-time { display: block; font-size: 0.75rem; color: #999; margin-top: 0.2rem; }
.admin-notif-empty { padding: 1rem; font-size: 0.85rem; color: #999; text-align: center; }
.admin-notif-viewall {
    display: block; text-align: center; padding: 0.6rem; font-size: 0.8rem;
    color: #e91e8c; text-decoration: none; border-top: 1px solid #eee;
}
```

- [ ] **Step 4: Run the full test suite**

```bash
php vendor/bin/phpunit
```

Expected: all green (no PHP logic changed beyond the extra render-time query, which is exercised by every existing admin page load in browser testing, not by unit tests).

- [ ] **Step 5: Manual verification**

Log in at `http://localhost:8080/admin/login`, confirm the sidebar shows a bell with the current unread count badge (or no badge if zero), and that clicking `notifications` in the nav goes to `/admin/notifications`. The dropdown will not yet open/populate (JS not wired until Task 7) — that's expected at this point.

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/Admin/AdminBaseController.php templates/layout/admin-base.twig www/assets/css/admin.css
git commit -m "feat: add notification bell UI to admin sidebar"
```

---

## Task 7: Polling + dropdown JS

**Files:**
- Create: `www/assets/js/admin-notifications.js`
- Modify: `templates/layout/admin-base.twig`

**Interfaces:**
- Consumes: `GET /admin/notifications/unread-count`, `POST /admin/notifications/open` (Task 5); DOM ids `#notifBell`, `#notifBadge`, `#notifDropdown`, `#notifList` (Task 6).

- [ ] **Step 1: Write the JS file**

Create `www/assets/js/admin-notifications.js`:

```js
document.addEventListener('DOMContentLoaded', function () {
    var bell     = document.getElementById('notifBell');
    var badge    = document.getElementById('notifBadge');
    var dropdown = document.getElementById('notifDropdown');
    var list     = document.getElementById('notifList');
    if (!bell || !badge || !dropdown || !list) return;

    function setCount(n) {
        if (n > 0) {
            badge.textContent = n > 99 ? '99+' : String(n);
            badge.hidden = false;
        } else {
            badge.hidden = true;
        }
    }

    function pollCount() {
        fetch('/admin/notifications/unread-count', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) { setCount(data.count || 0); })
            .catch(function () {});
    }

    function renderItems(items) {
        list.innerHTML = '';
        if (items.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'admin-notif-empty';
            empty.textContent = list.dataset.emptyText || '';
            list.appendChild(empty);
            return;
        }
        items.forEach(function (item) {
            var el = document.createElement(item.url ? 'a' : 'div');
            el.className = 'admin-notif-item';
            if (item.url) el.href = item.url;

            var msg = document.createElement('span');
            msg.textContent = item.message;
            el.appendChild(msg);

            var time = document.createElement('span');
            time.className = 'admin-notif-item-time';
            time.textContent = item.created_at;
            el.appendChild(time);

            list.appendChild(el);
        });
    }

    function openDropdown() {
        dropdown.hidden = false;
        bell.setAttribute('aria-expanded', 'true');
        fetch('/admin/notifications/open', { method: 'POST', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                renderItems(data.items || []);
                setCount(0);
            })
            .catch(function () {});
    }

    function closeDropdown() {
        dropdown.hidden = true;
        bell.setAttribute('aria-expanded', 'false');
    }

    bell.addEventListener('click', function (e) {
        e.stopPropagation();
        if (dropdown.hidden) {
            openDropdown();
        } else {
            closeDropdown();
        }
    });

    document.addEventListener('click', function (e) {
        if (!dropdown.hidden && !dropdown.contains(e.target) && e.target !== bell) {
            closeDropdown();
        }
    });

    pollCount();
    setInterval(pollCount, 30000);
});
```

- [ ] **Step 2: Include the script in the admin layout**

In `templates/layout/admin-base.twig`, change:

```twig
    <main class="admin-main">
        {% if flash %}
            <div class="flash-{{ flash.type }}">{{ t(flash.message) }}</div>
        {% endif %}
        {% block content %}{% endblock %}
    </main>
    {% block scripts %}{% endblock %}
</body>
</html>
```

to:

```twig
    <main class="admin-main">
        {% if flash %}
            <div class="flash-{{ flash.type }}">{{ t(flash.message) }}</div>
        {% endif %}
        {% block content %}{% endblock %}
    </main>
    <script src="/assets/js/admin-notifications.js?v={{ asset_v('assets/js/admin-notifications.js') }}" defer></script>
    {% block scripts %}{% endblock %}
</body>
</html>
```

- [ ] **Step 3: Run the full test suite**

```bash
php vendor/bin/phpunit
```

Expected: all green (no PHP changed in this task).

- [ ] **Step 4: Manual verification in the browser**

1. Log in as one admin user in one browser (or a normal window) and as a second admin/editor user in another (or a private/incognito window).
2. As user A, create, then edit, then delete a product (or category/service).
3. As user B, without reloading, wait up to 30s and confirm the bell badge count increases on its own (polling).
4. As user B, click the bell — confirm the dropdown opens showing the three notifications (created/updated/deleted) with correct wording, the created/updated ones are links to the product's edit page, the deleted one is plain text, and the badge immediately goes to zero.
5. Reload the page as user B — badge stays at zero (already marked read).
6. Visit `/admin/notifications` as user B — confirm the same three entries appear in the full history table.

- [ ] **Step 5: Commit**

```bash
git add www/assets/js/admin-notifications.js templates/layout/admin-base.twig
git commit -m "feat: wire notification bell polling and dropdown"
```

---

## Task 8: Final full-suite check

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite one more time**

```bash
php vendor/bin/phpunit --testdox
```

Expected: all tests pass, including the new `NotificationModelTest` and `NotifierTest`.

- [ ] **Step 2: Confirm no stray debug output or leftover files**

```bash
git status
```

Expected: clean (everything from Tasks 1–7 already committed); no unexpected untracked files.
