# Homepage Hero Image Carousel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the homepage's plain-text hero with an admin-manageable image carousel (image + title/subtitle/CTA per slide, prev/next arrows, dot pagination, autoplay), matching the reference screenshot.

**Architecture:** New `hero_slides` + `hero_slide_t` tables (base + translation, mirroring `categories`/`category_t`). A public `HeroSlideModel::active($lang)` feeds `HomeController` → `home.twig`, which renders a vanilla-JS carousel (no build step, same convention as `nav.js`). A new `HeroSlideController` mirrors `CategoryController` (translations) + `ProductController` (image upload) for full admin CRUD at `/admin/hero-slides`.

**Tech Stack:** PHP 8 / Slim 4, Twig 3, PDO/MySQL 8, PHPUnit 11, vanilla CSS/JS (no build step).

## Global Constraints

- Prepared statements with bound parameters everywhere; no string interpolation of request data into SQL (`.claude/rules/database.md`).
- Translation tables use `lang_code`, never `lang`; upsert via `ON DUPLICATE KEY UPDATE` (`.claude/rules/database.md`).
- Every new admin-facing string goes in **all 5** `lang/admin/{cs,sk,en,uk,ru}.json` files with identical key sets; every new public-facing string goes in **all 5** `lang/{cs,en,ru,uk,sk}.json` files (`.claude/rules/frontend.md`).
- CSS: flat kebab-case, `--modifier` suffixes, tokens from `:root`, no `!important`/IDs, responsive blocks live next to the component they modify (`.claude/rules/css-styling.md`).
- Interactive elements are real `<a>`/`<button>`, never `<div>`/`<span>` (`.claude/rules/frontend.md`).
- Model tests run against real Docker MySQL, no mocks; unique fixtures via `uniqid()` (`.claude/rules/unit-testing.md`). Controllers/Twig/JS are verified manually, not unit-tested.
- Admin routes go inside the existing `$app->group('/admin', ...)` block in `src/routes.php`, which is already registered before any `/{lang}/*` route (`.claude/rules/backend.md`) — do not reorder existing route registrations.
- Run `php vendor/bin/phpunit` (whole suite) before every commit — it must be fully green.
- `www/assets/uploads/` is gitignored — never assume a real file exists there; a missing/NULL image must degrade to a placeholder, not a broken `<img>`.
- The `notifications.entity_type` column is a fixed `ENUM('category','product','service')` (see `database/migrations/V016__notifications.sql`) — `hero_slide` is **not** in it. Do not call `Notifier::notify()` for hero slides (same as the existing `GalleryController`/`PageController`, which also don't call it) — extending that enum is out of scope for this feature.

---

### Task 1: Migration — `hero_slides` schema + seed

**Files:**
- Create: `database/migrations/V024__hero_slides.sql`

**Interfaces:**
- Produces: tables `hero_slides` (`id, image, cta_url, is_active, sort_order, created_at, created_by, updated_by, updated_at`) and `hero_slide_t` (`id, slide_id, lang_code, title, subtitle, cta_label`), used by every later task.

- [ ] **Step 1: Write the migration file**

```sql
CREATE TABLE `hero_slides` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `image`      varchar(255) DEFAULT NULL,
  `cta_url`    varchar(255) NOT NULL DEFAULT '/shop',
  `is_active`  tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_hero_slides_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hero_slides_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `hero_slide_t` (
  `id`        int NOT NULL AUTO_INCREMENT,
  `slide_id`  int NOT NULL,
  `lang_code` varchar(5) NOT NULL,
  `title`     varchar(255) NOT NULL,
  `subtitle`  varchar(500) DEFAULT NULL,
  `cta_label` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slide_lang` (`slide_id`,`lang_code`),
  CONSTRAINT `fk_hero_slide_t_slide` FOREIGN KEY (`slide_id`) REFERENCES `hero_slides`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: one default slide reusing the current plain-text hero copy, so the
-- homepage carousel is never empty out of the box. image stays NULL —
-- www/assets/uploads/ is gitignored, so no real file ships with this
-- migration; the public template renders a placeholder for a NULL image.
INSERT IGNORE INTO hero_slides (id, image, cta_url, is_active, sort_order) VALUES (1, NULL, '/shop', 1, 10);
INSERT IGNORE INTO hero_slide_t (slide_id, lang_code, title, subtitle, cta_label) VALUES (1, 'cs', 'Krásné balónky pro každou příležitost', 'Hélium, balónky a dekorace na míru', 'Prohlédnout nabídku');
INSERT IGNORE INTO hero_slide_t (slide_id, lang_code, title, subtitle, cta_label) VALUES (1, 'en', 'Beautiful balloons for every occasion', 'Helium, balloons and custom decorations', 'Browse our range');
INSERT IGNORE INTO hero_slide_t (slide_id, lang_code, title, subtitle, cta_label) VALUES (1, 'ru', 'Красивые шары для любого праздника', 'Гелий, шары и украшения на заказ', 'Смотреть каталог');
INSERT IGNORE INTO hero_slide_t (slide_id, lang_code, title, subtitle, cta_label) VALUES (1, 'uk', 'Красиві кульки для кожного свята', 'Гелій, кульки та прикраси на замовлення', 'Переглянути каталог');
INSERT IGNORE INTO hero_slide_t (slide_id, lang_code, title, subtitle, cta_label) VALUES (1, 'sk', 'Krásne balóny pre každú príležitosť', 'Hélium, balóny a dekorácie na mieru', 'Prezrieť ponuku');
```

- [ ] **Step 2: Apply it to the local dev DB**

Run (requires `docker compose up -d` and the PHP server already running — see `.claude/skills/start`):

```bash
MIGRATE_TOKEN=$(php -r "echo (require 'config/settings.php')['migrate_token'];")
curl -s "http://localhost:8080/migrate.php?token=${MIGRATE_TOKEN}" | python3 -m json.tool
```

Expected: `{"applied": ["V024__hero_slides"], "count": 1}` (or it's included alongside any other pending migrations).

- [ ] **Step 3: Verify the seed row**

```bash
docker exec balonkydecor_db mysql -u balonky -pbalonky balonkydecor -e \
  "SELECT s.id, s.cta_url, s.is_active, t.lang_code, t.title FROM hero_slides s JOIN hero_slide_t t ON t.slide_id = s.id WHERE s.id = 1;"
```

Expected: 5 rows (one per language) with `cta_url = /shop`, `is_active = 1`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/V024__hero_slides.sql
git commit -m "$(cat <<'EOF'
feat: add hero_slides schema and seed migration

EOF
)"
```

---

### Task 2: `HeroSlideModel` (TDD)

**Files:**
- Create: `tests/Unit/Models/HeroSlideModelTest.php`
- Create: `src/Models/HeroSlideModel.php`

**Interfaces:**
- Consumes: `App\Models\Database::getConnection()` (PDO singleton, `FETCH_ASSOC`).
- Produces (used by Tasks 3 & 4):
  - `HeroSlideModel::active(string $lang): array` — public, active slides only.
  - `HeroSlideModel::all(string $lang): array`, `::findById(int $id): ?array`,
    `::create(array $data, int $userId): int`, `::update(int $id, array $data, int $userId): void`,
    `::delete(int $id): void`, `::getTranslations(int $id): array`,
    `::setTranslations(int $id, array $translations): void` — admin CRUD.
  - `$data` keys for `create`/`update`: `image` (?string), `cta_url` (string), `is_active` (int 0/1), `sort_order` (int).
  - `$translations` shape: `['en' => ['title' => ..., 'subtitle' => ..., 'cta_label' => ...], ...]`.

- [ ] **Step 1: Write the failing test file**

```php
<?php
namespace Tests\Unit\Models;

use App\Models\Database;
use App\Models\HeroSlideModel;
use PHPUnit\Framework\TestCase;

class HeroSlideModelTest extends TestCase
{
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('hero-slide-test@example.com', 'x', 'editor')");
        self::$userId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='hero-slide-test@example.com'"
        )->fetch()['id'];
    }

    private function makeSlide(array $overrides = []): int
    {
        return HeroSlideModel::create(array_merge([
            'image'      => null,
            'cta_url'    => '/shop',
            'is_active'  => 1,
            'sort_order' => 0,
        ], $overrides), self::$userId);
    }

    public function test_create_records_creator_and_updater(): void
    {
        $id    = $this->makeSlide();
        $slide = HeroSlideModel::findById($id);
        $this->assertSame(self::$userId, (int) $slide['created_by']);
        $this->assertSame(self::$userId, (int) $slide['updated_by']);
        $this->assertSame('hero-slide-test@example.com', $slide['created_by_email']);
        $this->assertSame('/shop', $slide['cta_url']);
    }

    public function test_update_changes_updated_by_but_not_created_by(): void
    {
        $id = $this->makeSlide();

        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('hero-slide-editor2@example.com', 'x', 'editor')");
        $secondUserId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='hero-slide-editor2@example.com'"
        )->fetch()['id'];

        HeroSlideModel::update($id, [
            'image' => null, 'cta_url' => '/services', 'is_active' => 1, 'sort_order' => 5,
        ], $secondUserId);

        $slide = HeroSlideModel::findById($id);
        $this->assertSame(self::$userId, (int) $slide['created_by']);
        $this->assertSame($secondUserId, (int) $slide['updated_by']);
        $this->assertSame('/services', $slide['cta_url']);
        $this->assertSame(5, (int) $slide['sort_order']);
    }

    public function test_set_and_get_translations_upserts(): void
    {
        $id = $this->makeSlide();
        HeroSlideModel::setTranslations($id, [
            'en' => ['title' => 'Hello', 'subtitle' => 'World', 'cta_label' => 'Go'],
        ]);
        HeroSlideModel::setTranslations($id, [
            'en' => ['title' => 'Updated', 'subtitle' => 'World', 'cta_label' => 'Go'],
        ]);
        $translations = HeroSlideModel::getTranslations($id);
        $this->assertSame('Updated', $translations['en']['title']);
    }

    public function test_active_returns_only_active_slides_ordered_by_sort_order(): void
    {
        $activeId   = $this->makeSlide(['is_active' => 1, 'sort_order' => 1]);
        $inactiveId = $this->makeSlide(['is_active' => 0, 'sort_order' => 2]);
        HeroSlideModel::setTranslations($activeId,   ['en' => ['title' => 'Active',   'subtitle' => '', 'cta_label' => 'Go']]);
        HeroSlideModel::setTranslations($inactiveId, ['en' => ['title' => 'Inactive', 'subtitle' => '', 'cta_label' => 'Go']]);

        $ids = array_column(HeroSlideModel::active('en'), 'id');
        $this->assertContains($activeId, $ids);
        $this->assertNotContains($inactiveId, $ids);
    }

    public function test_active_excludes_slide_without_translation_for_requested_lang(): void
    {
        $id = $this->makeSlide(['is_active' => 1]);
        HeroSlideModel::setTranslations($id, ['en' => ['title' => 'English only', 'subtitle' => '', 'cta_label' => 'Go']]);

        $ids = array_column(HeroSlideModel::active('sk'), 'id');
        $this->assertNotContains($id, $ids);
    }

    public function test_delete_cascades_translations(): void
    {
        $id = $this->makeSlide();
        HeroSlideModel::setTranslations($id, ['en' => ['title' => 'Bye', 'subtitle' => '', 'cta_label' => 'Go']]);
        HeroSlideModel::delete($id);

        $this->assertNull(HeroSlideModel::findById($id));
        $this->assertEmpty(HeroSlideModel::getTranslations($id));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php vendor/bin/phpunit tests/Unit/Models/HeroSlideModelTest.php --testdox`
Expected: FAIL / ERROR — `Class "App\Models\HeroSlideModel" not found`.

- [ ] **Step 3: Implement `HeroSlideModel`**

```php
<?php
namespace App\Models;

class HeroSlideModel
{
    public static function active(string $lang): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT s.id, s.image, s.cta_url, t.title, t.subtitle, t.cta_label
             FROM hero_slides s
             JOIN hero_slide_t t ON t.slide_id = s.id AND t.lang_code = ?
             WHERE s.is_active = 1
             ORDER BY s.sort_order, s.id'
        );
        $stmt->execute([$lang]);
        return $stmt->fetchAll();
    }

    public static function all(string $lang): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT s.*, t.title,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM hero_slides s
             LEFT JOIN hero_slide_t t ON t.slide_id = s.id AND t.lang_code = :lang
             LEFT JOIN users creator ON creator.id = s.created_by
             LEFT JOIN users updater ON updater.id = s.updated_by
             ORDER BY s.sort_order, s.id'
        );
        $stmt->execute(['lang' => $lang]);
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT s.*,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM hero_slides s
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
        $stmt = $pdo->prepare(
            'INSERT INTO hero_slides (image, cta_url, is_active, sort_order, created_by, updated_by)
             VALUES (:image, :cta_url, :is_active, :sort_order, :created_by, :updated_by)'
        );
        $stmt->execute([
            'image'      => $data['image'] ?? null,
            'cta_url'    => trim((string) ($data['cta_url'] ?? '')) ?: '/shop',
            'is_active'  => (int) ($data['is_active'] ?? 1),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data, int $userId): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE hero_slides SET image = :image, cta_url = :cta_url, is_active = :is_active,
                                     sort_order = :sort_order, updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'image'      => $data['image'] ?? null,
            'cta_url'    => trim((string) ($data['cta_url'] ?? '')) ?: '/shop',
            'is_active'  => (int) ($data['is_active'] ?? 1),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'updated_by' => $userId,
            'id'         => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM hero_slides WHERE id = ?')->execute([$id]);
    }

    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, title, subtitle, cta_label FROM hero_slide_t WHERE slide_id = ?');
        $stmt->execute([$id]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['lang_code']] = $row;
        }
        return $result;
    }

    public static function setTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO hero_slide_t (slide_id, lang_code, title, subtitle, cta_label)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), subtitle = VALUES(subtitle), cta_label = VALUES(cta_label)'
        );
        foreach ($translations as $lang => $fields) {
            $stmt->execute([
                $id,
                $lang,
                trim((string) ($fields['title'] ?? '')),
                trim((string) ($fields['subtitle'] ?? '')) ?: null,
                trim((string) ($fields['cta_label'] ?? '')),
            ]);
        }
    }
}
```

- [ ] **Step 4: Run the test file again to verify it passes**

Run: `php vendor/bin/phpunit tests/Unit/Models/HeroSlideModelTest.php --testdox`
Expected: all 6 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Models/HeroSlideModel.php tests/Unit/Models/HeroSlideModelTest.php
git commit -m "$(cat <<'EOF'
feat: add HeroSlideModel with CRUD and translation support

EOF
)"
```

---

### Task 3: Admin CRUD (`HeroSlideController` + routes + templates + nav)

**Files:**
- Create: `src/Controllers/Admin/HeroSlideController.php`
- Create: `templates/admin/hero-slides/index.twig`
- Create: `templates/admin/hero-slides/form.twig`
- Modify: `src/routes.php` (add `use` import + routes inside the `/admin` group)
- Modify: `templates/layout/admin-base.twig` (add nav link)
- Modify: `lang/admin/cs.json`, `lang/admin/sk.json`, `lang/admin/en.json`, `lang/admin/uk.json`, `lang/admin/ru.json`

**Interfaces:**
- Consumes: `HeroSlideModel` (Task 2), `App\Services\ImageUploader::upload(array $file, string $dir): string`,
  `App\Services\Translator::autoFill(array $input, string $sourceLang, array $langs, array $fields): array`,
  `AdminBaseController::renderAdmin()/flash()/redirect()`.
- Produces: `GET /admin/hero-slides`, `GET|POST /admin/hero-slides/new`,
  `GET|POST /admin/hero-slides/{id}/edit`, `POST /admin/hero-slides/{id}/delete`.

- [ ] **Step 1: Create `HeroSlideController`**

```php
<?php
namespace App\Controllers\Admin;

use App\Models\HeroSlideModel;
use App\Services\ImageUploader;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HeroSlideController extends AdminBaseController
{
    private const LANGS               = ['cs', 'sk', 'en', 'uk', 'ru'];
    private const TRANSLATABLE_FIELDS = ['title', 'subtitle', 'cta_label'];
    private const UPLOAD_DIR          = __DIR__ . '/../../../www/assets/uploads/hero';

    public function index(Request $request, Response $response, array $args): Response
    {
        $slides = HeroSlideModel::all($request->getAttribute('admin_lang', 'cs'));
        return $this->renderAdmin($request, $response, 'admin/hero-slides/index.twig', compact('slides'));
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        return $this->renderAdmin($request, $response, 'admin/hero-slides/form.twig', [
            'slide'        => null,
            'translations' => [],
            'langs'        => self::LANGS,
        ]);
    }

    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);

        $id = HeroSlideModel::create([
            'image'      => $this->uploadedFilename($request),
            'cta_url'    => $body['cta_url'] ?? '/shop',
            'is_active'  => isset($body['is_active']) ? 1 : 0,
            'sort_order' => (int) ($body['sort_order'] ?? 0),
        ], $userId);

        $translations = \App\Services\Translator::autoFill(
            $body['t'] ?? [],
            $request->getAttribute('admin_lang', 'cs'),
            self::LANGS,
            self::TRANSLATABLE_FIELDS
        );
        HeroSlideModel::setTranslations($id, $translations);

        $this->flash('success', 'hero_slides.flash.created');
        return $this->redirect($response, '/admin/hero-slides');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $slide = HeroSlideModel::findById((int) $args['id']);
        if (!$slide) return $response->withStatus(404);
        $translations = HeroSlideModel::getTranslations((int) $args['id']);
        return $this->renderAdmin($request, $response, 'admin/hero-slides/form.twig', [
            'slide'        => $slide,
            'translations' => $translations,
            'langs'        => self::LANGS,
        ]);
    }

    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id       = (int) $args['id'];
        $existing = HeroSlideModel::findById($id);
        if (!$existing) return $response->withStatus(404);

        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);

        $newFilename = $this->uploadedFilename($request);
        $image       = $existing['image'];
        if ($newFilename !== null) {
            if ($existing['image']) {
                @unlink(self::UPLOAD_DIR . '/' . $existing['image']);
                @unlink(self::UPLOAD_DIR . '/thumb_' . $existing['image']);
            }
            $image = $newFilename;
        }

        HeroSlideModel::update($id, [
            'image'      => $image,
            'cta_url'    => $body['cta_url'] ?? '/shop',
            'is_active'  => isset($body['is_active']) ? 1 : 0,
            'sort_order' => (int) ($body['sort_order'] ?? 0),
        ], $userId);
        HeroSlideModel::setTranslations($id, $body['t'] ?? []);

        $this->flash('success', 'hero_slides.flash.updated');
        return $this->redirect($response, '/admin/hero-slides');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id    = (int) $args['id'];
        $slide = HeroSlideModel::findById($id);
        if ($slide) {
            if ($slide['image']) {
                @unlink(self::UPLOAD_DIR . '/' . $slide['image']);
                @unlink(self::UPLOAD_DIR . '/thumb_' . $slide['image']);
            }
            HeroSlideModel::delete($id);
        }
        $this->flash('success', 'hero_slides.flash.deleted');
        return $this->redirect($response, '/admin/hero-slides');
    }

    private function uploadedFilename(Request $request): ?string
    {
        $files = $request->getUploadedFiles();
        $file  = $files['image'] ?? null;
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) return null;

        $tmp = ['tmp_name' => $file->getStream()->getMetadata('uri'), 'error' => $file->getError()];
        return ImageUploader::upload($tmp, self::UPLOAD_DIR);
    }
}
```

- [ ] **Step 2: Register routes**

In `src/routes.php`, add the import alongside the other `Admin\...` uses:

```php
use App\Controllers\Admin\GalleryController as AdminGalleryController;
use App\Controllers\Admin\HeroSlideController;
use App\Controllers\Admin\NotificationController;
```

Then inside the `$app->group('/admin', ...)` block, immediately after the "Categories" block and before "Products":

```php
    // Hero slides
    $group->get('/hero-slides',                     HeroSlideController::class . ':index');
    $group->get('/hero-slides/new',                 HeroSlideController::class . ':createForm');
    $group->post('/hero-slides/new',                HeroSlideController::class . ':createSubmit');
    $group->get('/hero-slides/{id:[0-9]+}/edit',    HeroSlideController::class . ':editForm');
    $group->post('/hero-slides/{id:[0-9]+}/edit',   HeroSlideController::class . ':editSubmit');
    $group->post('/hero-slides/{id:[0-9]+}/delete', HeroSlideController::class . ':delete');
```

- [ ] **Step 3: Create `templates/admin/hero-slides/index.twig`**

```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ t('hero_slides.title') }}{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>{{ t('hero_slides.title') }}</h1>
    <a href="/admin/hero-slides/new" class="btn btn-primary">{{ t('hero_slides.add') }}</a>
</div>
<table class="admin-table" data-sortable>
    <thead>
        <tr>
            <th><button type="button" class="sort-btn" data-sort-type="number">{{ t('hero_slides.col.id') }}</button></th>
            <th>{{ t('hero_slides.col.image') }}</th>
            <th><button type="button" class="sort-btn" data-sort-type="text">{{ t('hero_slides.col.title') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="number">{{ t('hero_slides.col.order') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="number">{{ t('hero_slides.col.active') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="text">{{ t('hero_slides.col.updated_by') }}</button></th>
            <th><button type="button" class="sort-btn" data-sort-type="text">{{ t('hero_slides.col.updated') }}</button></th>
            <th>{{ t('hero_slides.col.actions') }}</th>
        </tr>
    </thead>
    <tbody>
    {% for slide in slides %}
    <tr>
        <td>{{ slide.id }}</td>
        <td>
            {% if slide.image %}
            <img src="/assets/uploads/hero/thumb_{{ slide.image }}" class="img-thumb">
            {% endif %}
        </td>
        <td>{{ slide.title ?? '—' }}</td>
        <td>{{ slide.sort_order }}</td>
        <td data-sort-value="{{ slide.is_active ? 1 : 0 }}">{{ slide.is_active ? '✓' : '—' }}</td>
        <td>{{ slide.updated_by_email ?? t('common.audit.unknown_user') }}</td>
        <td class="audit-meta">{{ slide.updated_at }}</td>
        <td>
            <a href="/admin/hero-slides/{{ slide.id }}/edit">{{ t('hero_slides.edit') }}</a> |
            <form method="POST" action="/admin/hero-slides/{{ slide.id }}/delete" style="display:inline" onsubmit="return confirm('{{ t('hero_slides.confirm_delete') }}')">
                <button class="btn-link">{{ t('hero_slides.delete') }}</button>
            </form>
        </td>
    </tr>
    {% else %}
    <tr><td colspan="8">{{ t('hero_slides.no_slides') }}</td></tr>
    {% endfor %}
    </tbody>
</table>
{% endblock %}
```

- [ ] **Step 4: Create `templates/admin/hero-slides/form.twig`**

```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ slide ? t('hero_slides.form.title_edit') : t('hero_slides.form.title_new') }}{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>{{ slide ? t('hero_slides.form.title_edit') : t('hero_slides.form.title_new') }}</h1>
    <a href="/admin/hero-slides" class="btn btn-secondary">{{ t('hero_slides.form.back') }}</a>
</div>
{% if slide %}
<p class="audit-meta">
    {{ t('hero_slides.audit.created', {user: slide.created_by_email ?? t('common.audit.unknown_user'), date: slide.created_at}) }}
    ·
    {{ t('hero_slides.audit.updated', {user: slide.updated_by_email ?? t('common.audit.unknown_user'), date: slide.updated_at}) }}
</p>
{% endif %}
<form method="POST" action="{{ slide ? '/admin/hero-slides/' ~ slide.id ~ '/edit' : '/admin/hero-slides/new' }}" enctype="multipart/form-data" class="admin-form">
    <div class="form-group">
        <label>{{ t('hero_slides.form.image_label') }}</label>
        <input type="file" name="image" accept="image/*">
    </div>
    {% if slide and slide.image %}
    <div class="form-group">
        <label>{{ t('hero_slides.form.existing_image') }}</label>
        <div><img src="/assets/uploads/hero/thumb_{{ slide.image }}" class="img-thumb"></div>
    </div>
    {% endif %}
    <div class="form-group">
        <label>{{ t('hero_slides.form.cta_url_label') }}</label>
        <input type="text" name="cta_url" value="{{ slide.cta_url ?? '/shop' }}" required>
    </div>
    <div class="form-group">
        <label>{{ t('hero_slides.form.order_label') }}</label>
        <input type="number" name="sort_order" value="{{ slide.sort_order ?? 0 }}">
    </div>
    <div class="form-group">
        <label>
            <input type="checkbox" name="is_active" value="1" {% if slide is null or slide.is_active %}checked{% endif %}>
            {{ t('hero_slides.form.active_label') }}
        </label>
    </div>
    <h3>{{ t('hero_slides.form.translations') }}</h3>
    {% set lang_labels = {cs: 'CZ', sk: 'SK', en: 'EN', uk: 'UA', ru: 'RU'} %}
    <div class="lang-tabs">
        {% for lang in langs %}
        <button type="button" class="lang-tab {% if lang == admin_lang %}active preferred{% endif %}" data-lang="{{ lang }}">{% if lang == admin_lang %}★ {% endif %}{{ lang_labels[lang] ?? lang|upper }}</button>
        {% endfor %}
    </div>
    {% for lang in langs %}
    <div class="lang-panel {% if lang == admin_lang %}active{% endif %}" id="panel-{{ lang }}">
        <div class="form-group">
            <label>{{ t('hero_slides.form.title_label') }} ({{ lang_labels[lang] ?? lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][title]" value="{{ translations[lang].title ?? '' }}">
        </div>
        <div class="form-group">
            <label>{{ t('hero_slides.form.subtitle_label') }} ({{ lang_labels[lang] ?? lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][subtitle]" value="{{ translations[lang].subtitle ?? '' }}">
        </div>
        <div class="form-group">
            <label>{{ t('hero_slides.form.cta_label_label') }} ({{ lang_labels[lang] ?? lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][cta_label]" value="{{ translations[lang].cta_label ?? '' }}">
        </div>
        {% if lang != admin_lang %}
        <div class="form-group">
            <button type="button" class="btn btn-secondary translate-btn" data-lang="{{ lang }}">{{ t('hero_slides.form.translate_btn') }}</button>
            <span class="translate-msg" style="display:none;margin-left:0.5rem;font-size:0.85rem;"></span>
        </div>
        {% endif %}
    </div>
    {% endfor %}
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">{{ t('hero_slides.form.save') }}</button>
        <a href="/admin/hero-slides" class="btn btn-secondary">{{ t('hero_slides.form.cancel') }}</a>
    </div>
</form>
{% endblock %}
{% block scripts %}
<script>
const PREFERRED_LANG = "{{ admin_lang }}";

document.querySelectorAll('.lang-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.lang-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.lang-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel-' + tab.dataset.lang).classList.add('active');
    });
});

document.querySelectorAll('.translate-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const targetLang = btn.dataset.lang;
        const panel       = document.getElementById('panel-' + targetLang);
        const msgSpan     = panel.querySelector('.translate-msg');
        const fields      = [
            { name: 'title',     el: document.querySelector('input[name="t[' + PREFERRED_LANG + '][title]"]') },
            { name: 'subtitle',  el: document.querySelector('input[name="t[' + PREFERRED_LANG + '][subtitle]"]') },
            { name: 'cta_label', el: document.querySelector('input[name="t[' + PREFERRED_LANG + '][cta_label]"]') },
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
    });
});
</script>
{% endblock %}
```

- [ ] **Step 5: Add the admin nav link**

In `templates/layout/admin-base.twig`, change:

```twig
            <a href="/admin/categories">{{ t('nav.categories') }}</a>
            <a href="/admin/orders">{{ t('nav.orders') }}</a>
```

to:

```twig
            <a href="/admin/categories">{{ t('nav.categories') }}</a>
            <a href="/admin/hero-slides">{{ t('nav.hero_slides') }}</a>
            <a href="/admin/orders">{{ t('nav.orders') }}</a>
```

- [ ] **Step 6: Add admin translation keys to all 5 `lang/admin/*.json` files**

Add these keys (alphabetically, matching the existing file style) to `lang/admin/cs.json`:

```json
  "hero_slides.add": "+ Přidat snímek",
  "hero_slides.audit.created": "Vytvořil(a) {user} dne {date}",
  "hero_slides.audit.updated": "Naposledy upravil(a) {user} dne {date}",
  "hero_slides.col.actions": "Akce",
  "hero_slides.col.active": "Aktivní",
  "hero_slides.col.id": "ID",
  "hero_slides.col.image": "Obrázek",
  "hero_slides.col.order": "Pořadí",
  "hero_slides.col.title": "Nadpis",
  "hero_slides.col.updated": "Naposledy upraveno",
  "hero_slides.col.updated_by": "Upravil(a)",
  "hero_slides.confirm_delete": "Smazat?",
  "hero_slides.delete": "Smazat",
  "hero_slides.edit": "Upravit",
  "hero_slides.flash.created": "Snímek vytvořen.",
  "hero_slides.flash.deleted": "Snímek smazán.",
  "hero_slides.flash.updated": "Snímek uložen.",
  "hero_slides.form.active_label": "Aktivní",
  "hero_slides.form.back": "← Zpět",
  "hero_slides.form.cancel": "Zrušit",
  "hero_slides.form.cta_label_label": "Text tlačítka",
  "hero_slides.form.cta_url_label": "Odkaz tlačítka (URL)",
  "hero_slides.form.existing_image": "Aktuální obrázek",
  "hero_slides.form.image_label": "Obrázek",
  "hero_slides.form.order_label": "Pořadí",
  "hero_slides.form.save": "Uložit",
  "hero_slides.form.subtitle_label": "Podnadpis",
  "hero_slides.form.title_edit": "Upravit snímek",
  "hero_slides.form.title_label": "Nadpis",
  "hero_slides.form.title_new": "Nový snímek",
  "hero_slides.form.translate_btn": "Přeložit z výchozího jazyka",
  "hero_slides.form.translations": "Překlady",
  "hero_slides.no_slides": "Žádné snímky.",
  "hero_slides.title": "Úvodní carousel",
  "nav.hero_slides": "Úvodní carousel",
```

`lang/admin/sk.json`:

```json
  "hero_slides.add": "+ Pridať snímku",
  "hero_slides.audit.created": "Vytvoril(a) {user} dňa {date}",
  "hero_slides.audit.updated": "Naposledy upravil(a) {user} dňa {date}",
  "hero_slides.col.actions": "Akcie",
  "hero_slides.col.active": "Aktívny",
  "hero_slides.col.id": "ID",
  "hero_slides.col.image": "Obrázok",
  "hero_slides.col.order": "Poradie",
  "hero_slides.col.title": "Nadpis",
  "hero_slides.col.updated": "Naposledy upravené",
  "hero_slides.col.updated_by": "Upravil(a)",
  "hero_slides.confirm_delete": "Zmazať?",
  "hero_slides.delete": "Zmazať",
  "hero_slides.edit": "Upraviť",
  "hero_slides.flash.created": "Snímka vytvorená.",
  "hero_slides.flash.deleted": "Snímka zmazaná.",
  "hero_slides.flash.updated": "Snímka uložená.",
  "hero_slides.form.active_label": "Aktívny",
  "hero_slides.form.back": "← Späť",
  "hero_slides.form.cancel": "Zrušiť",
  "hero_slides.form.cta_label_label": "Text tlačidla",
  "hero_slides.form.cta_url_label": "Odkaz tlačidla (URL)",
  "hero_slides.form.existing_image": "Aktuálny obrázok",
  "hero_slides.form.image_label": "Obrázok",
  "hero_slides.form.order_label": "Poradie",
  "hero_slides.form.save": "Uložiť",
  "hero_slides.form.subtitle_label": "Podnadpis",
  "hero_slides.form.title_edit": "Upraviť snímku",
  "hero_slides.form.title_label": "Nadpis",
  "hero_slides.form.title_new": "Nová snímka",
  "hero_slides.form.translate_btn": "Preložiť z predvoleného jazyka",
  "hero_slides.form.translations": "Preklady",
  "hero_slides.no_slides": "Žiadne snímky.",
  "hero_slides.title": "Úvodný carousel",
  "nav.hero_slides": "Úvodný carousel",
```

`lang/admin/en.json`:

```json
  "hero_slides.add": "+ Add Slide",
  "hero_slides.audit.created": "Created by {user} on {date}",
  "hero_slides.audit.updated": "Last updated by {user} on {date}",
  "hero_slides.col.actions": "Actions",
  "hero_slides.col.active": "Active",
  "hero_slides.col.id": "ID",
  "hero_slides.col.image": "Image",
  "hero_slides.col.order": "Order",
  "hero_slides.col.title": "Title",
  "hero_slides.col.updated": "Last Updated",
  "hero_slides.col.updated_by": "Updated By",
  "hero_slides.confirm_delete": "Delete?",
  "hero_slides.delete": "Delete",
  "hero_slides.edit": "Edit",
  "hero_slides.flash.created": "Slide created.",
  "hero_slides.flash.deleted": "Slide deleted.",
  "hero_slides.flash.updated": "Slide saved.",
  "hero_slides.form.active_label": "Active",
  "hero_slides.form.back": "← Back",
  "hero_slides.form.cancel": "Cancel",
  "hero_slides.form.cta_label_label": "Button text",
  "hero_slides.form.cta_url_label": "Button link (URL)",
  "hero_slides.form.existing_image": "Current image",
  "hero_slides.form.image_label": "Image",
  "hero_slides.form.order_label": "Order",
  "hero_slides.form.save": "Save",
  "hero_slides.form.subtitle_label": "Subtitle",
  "hero_slides.form.title_edit": "Edit Slide",
  "hero_slides.form.title_label": "Title",
  "hero_slides.form.title_new": "New Slide",
  "hero_slides.form.translate_btn": "Translate from default language",
  "hero_slides.form.translations": "Translations",
  "hero_slides.no_slides": "No slides.",
  "hero_slides.title": "Hero Slides",
  "nav.hero_slides": "Hero Slides",
```

`lang/admin/uk.json`:

```json
  "hero_slides.add": "+ Додати слайд",
  "hero_slides.audit.created": "Створив(ла) {user} {date}",
  "hero_slides.audit.updated": "Востаннє оновив(ла) {user} {date}",
  "hero_slides.col.actions": "Дії",
  "hero_slides.col.active": "Активний",
  "hero_slides.col.id": "ID",
  "hero_slides.col.image": "Зображення",
  "hero_slides.col.order": "Порядок",
  "hero_slides.col.title": "Заголовок",
  "hero_slides.col.updated": "Останнє оновлення",
  "hero_slides.col.updated_by": "Оновив(ла)",
  "hero_slides.confirm_delete": "Видалити?",
  "hero_slides.delete": "Видалити",
  "hero_slides.edit": "Редагувати",
  "hero_slides.flash.created": "Слайд створено.",
  "hero_slides.flash.deleted": "Слайд видалено.",
  "hero_slides.flash.updated": "Слайд збережено.",
  "hero_slides.form.active_label": "Активний",
  "hero_slides.form.back": "← Назад",
  "hero_slides.form.cancel": "Скасувати",
  "hero_slides.form.cta_label_label": "Текст кнопки",
  "hero_slides.form.cta_url_label": "Посилання кнопки (URL)",
  "hero_slides.form.existing_image": "Поточне зображення",
  "hero_slides.form.image_label": "Зображення",
  "hero_slides.form.order_label": "Порядок",
  "hero_slides.form.save": "Зберегти",
  "hero_slides.form.subtitle_label": "Підзаголовок",
  "hero_slides.form.title_edit": "Редагувати слайд",
  "hero_slides.form.title_label": "Заголовок",
  "hero_slides.form.title_new": "Новий слайд",
  "hero_slides.form.translate_btn": "Перекласти з основної мови",
  "hero_slides.form.translations": "Переклади",
  "hero_slides.no_slides": "Немає слайдів.",
  "hero_slides.title": "Слайди на головній",
  "nav.hero_slides": "Слайди на головній",
```

`lang/admin/ru.json`:

```json
  "hero_slides.add": "+ Добавить слайд",
  "hero_slides.audit.created": "Создал(а) {user} {date}",
  "hero_slides.audit.updated": "Последнее изменение {user} {date}",
  "hero_slides.col.actions": "Действия",
  "hero_slides.col.active": "Активен",
  "hero_slides.col.id": "ID",
  "hero_slides.col.image": "Изображение",
  "hero_slides.col.order": "Порядок",
  "hero_slides.col.title": "Заголовок",
  "hero_slides.col.updated": "Последнее изменение",
  "hero_slides.col.updated_by": "Изменил(а)",
  "hero_slides.confirm_delete": "Удалить?",
  "hero_slides.delete": "Удалить",
  "hero_slides.edit": "Редактировать",
  "hero_slides.flash.created": "Слайд создан.",
  "hero_slides.flash.deleted": "Слайд удалён.",
  "hero_slides.flash.updated": "Слайд сохранён.",
  "hero_slides.form.active_label": "Активен",
  "hero_slides.form.back": "← Назад",
  "hero_slides.form.cancel": "Отмена",
  "hero_slides.form.cta_label_label": "Текст кнопки",
  "hero_slides.form.cta_url_label": "Ссылка кнопки (URL)",
  "hero_slides.form.existing_image": "Текущее изображение",
  "hero_slides.form.image_label": "Изображение",
  "hero_slides.form.order_label": "Порядок",
  "hero_slides.form.save": "Сохранить",
  "hero_slides.form.subtitle_label": "Подзаголовок",
  "hero_slides.form.title_edit": "Редактировать слайд",
  "hero_slides.form.title_label": "Заголовок",
  "hero_slides.form.title_new": "Новый слайд",
  "hero_slides.form.translate_btn": "Перевести с основного языка",
  "hero_slides.form.translations": "Переводы",
  "hero_slides.no_slides": "Нет слайдов.",
  "hero_slides.title": "Слайды на главной",
  "nav.hero_slides": "Слайды на главной",
```

Insert each language's keys in alphabetical order within its file (matching the existing sort convention) rather than appending at the end.

- [ ] **Step 7: Verify all 5 admin lang files still have identical key sets**

```bash
php -r '
$files = ["cs","sk","en","uk","ru"];
$base = json_decode(file_get_contents("lang/admin/cs.json"), true);
sort($baseKeys = array_keys($base));
foreach ($files as $f) {
    $keys = array_keys(json_decode(file_get_contents("lang/admin/$f.json"), true));
    sort($keys);
    if ($keys !== $baseKeys) {
        echo "MISMATCH in $f.json\n";
        echo "Missing: " . implode(", ", array_diff($baseKeys, $keys)) . "\n";
        echo "Extra:   " . implode(", ", array_diff($keys, $baseKeys)) . "\n";
    }
}
echo "done\n";
'
```

Expected: `done` with no `MISMATCH` lines.

- [ ] **Step 8: Manually verify the admin screen**

With the local server running (`php -S localhost:8080 -t www`, `/admin/setup` already completed):

1. Log in at `http://localhost:8080/admin/login`.
2. Visit `http://localhost:8080/admin/hero-slides` — the seeded slide from Task 1 appears in the list.
3. Click "Edit", confirm the CZ title/subtitle/CTA fields show the seeded copy, upload an image, save.
4. Confirm the list now shows a thumbnail for that slide.
5. Click "+ Add Slide", fill in CZ fields, upload an image, save — confirm it appears in the list.
6. Delete the newly-added slide — confirm it disappears and its uploaded file is removed from `www/assets/uploads/hero/`.

- [ ] **Step 9: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests pass.

- [ ] **Step 10: Commit**

```bash
git add src/Controllers/Admin/HeroSlideController.php \
        templates/admin/hero-slides/index.twig templates/admin/hero-slides/form.twig \
        src/routes.php templates/layout/admin-base.twig \
        lang/admin/cs.json lang/admin/sk.json lang/admin/en.json lang/admin/uk.json lang/admin/ru.json
git commit -m "$(cat <<'EOF'
feat: add hero slides admin CRUD screen

EOF
)"
```

---

### Task 4: Public homepage carousel

**Files:**
- Modify: `src/Controllers/HomeController.php`
- Modify: `templates/public/home.twig`
- Modify: `www/assets/css/style.css`
- Create: `www/assets/js/hero-carousel.js`
- Modify: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`

**Interfaces:**
- Consumes: `HeroSlideModel::active(string $lang): array` (Task 2) — each row has
  `id, image, cta_url, title, subtitle, cta_label`.

- [ ] **Step 1: Pass slides from `HomeController`**

In `src/Controllers/HomeController.php`, add the import:

```php
use App\Models\HeroSlideModel;
```

and change:

```php
        return $this->render($request, $response, 'public/home.twig', [
            'page'            => PageModel::find('home', $lang),
            'recently_viewed' => RecentlyViewed::items($lang),
        ]);
```

to:

```php
        return $this->render($request, $response, 'public/home.twig', [
            'page'            => PageModel::find('home', $lang),
            'recently_viewed' => RecentlyViewed::items($lang),
            'hero_slides'     => HeroSlideModel::active($lang),
        ]);
```

- [ ] **Step 2: Replace the hero section in `home.twig`**

Replace:

```twig
{% block content %}
<section class="hero">
    <div class="container">
        <h1>{{ t('home.hero_title') }}</h1>
        <p class="hero-subtitle">{{ t('home.hero_subtitle') }}</p>
        <a href="/{{ lang }}/shop" class="btn btn-primary">{{ t('home.cta') }}</a>
    </div>
</section>

{% include 'public/partials/recently-viewed-row.twig' %}
{% endblock %}
```

with:

```twig
{% block content %}
{% if hero_slides %}
<section class="hero-carousel" data-hero-carousel>
    <div class="container hero-carousel-inner">
        {% for slide in hero_slides %}
        <div class="hero-slide {% if loop.first %}active{% endif %}" data-hero-slide>
            <div class="hero-slide-copy">
                <h1>{{ slide.title }}</h1>
                {% if slide.subtitle %}<p class="hero-slide-subtitle">{{ slide.subtitle }}</p>{% endif %}
                <a href="/{{ lang }}{{ slide.cta_url }}" class="btn btn-primary">{{ slide.cta_label }}</a>
            </div>
            <div class="hero-slide-media">
                {% if slide.image %}
                <img src="/assets/uploads/hero/{{ slide.image }}" alt="">
                {% else %}
                <div class="hero-slide-placeholder"></div>
                {% endif %}
            </div>
        </div>
        {% endfor %}
        {% if hero_slides|length > 1 %}
        <button type="button" class="hero-carousel-arrow hero-carousel-arrow--prev" data-hero-prev aria-label="{{ t('home.hero_prev') }}">‹</button>
        <button type="button" class="hero-carousel-arrow hero-carousel-arrow--next" data-hero-next aria-label="{{ t('home.hero_next') }}">›</button>
        <div class="hero-carousel-dots" data-hero-dots>
            {% for slide in hero_slides %}
            <button type="button" class="hero-carousel-dot {% if loop.first %}active{% endif %}" data-hero-dot data-index="{{ loop.index0 }}" aria-label="{{ t('home.hero_goto', {n: loop.index}) }}"></button>
            {% endfor %}
        </div>
        {% endif %}
    </div>
</section>
{% endif %}

{% include 'public/partials/recently-viewed-row.twig' %}
{% endblock %}
{% block scripts %}
{{ parent() }}
<script src="/assets/js/hero-carousel.js" defer></script>
{% endblock %}
```

- [ ] **Step 3: Replace the old `.hero`/`.hero-subtitle` CSS with carousel styles**

In `www/assets/css/style.css`, replace:

```css
.hero { padding: 6rem 0; text-align: center; background: linear-gradient(to bottom,var(--surface),var(--bg)); }
.hero h1 { font-size: 2.8rem; font-weight: normal; margin-bottom: 1rem; }
.hero-subtitle { color: var(--muted); font-size: 1.2rem; margin-bottom: 2rem; }
```

with:

```css
.hero-carousel { position: relative; background: linear-gradient(to bottom,var(--surface),var(--bg)); overflow: hidden; }
.hero-carousel-inner { position: relative; padding: 3rem 0; }
.hero-slide { display: none; grid-template-columns: 1fr 1fr; align-items: center; gap: 2rem; }
.hero-slide.active { display: grid; }
.hero-slide-copy h1 { font-size: 2.4rem; font-weight: normal; margin-bottom: 1rem; }
.hero-slide-subtitle { color: var(--muted); font-size: 1.1rem; margin-bottom: 1.5rem; }
.hero-slide-media { aspect-ratio: 16/10; overflow: hidden; border-radius: 4px; background: var(--border); }
.hero-slide-media img { width: 100%; height: 100%; object-fit: cover; }
.hero-slide-placeholder { width: 100%; height: 100%; }
.hero-carousel-arrow { position: absolute; top: 50%; transform: translateY(-50%); width: 40px; height: 40px; border-radius: 50%; border: none; background: var(--surface); color: var(--text); font-size: 1.3rem; line-height: 1; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
.hero-carousel-arrow:hover { color: var(--accent); }
.hero-carousel-arrow--prev { left: .75rem; }
.hero-carousel-arrow--next { right: .75rem; }
.hero-carousel-dots { display: flex; justify-content: center; gap: .5rem; margin-top: 1.5rem; }
.hero-carousel-dot { width: 10px; height: 10px; border-radius: 50%; border: none; background: var(--border); padding: 0; cursor: pointer; }
.hero-carousel-dot.active { background: var(--accent); }
```

Then extend the shared placeholder-background rule so the new placeholder reuses the existing branded gradient instead of duplicating it — replace:

```css
.product-img-placeholder, .gallery-cover-placeholder {
```

with:

```css
.product-img-placeholder, .gallery-cover-placeholder, .hero-slide-placeholder {
```

Then, in the 480px block, replace:

```css
    .hero { padding: 3rem 0; }
    .hero h1 { font-size: 2rem; }
    .hero-subtitle { font-size: 1rem; }
```

with:

```css
    .hero-carousel-inner { padding: 1.5rem 0; }
    .hero-slide-copy h1 { font-size: 1.5rem; }
    .hero-slide-subtitle { font-size: .95rem; }
```

Finally, add a 768px responsive block next to the carousel rules (per `.claude/rules/css-styling.md`, immediately after the `.hero-carousel-dot.active` rule):

```css
/* Responsive: hero carousel stacks image below copy (tablet) */
@media (max-width: 768px) {
    .hero-slide { grid-template-columns: 1fr; }
    .hero-slide-media { order: -1; }
    .hero-slide-copy h1 { font-size: 1.75rem; }
    .hero-carousel-arrow { width: 32px; height: 32px; font-size: 1.1rem; }
}
```

- [ ] **Step 4: Create `www/assets/js/hero-carousel.js`**

```javascript
document.addEventListener('DOMContentLoaded', function () {
    var carousel = document.querySelector('[data-hero-carousel]');
    if (!carousel) return;

    var slides = carousel.querySelectorAll('[data-hero-slide]');
    var dots   = carousel.querySelectorAll('[data-hero-dot]');
    var prev   = carousel.querySelector('[data-hero-prev]');
    var next   = carousel.querySelector('[data-hero-next]');
    if (slides.length < 2) return;

    var current  = 0;
    var timer    = null;
    var INTERVAL = 6000;

    function show(index) {
        current = (index + slides.length) % slides.length;
        slides.forEach(function (slide, i) { slide.classList.toggle('active', i === current); });
        dots.forEach(function (dot, i) { dot.classList.toggle('active', i === current); });
    }

    function startAutoplay() {
        stopAutoplay();
        timer = setInterval(function () { show(current + 1); }, INTERVAL);
    }

    function stopAutoplay() {
        if (timer) clearInterval(timer);
        timer = null;
    }

    if (prev) prev.addEventListener('click', function () { show(current - 1); startAutoplay(); });
    if (next) next.addEventListener('click', function () { show(current + 1); startAutoplay(); });
    dots.forEach(function (dot, i) {
        dot.addEventListener('click', function () { show(i); startAutoplay(); });
    });

    carousel.addEventListener('mouseenter', stopAutoplay);
    carousel.addEventListener('mouseleave', startAutoplay);
    carousel.addEventListener('focusin', stopAutoplay);
    carousel.addEventListener('focusout', startAutoplay);

    startAutoplay();
});
```

- [ ] **Step 5: Update public translation keys in all 5 `lang/*.json` files**

In each of `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`:

Remove these two now-unused keys:

```json
  "home.hero_subtitle": "...",
  "home.cta": "...",
```

(`home.hero_title` stays — it's still used as the `<title>` tag fallback in `home.twig`.)

Add these three new keys (alphabetically among the `home.*` group):

`lang/cs.json`:
```json
  "home.hero_goto": "Přejít na snímek {n}",
  "home.hero_next": "Další snímek",
  "home.hero_prev": "Předchozí snímek",
```

`lang/en.json`:
```json
  "home.hero_goto": "Go to slide {n}",
  "home.hero_next": "Next slide",
  "home.hero_prev": "Previous slide",
```

`lang/ru.json`:
```json
  "home.hero_goto": "Перейти к слайду {n}",
  "home.hero_next": "Следующий слайд",
  "home.hero_prev": "Предыдущий слайд",
```

`lang/uk.json`:
```json
  "home.hero_goto": "Перейти до слайду {n}",
  "home.hero_next": "Наступний слайд",
  "home.hero_prev": "Попередній слайд",
```

`lang/sk.json`:
```json
  "home.hero_goto": "Prejsť na snímok {n}",
  "home.hero_next": "Ďalší snímok",
  "home.hero_prev": "Predchádzajúci snímok",
```

- [ ] **Step 6: Verify all 5 public lang files still have identical key sets**

```bash
php -r '
$files = ["cs","en","ru","uk","sk"];
$base = json_decode(file_get_contents("lang/cs.json"), true);
sort($baseKeys = array_keys($base));
foreach ($files as $f) {
    $keys = array_keys(json_decode(file_get_contents("lang/$f.json"), true));
    sort($keys);
    if ($keys !== $baseKeys) {
        echo "MISMATCH in $f.json\n";
        echo "Missing: " . implode(", ", array_diff($baseKeys, $keys)) . "\n";
        echo "Extra:   " . implode(", ", array_diff($keys, $baseKeys)) . "\n";
    }
}
echo "done\n";
'
```

Expected: `done` with no `MISMATCH` lines.

- [ ] **Step 7: Manually verify the public homepage**

With the local server running:

1. Visit `http://localhost:8080/cs/` — the seeded slide renders with its title/subtitle/CTA and a placeholder graphic (no image uploaded yet in Task 3's DB state, unless you uploaded one during Step 8 of Task 3).
2. In the admin, add a second active slide (Task 3's "+ Add Slide" flow) with different CZ copy, then reload `http://localhost:8080/cs/`.
3. Confirm arrows and dots now appear; clicking next/prev/dots switches slides; the carousel auto-advances after ~6s; hovering over it pauses the auto-advance, moving the mouse away resumes it.
4. Deactivate one slide in the admin (uncheck "Active", save) — confirm it no longer appears in the homepage rotation.
5. Check `http://localhost:8080/en/` and one other language — confirm the seeded slide's title/subtitle/CTA render in that language.

- [ ] **Step 8: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests pass.

- [ ] **Step 9: Commit**

```bash
git add src/Controllers/HomeController.php templates/public/home.twig \
        www/assets/css/style.css www/assets/js/hero-carousel.js \
        lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json
git commit -m "$(cat <<'EOF'
feat: render homepage hero as an image carousel

EOF
)"
```
