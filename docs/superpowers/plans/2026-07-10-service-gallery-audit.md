# Service/Gallery Audit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Track who created/last edited each service and gallery album (public label "Naše realizace" / completed projects), and when, and surface it in the admin UI — closing the gap left by the prior categories/products audit feature.

**Architecture:** One migration adds `created_by`/`updated_by`/`updated_at` columns (FK to `users`, `ON DELETE SET NULL`) to `services` and `gallery_albums` (both already have `created_at`). `ServiceModel`/`GalleryModel` gain a `$userId` parameter on `create()`/`update()` (`createAlbum()`/`updateAlbum()` for gallery) and expose joined `created_by_email`/`updated_by_email` on admin reads. Controllers pass the session's admin user id through — `ServiceController` already computes it for its `Notifier` calls; `GalleryController` gains a new read. Templates render the audit trail in the index tables and edit forms, reusing the `.audit-meta` CSS class and `common.audit.unknown_user` translation key already added by the categories/products feature.

**Tech Stack:** PHP 8 / Slim 4, PDO/MySQL 8, Twig 3, PHPUnit 11 against real Docker MySQL.

## Global Constraints

- Prepared statements with bound parameters only; no SQL string interpolation of request data (`.claude/rules/database.md`).
- Migration file name: `database/migrations/V017__service_gallery_audit.sql`, idempotent-safe `ALTER TABLE ... ADD COLUMN` (never edit/delete already-applied migrations).
- `created_by`/`updated_by` are `INT NULL` FK → `users(id)` `ON DELETE SET NULL`.
- `updated_at` is DB-managed (`DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`) — app code never sets it.
- All 5 admin lang files (`lang/admin/{cs,en,ru,uk,sk}.json`) must gain the same new keys, kept alphabetically sorted (existing convention).
- Public-facing model methods (`ServiceModel::allWithTranslation`, `GalleryModel::albums`, `GalleryModel::album`) are untouched — audit data is admin-only.
- Reuse existing `common.audit.unknown_user` translation key and `.audit-meta` CSS class (`www/assets/css/admin.css:19`) — do not redefine them.
- Run `php vendor/bin/phpunit` (whole suite) before considering any task done; must be fully green.
- Local dev DB (`docker compose up -d`) must be running for model tests.

---

### Task 1: Migration — add audit columns

**Files:**
- Create: `database/migrations/V017__service_gallery_audit.sql`

**Interfaces:**
- Produces: columns `services.created_by`, `services.updated_by`, `services.updated_at` (already has `created_at`); `gallery_albums.created_by`, `gallery_albums.updated_by`, `gallery_albums.updated_at` (already has `created_at`). All later tasks read/write these columns directly via PDO.

- [ ] **Step 1: Write the migration file**

```sql
ALTER TABLE `services`
  ADD COLUMN `created_by` int NULL AFTER `created_at`,
  ADD COLUMN `updated_by` int NULL AFTER `created_by`,
  ADD COLUMN `updated_at` datetime NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `updated_by`;

ALTER TABLE `services`
  ADD CONSTRAINT `fk_services_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_services_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

ALTER TABLE `gallery_albums`
  ADD COLUMN `created_by` int NULL AFTER `created_at`,
  ADD COLUMN `updated_by` int NULL AFTER `created_by`,
  ADD COLUMN `updated_at` datetime NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `updated_by`;

ALTER TABLE `gallery_albums`
  ADD CONSTRAINT `fk_gallery_albums_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_gallery_albums_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
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
Expected: `{"applied": ["V017__service_gallery_audit"], "count": 1}`.

- [ ] **Step 4: Verify the schema**

```bash
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e "DESCRIBE services; DESCRIBE gallery_albums;"
```
Expected: `services` shows `created_by`, `updated_by`, `updated_at` alongside the existing `created_at`; `gallery_albums` shows the same three alongside its existing `created_at`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/V017__service_gallery_audit.sql
git commit -m "feat: add created_by/updated_by audit columns to services and gallery_albums"
```

---

### Task 2: `ServiceModel` — audit-aware create/update + reads

**Files:**
- Modify: `src/Models/ServiceModel.php`
- Test: `tests/Unit/Models/ServiceModelTest.php`

**Interfaces:**
- Consumes: `services.created_by`/`updated_by`/`updated_at` columns (Task 1); `users.email`.
- Produces: `ServiceModel::create(array $data, int $userId): int`, `ServiceModel::update(int $id, array $data, int $userId): void` — both now **require** `$userId` as an additional positional argument. `ServiceModel::all()` and `ServiceModel::findById()` rows gain `created_by_email`, `updated_by_email` keys (`created_at`/`updated_at` already present or added by Task 1). Consumed by Task 4 (`ServiceController`) and Task 7 (templates).

- [ ] **Step 1: Update existing test call sites and add a fixture user**

In `tests/Unit/Models/ServiceModelTest.php`, add a `setUpBeforeClass()` (the class currently has none) right after the class declaration:

```php
class ServiceModelTest extends TestCase
{
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('service-audit-test@example.com', 'x', 'editor')");
        self::$userId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='service-audit-test@example.com'"
        )->fetch()['id'];
    }

    public function test_create_find_update_delete(): void
    {
        $id = ServiceModel::create(['price_from' => 500, 'sort_order' => 99], self::$userId);
        $this->assertGreaterThan(0, $id);

        $service = ServiceModel::findById($id);
        $this->assertSame(500, (int) $service['price_from']);
        $this->assertSame(99, (int) $service['sort_order']);

        ServiceModel::update($id, ['price_from' => null, 'sort_order' => 98], self::$userId);
        $service = ServiceModel::findById($id);
        $this->assertNull($service['price_from']);
        $this->assertSame(98, (int) $service['sort_order']);

        ServiceModel::delete($id);
        $this->assertNull(ServiceModel::findById($id));
    }

    public function test_set_and_get_translations_upsert(): void
    {
        $id = ServiceModel::create(['price_from' => null, 'sort_order' => 99], self::$userId);
        ServiceModel::setTranslations($id, [
            'en' => ['name' => 'Test Service', 'description' => 'Desc', 'features' => "One\nTwo"],
        ]);
        ServiceModel::setTranslations($id, [
            'en' => ['name' => 'Test Service v2', 'description' => 'Desc v2', 'features' => "One\nTwo\nThree"],
        ]);

        $translations = ServiceModel::getTranslations($id);
        $this->assertSame('Test Service v2', $translations['en']['name']);
        $this->assertSame("One\nTwo\nThree", $translations['en']['features']);

        ServiceModel::delete($id);
    }

    public function test_all_with_translation_falls_back_to_cs(): void
    {
        $id = ServiceModel::create(['price_from' => 750, 'sort_order' => 99], self::$userId);
        ServiceModel::setTranslations($id, [
            'cs' => ['name' => 'Jen česky', 'description' => 'Popis', 'features' => 'A'],
        ]);

        $row = $this->findService(ServiceModel::allWithTranslation('en'), $id);
        $this->assertSame('Jen česky', $row['name']);

        ServiceModel::delete($id);
    }

    public function test_all_with_translation_orders_by_sort_order(): void
    {
        $first  = ServiceModel::create(['price_from' => null, 'sort_order' => 97], self::$userId);
        $second = ServiceModel::create(['price_from' => null, 'sort_order' => 96], self::$userId);
        ServiceModel::setTranslations($first, ['cs' => ['name' => 'First', 'description' => '', 'features' => '']]);
        ServiceModel::setTranslations($second, ['cs' => ['name' => 'Second', 'description' => '', 'features' => '']]);

        $ids = array_column(ServiceModel::allWithTranslation('cs'), 'id');
        $this->assertLessThan(array_search($first, $ids), array_search($second, $ids));

        ServiceModel::delete($first);
        ServiceModel::delete($second);
    }

    public function test_delete_cascades_translations(): void
    {
        $id = ServiceModel::create(['price_from' => null, 'sort_order' => 99], self::$userId);
        ServiceModel::setTranslations($id, ['cs' => ['name' => 'X', 'description' => '', 'features' => '']]);
        ServiceModel::delete($id);

        $stmt = Database::getConnection()->prepare('SELECT COUNT(*) FROM service_t WHERE service_id = ?');
        $stmt->execute([$id]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }
```

Then add new audit-specific tests (after `test_delete_cascades_translations`, before the private `findService()` helper):

```php
    public function test_create_records_creator_and_updater(): void
    {
        $id = ServiceModel::create(['price_from' => 500, 'sort_order' => 99], self::$userId);
        $service = ServiceModel::findById($id);
        $this->assertSame(self::$userId, (int) $service['created_by']);
        $this->assertSame(self::$userId, (int) $service['updated_by']);
        $this->assertSame('service-audit-test@example.com', $service['created_by_email']);
        $this->assertSame('service-audit-test@example.com', $service['updated_by_email']);
        $this->assertNotEmpty($service['created_at']);
        $this->assertNotEmpty($service['updated_at']);

        ServiceModel::delete($id);
    }

    public function test_update_changes_updated_by_but_not_created_by(): void
    {
        $id = ServiceModel::create(['price_from' => 500, 'sort_order' => 99], self::$userId);

        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('service-audit-editor2@example.com', 'x', 'editor')");
        $secondUserId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='service-audit-editor2@example.com'"
        )->fetch()['id'];

        ServiceModel::update($id, ['price_from' => 600, 'sort_order' => 98], $secondUserId);

        $service = ServiceModel::findById($id);
        $this->assertSame(self::$userId, (int) $service['created_by']);
        $this->assertSame($secondUserId, (int) $service['updated_by']);

        ServiceModel::delete($id);
    }

    public function test_all_includes_audit_columns(): void
    {
        $id   = ServiceModel::create(['price_from' => 500, 'sort_order' => 99], self::$userId);
        $rows = ServiceModel::all();
        $this->assertNotEmpty($rows);
        foreach (['created_by_email', 'created_at', 'updated_by_email', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $rows[0]);
        }
        ServiceModel::delete($id);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Models/ServiceModelTest.php --testdox`
Expected: FAIL — `ArgumentCountError: Too few arguments to function App\Models\ServiceModel::create()` (or similar) on the updated call sites, plus the new audit tests failing on missing keys.

- [ ] **Step 3: Implement `ServiceModel::all()` / `findById()` / `create()` / `update()`**

Replace the four methods in `src/Models/ServiceModel.php`:

```php
    public static function all(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query(
            'SELECT s.*, st.name AS name,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM services s
             LEFT JOIN service_t st ON st.service_id = s.id AND st.lang_code = \'cs\'
             LEFT JOIN users creator ON creator.id = s.created_by
             LEFT JOIN users updater ON updater.id = s.updated_by
             ORDER BY s.sort_order, s.id'
        )->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT s.*,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM services s
             LEFT JOIN users creator ON creator.id = s.created_by
             LEFT JOIN users updater ON updater.id = s.updated_by
             WHERE s.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data, int $userId): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO services (price_from, sort_order, created_by, updated_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $data['price_from'] !== null && $data['price_from'] !== '' ? (int) $data['price_from'] : null,
            (int) ($data['sort_order'] ?? 0),
            $userId,
            $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data, int $userId): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE services SET price_from = ?, sort_order = ?, updated_by = ? WHERE id = ?');
        $stmt->execute([
            $data['price_from'] !== null && $data['price_from'] !== '' ? (int) $data['price_from'] : null,
            (int) ($data['sort_order'] ?? 0),
            $userId,
            $id,
        ]);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Models/ServiceModelTest.php --testdox`
Expected: PASS (all tests).

- [ ] **Step 5: Commit**

```bash
git add src/Models/ServiceModel.php tests/Unit/Models/ServiceModelTest.php
git commit -m "feat: track created_by/updated_by on ServiceModel"
```

---

### Task 3: `GalleryModel` — audit-aware createAlbum/updateAlbum + reads

**Files:**
- Modify: `src/Models/GalleryModel.php`
- Test: `tests/Unit/Models/GalleryModelTest.php`

**Interfaces:**
- Consumes: `gallery_albums.created_by`/`updated_by`/`updated_at` columns (Task 1); `users.email`.
- Produces: `GalleryModel::createAlbum(array $data, int $userId): int`, `GalleryModel::updateAlbum(int $id, array $data, int $userId): void` — both now **require** `$userId` as an additional positional argument. `GalleryModel::allAlbums()` / `findAlbumById()` rows gain `created_by_email`, `updated_by_email` keys (`created_at`/`updated_at` already present or added by Task 1). Consumed by Task 5 (`GalleryController`) and Task 7 (templates).

- [ ] **Step 1: Add a fixture user and new audit tests**

No existing test in `tests/Unit/Models/GalleryModelTest.php` calls `GalleryModel::createAlbum()` or `updateAlbum()` directly (fixtures use raw PDO `INSERT`), so no call sites need updating. Add a fixture user to the existing `setUpBeforeClass()`:

```php
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO gallery_albums (slug) VALUES ('test-album')");
        $row = $pdo->query("SELECT id FROM gallery_albums WHERE slug='test-album'")->fetch();
        $id  = $row['id'];
        $pdo->exec("INSERT IGNORE INTO gallery_album_t (album_id, lang_code, name)
                    VALUES ({$id}, 'en', 'Test Album')");

        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('gallery-audit-test@example.com', 'x', 'editor')");
        self::$userId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='gallery-audit-test@example.com'"
        )->fetch()['id'];
    }
```

Add new tests (anywhere among the other `test_*` methods, e.g. right after `test_album_returns_null_for_unknown`):

```php
    public function test_create_album_records_creator_and_updater(): void
    {
        $id    = GalleryModel::createAlbum(['slug' => 'audit-album-' . uniqid(), 'sort_order' => 1], self::$userId);
        $album = GalleryModel::findAlbumById($id);
        $this->assertSame(self::$userId, (int) $album['created_by']);
        $this->assertSame(self::$userId, (int) $album['updated_by']);
        $this->assertSame('gallery-audit-test@example.com', $album['created_by_email']);
        $this->assertSame('gallery-audit-test@example.com', $album['updated_by_email']);
        $this->assertNotEmpty($album['created_at']);
        $this->assertNotEmpty($album['updated_at']);
    }

    public function test_update_album_changes_updated_by_but_not_created_by(): void
    {
        $id = GalleryModel::createAlbum(['slug' => 'audit-album-' . uniqid(), 'sort_order' => 1], self::$userId);

        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('gallery-audit-editor2@example.com', 'x', 'editor')");
        $secondUserId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='gallery-audit-editor2@example.com'"
        )->fetch()['id'];

        GalleryModel::updateAlbum($id, ['slug' => 'audit-album-updated-' . uniqid(), 'sort_order' => 2], $secondUserId);

        $album = GalleryModel::findAlbumById($id);
        $this->assertSame(self::$userId, (int) $album['created_by']);
        $this->assertSame($secondUserId, (int) $album['updated_by']);
    }

    public function test_all_albums_includes_audit_columns(): void
    {
        GalleryModel::createAlbum(['slug' => 'audit-album-' . uniqid(), 'sort_order' => 1], self::$userId);
        $rows = GalleryModel::allAlbums();
        $this->assertNotEmpty($rows);
        foreach (['created_by_email', 'created_at', 'updated_by_email', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $rows[0]);
        }
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Models/GalleryModelTest.php --testdox`
Expected: FAIL — `ArgumentCountError: Too few arguments to function App\Models\GalleryModel::createAlbum()` (or similar) on the new audit tests.

- [ ] **Step 3: Implement `GalleryModel::allAlbums()` / `findAlbumById()` / `createAlbum()` / `updateAlbum()`**

Replace the four methods in `src/Models/GalleryModel.php`:

```php
    public static function allAlbums(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query(
            'SELECT a.*,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM gallery_albums a
             LEFT JOIN users creator ON creator.id = a.created_by
             LEFT JOIN users updater ON updater.id = a.updated_by
             ORDER BY a.sort_order, a.id'
        )->fetchAll();
    }

    public static function findAlbumById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT a.*,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM gallery_albums a
             LEFT JOIN users creator ON creator.id = a.created_by
             LEFT JOIN users updater ON updater.id = a.updated_by
             WHERE a.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $album = $stmt->fetch();
        if (!$album) return null;
        $imgs = $pdo->prepare('SELECT * FROM gallery_images WHERE album_id = ? ORDER BY sort_order, id');
        $imgs->execute([$id]);
        $album['images'] = $imgs->fetchAll();
        return $album;
    }

    public static function createAlbum(array $data, int $userId): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO gallery_albums (slug, sort_order, created_by, updated_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$data['slug'], (int) ($data['sort_order'] ?? 0), $userId, $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function updateAlbum(int $id, array $data, int $userId): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE gallery_albums SET slug = ?, sort_order = ?, updated_by = ? WHERE id = ?');
        $stmt->execute([$data['slug'], (int) ($data['sort_order'] ?? 0), $userId, $id]);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Models/GalleryModelTest.php --testdox`
Expected: PASS (all tests).

- [ ] **Step 5: Commit**

```bash
git add src/Models/GalleryModel.php tests/Unit/Models/GalleryModelTest.php
git commit -m "feat: track created_by/updated_by on GalleryModel"
```

---

### Task 4: `ServiceController` — wire session user id through

**Files:**
- Modify: `src/Controllers/Admin/ServiceController.php:25-79`

**Interfaces:**
- Consumes: `ServiceModel::create(array $data, int $userId): int` and `ServiceModel::update(int $id, array $data, int $userId): void` (Task 2); `$_SESSION['admin_user']['id']` (already read further down in both methods for the existing `Notifier::notify()` calls — this task only moves that read earlier and reuses it).
- Produces: no new public interface — internal wiring only.

- [ ] **Step 1: Update `createSubmit()` and `editSubmit()`**

```php
    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $id     = ServiceModel::create([
            'price_from' => trim($body['price_from'] ?? ''),
            'sort_order' => (int) ($body['sort_order'] ?? 0),
        ], $userId);
        $translations = \App\Services\Translator::autoFill(
            $body['t'] ?? [],
            $request->getAttribute('admin_lang', 'cs'),
            self::LANGS,
            self::TRANSLATABLE_FIELDS
        );
        ServiceModel::setTranslations($id, $translations);
        \App\Services\Notifier::notify(
            'service', $id, $this->serviceLabel($translations, $id),
            'created', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'services.flash.created');
        return $this->redirect($response, '/admin/services');
    }
```

```php
    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id     = (int) $args['id'];
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        ServiceModel::update($id, [
            'price_from' => trim($body['price_from'] ?? ''),
            'sort_order' => (int) ($body['sort_order'] ?? 0),
        ], $userId);
        $translations = $body['t'] ?? [];
        ServiceModel::setTranslations($id, $translations);
        \App\Services\Notifier::notify(
            'service', $id, $this->serviceLabel($translations, $id),
            'updated', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'services.flash.updated');
        return $this->redirect($response, '/admin/services');
    }
```

- [ ] **Step 2: Commit**

```bash
git add src/Controllers/Admin/ServiceController.php
git commit -m "feat: record admin user on service create/update"
```

---

### Task 5: `GalleryController` — wire session user id through

**Files:**
- Modify: `src/Controllers/Admin/GalleryController.php:24-63`

**Interfaces:**
- Consumes: `GalleryModel::createAlbum(array $data, int $userId): int` and `GalleryModel::updateAlbum(int $id, array $data, int $userId): void` (Task 3); `$_SESSION['admin_user']['id']` (set by `AuthController` on login, session already started by `AuthMiddleware` for every route in this controller's group — this is a new read, `GalleryController` doesn't currently touch the session).
- Produces: no new public interface — internal wiring only.

- [ ] **Step 1: Update `createSubmit()` and `editSubmit()`**

```php
    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $id     = GalleryModel::createAlbum(
            ['slug' => trim($body['slug'] ?? ''), 'sort_order' => (int) ($body['sort_order'] ?? 0)],
            $userId
        );
        GalleryModel::setAlbumTranslations($id, $body['t'] ?? []);
        $this->handleImageUploads($request, $id);
        $this->handleVideoUploads($request, $id);
        $this->flash('success', 'gallery.flash.created');
        return $this->redirect($response, '/admin/gallery');
    }
```

```php
    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id     = (int) $args['id'];
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        GalleryModel::updateAlbum(
            $id,
            ['slug' => trim($body['slug'] ?? ''), 'sort_order' => (int) ($body['sort_order'] ?? 0)],
            $userId
        );
        GalleryModel::setAlbumTranslations($id, $body['t'] ?? []);
        $this->handleImageUploads($request, $id);
        $this->handleVideoUploads($request, $id);
        $this->flash('success', 'gallery.flash.updated');
        return $this->redirect($response, '/admin/gallery');
    }
```

- [ ] **Step 2: Manually verify via the running app**

With the local server up (Task 1, Step 2) and logged into `/admin/login`:

```bash
curl -s -c /tmp/admin-cookie.txt -X POST http://localhost:8080/admin/login \
  --data-urlencode "email=<your admin email>" --data-urlencode "password=<your admin password>" -o /dev/null -w "%{http_code}\n"
curl -s -b /tmp/admin-cookie.txt -X POST http://localhost:8080/admin/gallery/new \
  --data-urlencode "slug=audit-smoke-test-album" --data-urlencode "sort_order=1" -o /dev/null -w "%{http_code}\n"
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor \
  -e "SELECT slug, created_by, updated_by, created_at, updated_at FROM gallery_albums WHERE slug='audit-smoke-test-album';"
```
Expected: the row shows a non-NULL `created_by`/`updated_by` matching the logged-in admin's user id, and populated timestamps.
(There's no seeded admin credential in this plan — use whatever admin account already exists locally, or skip this manual check and rely on Task 8's full walkthrough if none is set up yet.)

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/Admin/GalleryController.php
git commit -m "feat: record admin user on gallery album create/update"
```

---

### Task 6: Admin translations — audit strings in all 5 lang files

**Files:**
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`

**Interfaces:**
- Produces: translation keys `services.col.updated`, `services.audit.created`, `services.audit.updated`, `gallery.col.updated`, `gallery.audit.created`, `gallery.audit.updated` — each takes `{user}`/`{date}` placeholders (via `App\Services\I18n::t($key, ['user' => ..., 'date' => ...])`, already supported). Reuses the existing `common.audit.unknown_user` key (no change needed to it). Consumed by Task 7 templates.

- [ ] **Step 1: Add keys to `lang/admin/cs.json`**

Insert immediately before `"services.col.actions"` (alphabetical: `audit` < `col`):
```json
  "services.audit.created": "Vytvořil(a) {user} dne {date}",
  "services.audit.updated": "Naposledy upravil(a) {user} dne {date}",
```

Insert immediately after `"services.col.price"` and before `"services.confirm_delete"` (still the `col.*` group, sorting after the other `col.*` entries — `updated` is alphabetically last among `actions, id, name, order, price, updated`):
```json
  "services.col.updated": "Naposledy upraveno",
```

Insert immediately before `"gallery.col.actions"` (alphabetical: `audit` < `col`):
```json
  "gallery.audit.created": "Vytvořil(a) {user} dne {date}",
  "gallery.audit.updated": "Naposledy upravil(a) {user} dne {date}",
```

Insert immediately after `"gallery.col.slug"` and before `"gallery.confirm_delete"` (still `col.*`, last alphabetically among `actions, id, order, slug, updated`):
```json
  "gallery.col.updated": "Naposledy upraveno",
```

- [ ] **Step 2: Add the same keys (translated) to the other 4 files, at the same alphabetical positions**

`lang/admin/en.json`:
```json
  "services.audit.created": "Created by {user} on {date}",
  "services.audit.updated": "Last updated by {user} on {date}",
  "services.col.updated": "Last updated",
  "gallery.audit.created": "Created by {user} on {date}",
  "gallery.audit.updated": "Last updated by {user} on {date}",
  "gallery.col.updated": "Last updated",
```

`lang/admin/ru.json`:
```json
  "services.audit.created": "Создал(а) {user} {date}",
  "services.audit.updated": "Последнее обновление: {user}, {date}",
  "services.col.updated": "Последнее обновление",
  "gallery.audit.created": "Создал(а) {user} {date}",
  "gallery.audit.updated": "Последнее обновление: {user}, {date}",
  "gallery.col.updated": "Последнее обновление",
```

`lang/admin/uk.json`:
```json
  "services.audit.created": "Створив(ла) {user} {date}",
  "services.audit.updated": "Останнє оновлення: {user}, {date}",
  "services.col.updated": "Останнє оновлення",
  "gallery.audit.created": "Створив(ла) {user} {date}",
  "gallery.audit.updated": "Останнє оновлення: {user}, {date}",
  "gallery.col.updated": "Останнє оновлення",
```

`lang/admin/sk.json`:
```json
  "services.audit.created": "Vytvoril(a) {user} dňa {date}",
  "services.audit.updated": "Naposledy upravil(a) {user} dňa {date}",
  "services.col.updated": "Naposledy upravené",
  "gallery.audit.created": "Vytvoril(a) {user} dňa {date}",
  "gallery.audit.updated": "Naposledy upravil(a) {user} dňa {date}",
  "gallery.col.updated": "Naposledy upravené",
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
Expected: `OK, all files have 275 identical keys` (269 existing + 6 new).

- [ ] **Step 4: Commit**

```bash
git add lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/uk.json lang/admin/sk.json
git commit -m "feat: add service/gallery audit translation keys to admin languages"
```

---

### Task 7: Admin templates — show audit info

**Files:**
- Modify: `templates/admin/services/index.twig`
- Modify: `templates/admin/services/form.twig`
- Modify: `templates/admin/gallery/index.twig`
- Modify: `templates/admin/gallery/form.twig`

**Interfaces:**
- Consumes: `service.created_by_email`/`service.created_at`/`service.updated_by_email`/`service.updated_at` and `a.created_by_email`/`a.created_at`/`a.updated_by_email`/`a.updated_at` (Tasks 2–3); translation keys from Task 6; existing `.audit-meta` CSS class (`www/assets/css/admin.css:19`) and `common.audit.unknown_user` key — both reused as-is, no new CSS or key needed.

- [ ] **Step 1: Add an "Updated" column to `templates/admin/services/index.twig`**

```twig
        <tr>
            <th>{{ t('services.col.id') }}</th>
            <th>{{ t('services.col.name') }}</th>
            <th>{{ t('services.col.price') }}</th>
            <th>{{ t('services.col.order') }}</th>
            <th>{{ t('services.col.updated') }}</th>
            <th>{{ t('services.col.actions') }}</th>
        </tr>
```

```twig
    <tr>
        <td>{{ service.id }}</td>
        <td>{{ service.name ?? '—' }}</td>
        <td>{{ service.price_from ? service.price_from ~ ' Kč' : '—' }}</td>
        <td>{{ service.sort_order }}</td>
        <td class="audit-meta">{{ service.updated_by_email ?? t('common.audit.unknown_user') }}<br>{{ service.updated_at }}</td>
        <td>
```

Update the `{% else %}` row's `colspan` from `5` to `6`.

- [ ] **Step 2: Add an audit line to `templates/admin/services/form.twig`**

Insert right after the `admin-topbar` `</div>` and before the `<form ...>` tag:

```twig
{% if service %}
<p class="audit-meta">
    {{ t('services.audit.created', {user: service.created_by_email ?? t('common.audit.unknown_user'), date: service.created_at}) }}
    ·
    {{ t('services.audit.updated', {user: service.updated_by_email ?? t('common.audit.unknown_user'), date: service.updated_at}) }}
</p>
{% endif %}
```

- [ ] **Step 3: Add an "Updated" column to `templates/admin/gallery/index.twig`**

```twig
    <thead><tr><th>{{ t('gallery.col.id') }}</th><th>{{ t('gallery.col.slug') }}</th><th>{{ t('gallery.col.order') }}</th><th>{{ t('gallery.col.updated') }}</th><th>{{ t('gallery.col.actions') }}</th></tr></thead>
    <tbody>
    {% for a in albums %}
    <tr>
        <td>{{ a.id }}</td>
        <td>{{ a.slug }}</td>
        <td>{{ a.sort_order }}</td>
        <td class="audit-meta">{{ a.updated_by_email ?? t('common.audit.unknown_user') }}<br>{{ a.updated_at }}</td>
        <td>
```

Update the `{% else %}` row's `colspan` from `4` to `5`.

- [ ] **Step 4: Add an audit line to `templates/admin/gallery/form.twig`**

Insert right after the `admin-topbar` `</div>` and before the `<form ...>` tag:

```twig
{% if album %}
<p class="audit-meta">
    {{ t('gallery.audit.created', {user: album.created_by_email ?? t('common.audit.unknown_user'), date: album.created_at}) }}
    ·
    {{ t('gallery.audit.updated', {user: album.updated_by_email ?? t('common.audit.unknown_user'), date: album.updated_at}) }}
</p>
{% endif %}
```

- [ ] **Step 5: Manually verify in the browser**

With the local server running (Task 1, Step 2), log into `/admin/login`, then visit:
- `http://localhost:8080/admin/services` — confirm the new "Last updated"/"Naposledy upraveno" column renders with an email + timestamp (or "unknown user" for pre-existing rows).
- `http://localhost:8080/admin/services/{id}/edit` for an existing service — confirm the audit line renders above the form.
- `http://localhost:8080/admin/gallery` and `http://localhost:8080/admin/gallery/{id}/edit` — same checks.
- Create a new service and a new gallery album through the admin UI, then reload their edit pages — confirm both "Created by" and "Last updated by" show your logged-in email.

- [ ] **Step 6: Commit**

```bash
git add templates/admin/services/index.twig templates/admin/services/form.twig \
        templates/admin/gallery/index.twig templates/admin/gallery/form.twig
git commit -m "feat: show service/gallery audit info in admin UI"
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
curl -s -o /dev/null -w "Services:     %{http_code}\n" http://localhost:8080/admin/services
curl -s -o /dev/null -w "Gallery:      %{http_code}\n" http://localhost:8080/admin/gallery
```
Expected: all four return `200` (admin pages will redirect to login with a `302` if not authenticated in this shell session — that's fine, it means routing didn't break; the manual browser check in Task 7 Step 5 already confirmed the authenticated view).

- [ ] **Step 3: Final commit if any stragglers remain**

```bash
git status
```
Expected: clean working tree (everything already committed task-by-task). If anything is outstanding, commit it with a clear message.
