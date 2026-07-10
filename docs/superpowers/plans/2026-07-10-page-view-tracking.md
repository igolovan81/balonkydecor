# Page-View Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Track visits to public pages (path, language, referrer, anonymized IP, user agent) and surface a summary + top-pages report in the admin panel, with automatic 90-day retention.

**Architecture:** A new `page_views` table stores one row per public-page GET request. A `PageViewMiddleware`, registered globally in `app.php`, filters to GET requests under a supported-language path segment (naturally excluding `/admin/*`, `/payment/*`, static assets) and delegates persistence to an injected recorder closure — keeping the middleware itself DB-free and unit-testable (mirrors the existing `LangMiddlewareTest` pattern). The closure calls `PageViewModel::record()` (with the IP anonymized first) and has a 1-in-100 chance of also calling `PageViewModel::pruneOlderThan(90)`, since there's no cron on this host. A new admin route/controller/template shows total views, unique visitors, and a paginated top-pages table, styled like the existing Orders admin list.

**Tech Stack:** PHP 8 / Slim 4, PDO/MySQL 8, Twig 3, PHPUnit 11 (model tests need Docker MySQL; middleware tests don't).

## Global Constraints

- Prepared statements with bound parameters only; no SQL string interpolation of request data (`.claude/rules/database.md`).
- Migration file name: `database/migrations/V019__page_views.sql` (never edit/delete already-applied migrations).
- IPs are **never stored raw** — always anonymized (last IPv4 octet or last IPv6 hextet zeroed) before `PageViewModel::record()` is called.
- `PageViewMiddleware` must have zero DB dependency of its own — it only calls the injected recorder closure; all persistence logic lives in `PageViewModel`, testable separately with real MySQL.
- All 5 admin lang files (`lang/admin/{cs,en,ru,uk,sk}.json`) must gain the same new keys, kept alphabetically sorted (existing convention).
- New admin routes go inside the existing `$app->group('/admin', ...)` block in `src/routes.php` so `AuthMiddleware` + `AdminLangMiddleware` apply — this repo's `/admin/*` static routes already sit before `/{lang}/*` variable routes, and this doesn't change that order.
- Run `php vendor/bin/phpunit` (whole suite) before considering any task done; must be fully green.
- Local dev DB (`docker compose up -d`) must be running for `PageViewModel` tests (not needed for `PageViewMiddleware` tests).

---

### Task 1: Migration — create `page_views` table

**Files:**
- Create: `database/migrations/V019__page_views.sql`

**Interfaces:**
- Produces: table `page_views` (`id`, `path`, `lang`, `referrer`, `ip_anon`, `user_agent`, `created_at`), indexed on `created_at` and `path`. Read/written directly via PDO by `PageViewModel` (Task 2).

- [ ] **Step 1: Write the migration file**

```sql
CREATE TABLE `page_views` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `path`        varchar(255) NOT NULL,
  `lang`        varchar(5) NOT NULL,
  `referrer`    varchar(500) DEFAULT NULL,
  `ip_anon`     varchar(45) DEFAULT NULL,
  `user_agent`  varchar(255) DEFAULT NULL,
  `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_page_views_created_at` (`created_at`),
  KEY `idx_page_views_path` (`path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
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
Expected: `{"applied": ["V019__page_views"], "count": 1}`.

- [ ] **Step 4: Verify the schema**

```bash
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e "DESCRIBE page_views;"
```
Expected: columns `id`, `path`, `lang`, `referrer`, `ip_anon`, `user_agent`, `created_at`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/V019__page_views.sql
git commit -m "feat: add page_views table"
```

---

### Task 2: `PageViewModel` + tests

**Files:**
- Create: `src/Models/PageViewModel.php`
- Test: `tests/Unit/Models/PageViewModelTest.php`

**Interfaces:**
- Consumes: `page_views` table (Task 1).
- Produces: `PageViewModel::anonymizeIp(string $ip): string`, `PageViewModel::record(string $path, string $lang, ?string $referrer, ?string $ipAnon, ?string $userAgent): void`, `PageViewModel::summary(string $from, string $to): array` (`['total_views' => int, 'unique_visitors' => int]`), `PageViewModel::topPages(string $from, string $to, int $page, int $perPage): array` (`['rows' => [['path'=>string,'views'=>int], ...], 'total' => int, 'pages' => int]`), `PageViewModel::pruneOlderThan(int $days): int`. Consumed by Task 4 (middleware wiring) and Task 5 (`PageViewController`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Models/PageViewModelTest.php`:

```php
<?php
namespace Tests\Unit\Models;

use App\Models\PageViewModel;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

class PageViewModelTest extends TestCase
{
    public function test_anonymize_ip_zeroes_last_ipv4_octet(): void
    {
        $this->assertSame('89.24.130.0', PageViewModel::anonymizeIp('89.24.130.57'));
    }

    public function test_anonymize_ip_zeroes_last_ipv6_hextet(): void
    {
        $this->assertSame(
            '2001:db8:85a3:0:0:8a2e:370:0',
            PageViewModel::anonymizeIp('2001:db8:85a3:0:0:8a2e:370:7334')
        );
    }

    public function test_record_persists_row(): void
    {
        $path = '/cs/audit-test-' . uniqid();
        PageViewModel::record($path, 'cs', 'https://example.com', '1.2.3.0', 'TestAgent/1.0');

        $stmt = Database::getConnection()->prepare('SELECT * FROM page_views WHERE path = ?');
        $stmt->execute([$path]);
        $row = $stmt->fetch();

        $this->assertSame('cs', $row['lang']);
        $this->assertSame('https://example.com', $row['referrer']);
        $this->assertSame('1.2.3.0', $row['ip_anon']);
        $this->assertSame('TestAgent/1.0', $row['user_agent']);
    }

    public function test_summary_counts_views_and_unique_visitors_in_range(): void
    {
        $pdo  = Database::getConnection();
        $path = '/cs/summary-test-' . uniqid();
        $pdo->prepare("INSERT INTO page_views (path, lang, ip_anon, created_at) VALUES (?, 'cs', '10.0.0.0', NOW())")->execute([$path]);
        $pdo->prepare("INSERT INTO page_views (path, lang, ip_anon, created_at) VALUES (?, 'cs', '10.0.0.0', NOW())")->execute([$path]);
        $pdo->prepare("INSERT INTO page_views (path, lang, ip_anon, created_at) VALUES (?, 'cs', '10.0.0.1', NOW())")->execute([$path]);

        $from = date('Y-m-d H:i:s', strtotime('-1 minute'));
        $to   = date('Y-m-d H:i:s', strtotime('+1 minute'));

        $summary = PageViewModel::summary($from, $to);

        $this->assertGreaterThanOrEqual(3, $summary['total_views']);
        $this->assertGreaterThanOrEqual(2, $summary['unique_visitors']);
    }

    public function test_top_pages_orders_by_view_count_descending(): void
    {
        $pdo     = Database::getConnection();
        $popular = '/cs/top-test-popular-' . uniqid();
        $quiet   = '/cs/top-test-quiet-' . uniqid();
        foreach (range(1, 3) as $i) {
            $pdo->prepare("INSERT INTO page_views (path, lang, created_at) VALUES (?, 'cs', NOW())")->execute([$popular]);
        }
        $pdo->prepare("INSERT INTO page_views (path, lang, created_at) VALUES (?, 'cs', NOW())")->execute([$quiet]);

        $from = date('Y-m-d H:i:s', strtotime('-1 minute'));
        $to   = date('Y-m-d H:i:s', strtotime('+1 minute'));

        $data  = PageViewModel::topPages($from, $to, 1, 100);
        $paths = array_column($data['rows'], 'path');

        $this->assertLessThan(array_search($quiet, $paths), array_search($popular, $paths));
    }

    public function test_top_pages_paginates(): void
    {
        $data = PageViewModel::topPages('2000-01-01', '2100-01-01', 1, 1);
        $this->assertCount(1, $data['rows']);
        $this->assertGreaterThanOrEqual(1, $data['pages']);
    }

    public function test_prune_older_than_deletes_old_rows_but_keeps_recent(): void
    {
        $pdo    = Database::getConnection();
        $old    = '/cs/prune-test-old-' . uniqid();
        $recent = '/cs/prune-test-recent-' . uniqid();
        $pdo->prepare("INSERT INTO page_views (path, lang, created_at) VALUES (?, 'cs', NOW() - INTERVAL 100 DAY)")->execute([$old]);
        $pdo->prepare("INSERT INTO page_views (path, lang, created_at) VALUES (?, 'cs', NOW())")->execute([$recent]);

        PageViewModel::pruneOlderThan(90);

        $oldStmt = $pdo->prepare('SELECT COUNT(*) FROM page_views WHERE path = ?');
        $oldStmt->execute([$old]);
        $this->assertSame(0, (int) $oldStmt->fetchColumn());

        $recentStmt = $pdo->prepare('SELECT COUNT(*) FROM page_views WHERE path = ?');
        $recentStmt->execute([$recent]);
        $this->assertSame(1, (int) $recentStmt->fetchColumn());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Models/PageViewModelTest.php --testdox`
Expected: FAIL — `Class "App\Models\PageViewModel" not found`.

- [ ] **Step 3: Implement `src/Models/PageViewModel.php`**

```php
<?php
namespace App\Models;

class PageViewModel
{
    public static function anonymizeIp(string $ip): string
    {
        if (strpos($ip, ':') !== false) {
            $parts = explode(':', $ip);
            if (count($parts) > 1) {
                $parts[count($parts) - 1] = '0';
                return implode(':', $parts);
            }
            return $ip;
        }

        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            $parts[3] = '0';
            return implode('.', $parts);
        }

        return $ip;
    }

    public static function record(string $path, string $lang, ?string $referrer, ?string $ipAnon, ?string $userAgent): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO page_views (path, lang, referrer, ip_anon, user_agent) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$path, $lang, $referrer, $ipAnon, $userAgent]);
    }

    public static function summary(string $from, string $to): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS total_views,
                    COUNT(DISTINCT CONCAT(ip_anon, "|", DATE(created_at))) AS unique_visitors
             FROM page_views WHERE created_at BETWEEN :from AND :to'
        );
        $stmt->execute(['from' => $from, 'to' => $to]);
        $row = $stmt->fetch();

        return [
            'total_views'     => (int) $row['total_views'],
            'unique_visitors' => (int) $row['unique_visitors'],
        ];
    }

    public static function topPages(string $from, string $to, int $page, int $perPage): array
    {
        $pdo = Database::getConnection();

        $totalStmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT path) FROM page_views WHERE created_at BETWEEN :from AND :to'
        );
        $totalStmt->execute(['from' => $from, 'to' => $to]);
        $total = (int) $totalStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt   = $pdo->prepare(
            'SELECT path, COUNT(*) AS views
             FROM page_views
             WHERE created_at BETWEEN :from AND :to
             GROUP BY path
             ORDER BY views DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':from', $from);
        $stmt->bindValue(':to', $to);
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows'  => $stmt->fetchAll(),
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public static function pruneOlderThan(int $days): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM page_views WHERE created_at < (NOW() - INTERVAL :days DAY)');
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Models/PageViewModelTest.php --testdox`
Expected: PASS (all 7 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Models/PageViewModel.php tests/Unit/Models/PageViewModelTest.php
git commit -m "feat: add PageViewModel"
```

---

### Task 3: `PageViewMiddleware` + tests

**Files:**
- Create: `src/Middleware/PageViewMiddleware.php`
- Test: `tests/Unit/Middleware/PageViewMiddlewareTest.php`

**Interfaces:**
- Produces: `App\Middleware\PageViewMiddleware` — constructor `(array $supportedLangs, \Closure $recorder)` where `$recorder` is called as `($path, $lang, $referrer, $ip, $userAgent)`. Consumed by Task 4 (`app.php` wiring).
- No DB dependency — tests use a spy closure, no Docker MySQL needed.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Middleware/PageViewMiddlewareTest.php`:

```php
<?php
namespace Tests\Unit\Middleware;

use App\Middleware\PageViewMiddleware;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PageViewMiddlewareTest extends TestCase
{
    private const LANGS = ['cs', 'sk', 'en', 'uk', 'ru'];

    private function passthroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $req): ResponseInterface
            {
                return (new ResponseFactory())->createResponse();
            }
        };
    }

    public function test_records_get_request_under_supported_lang_path(): void
    {
        $calls = [];
        $mw    = new PageViewMiddleware(self::LANGS, function (...$args) use (&$calls) {
            $calls[] = $args;
        });

        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/cs/shop', ['REMOTE_ADDR' => '1.2.3.4'])
            ->withHeader('Referer', 'https://google.com')
            ->withHeader('User-Agent', 'TestAgent/1.0');

        $mw->process($req, $this->passthroughHandler());

        $this->assertCount(1, $calls);
        [$path, $lang, $referrer, $ip, $userAgent] = $calls[0];
        $this->assertSame('/cs/shop', $path);
        $this->assertSame('cs', $lang);
        $this->assertSame('https://google.com', $referrer);
        $this->assertSame('1.2.3.4', $ip);
        $this->assertSame('TestAgent/1.0', $userAgent);
    }

    public function test_skips_admin_paths(): void
    {
        $calls = [];
        $mw    = new PageViewMiddleware(self::LANGS, function (...$args) use (&$calls) {
            $calls[] = $args;
        });

        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/services');
        $mw->process($req, $this->passthroughHandler());

        $this->assertCount(0, $calls);
    }

    public function test_skips_non_get_requests(): void
    {
        $calls = [];
        $mw    = new PageViewMiddleware(self::LANGS, function (...$args) use (&$calls) {
            $calls[] = $args;
        });

        $req = (new ServerRequestFactory())->createServerRequest('POST', '/cs/cart/add');
        $mw->process($req, $this->passthroughHandler());

        $this->assertCount(0, $calls);
    }

    public function test_skips_unsupported_lang_segment(): void
    {
        $calls = [];
        $mw    = new PageViewMiddleware(self::LANGS, function (...$args) use (&$calls) {
            $calls[] = $args;
        });

        $req = (new ServerRequestFactory())->createServerRequest('GET', '/robots.txt');
        $mw->process($req, $this->passthroughHandler());

        $this->assertCount(0, $calls);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Middleware/PageViewMiddlewareTest.php --testdox`
Expected: FAIL — `Class "App\Middleware\PageViewMiddleware" not found`.

- [ ] **Step 3: Implement `src/Middleware/PageViewMiddleware.php`**

```php
<?php
namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PageViewMiddleware implements MiddlewareInterface
{
    private \Closure $recorder;

    public function __construct(private array $supportedLangs, \Closure $recorder)
    {
        $this->recorder = $recorder;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($request->getMethod() === 'GET') {
            $path    = $request->getUri()->getPath();
            $segment = explode('/', ltrim($path, '/'))[0];

            if (in_array($segment, $this->supportedLangs, true)) {
                $server    = $request->getServerParams();
                $ip        = $server['REMOTE_ADDR'] ?? '';
                $referrer  = $request->getHeaderLine('Referer') ?: null;
                $userAgent = $request->getHeaderLine('User-Agent') ?: null;

                ($this->recorder)($path, $segment, $referrer, $ip, $userAgent);
            }
        }

        return $response;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Middleware/PageViewMiddlewareTest.php --testdox`
Expected: PASS (all 4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Middleware/PageViewMiddleware.php tests/Unit/Middleware/PageViewMiddlewareTest.php
git commit -m "feat: add PageViewMiddleware"
```

---

### Task 4: Wire the middleware into `app.php`

**Files:**
- Modify: `src/app.php`

**Interfaces:**
- Consumes: `App\Middleware\PageViewMiddleware` (Task 3), `App\Models\PageViewModel::anonymizeIp()`/`::record()`/`::pruneOlderThan()` (Task 2), `$settings['languages']` (already loaded in `app.php`).
- Produces: no new public interface — internal wiring only.

- [ ] **Step 1: Add the `use` import**

In `src/app.php`, add near the top with the other `use` statements:

```php
use App\Middleware\LangMiddleware;
use App\Middleware\PageViewMiddleware;
```

- [ ] **Step 2: Register the middleware**

Change:

```php
$app->add(TwigMiddleware::createFromContainer($app, Twig::class));
$app->add(new LangMiddleware(
    $settings['languages'],
    $settings['default_lang'],
    __DIR__ . '/../lang'
));
```

to:

```php
$app->add(TwigMiddleware::createFromContainer($app, Twig::class));
$app->add(new PageViewMiddleware($settings['languages'], function (
    string $path, string $lang, ?string $referrer, string $ip, ?string $userAgent
) {
    $ipAnon = \App\Models\PageViewModel::anonymizeIp($ip);
    \App\Models\PageViewModel::record($path, $lang, $referrer, $ipAnon, $userAgent);
    if (random_int(1, 100) === 1) {
        \App\Models\PageViewModel::pruneOlderThan(90);
    }
}));
$app->add(new LangMiddleware(
    $settings['languages'],
    $settings['default_lang'],
    __DIR__ . '/../lang'
));
```

- [ ] **Step 3: Verify the app still boots and records a view**

```bash
php -l src/app.php
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/cs/shop
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e \
  "SELECT path, lang, ip_anon FROM page_views ORDER BY id DESC LIMIT 1;"
```
Expected: `php -l` reports no syntax errors; the curl request returns `200`; the SQL query shows a row for `/cs/shop` with an anonymized `ip_anon` (last octet `0` for a local IPv4 request, e.g. `127.0.0.0`).

- [ ] **Step 4: Commit**

```bash
git add src/app.php
git commit -m "feat: wire PageViewMiddleware into app.php"
```

---

### Task 5: Admin report — route, controller, template, nav, translations

**Files:**
- Modify: `src/routes.php`
- Create: `src/Controllers/Admin/PageViewController.php`
- Create: `templates/admin/page-views/index.twig`
- Modify: `templates/layout/admin-base.twig`
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`

**Interfaces:**
- Consumes: `PageViewModel::summary()`/`::topPages()` (Task 2).
- Produces: `GET /admin/page-views` — no other code depends on this; it's the final consumer in this plan.

- [ ] **Step 1: Add the route**

In `src/routes.php`, add the import near the other admin controller imports:

```php
use App\Controllers\Admin\PageController as AdminPageController;
use App\Controllers\Admin\PageViewController;
```

Add the route inside the existing `$app->group('/admin', ...)` block, right after the `// Orders` section:

```php
    // Orders
    $group->get('/orders',                  AdminOrderController::class . ':index');
    $group->get('/orders/{number}',         AdminOrderController::class . ':detail');
    $group->post('/orders/{number}/status', AdminOrderController::class . ':updateStatus');

    // Page views
    $group->get('/page-views', PageViewController::class . ':index');
```

- [ ] **Step 2: Create `src/Controllers/Admin/PageViewController.php`**

```php
<?php
namespace App\Controllers\Admin;

use App\Models\PageViewModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PageViewController extends AdminBaseController
{
    private const ALLOWED_DAYS = [7, 30, 90];
    private const PER_PAGE     = 20;

    public function index(Request $request, Response $response, array $args): Response
    {
        $params = $request->getQueryParams();
        $days   = (int) ($params['days'] ?? 30);
        if (!in_array($days, self::ALLOWED_DAYS, true)) {
            $days = 30;
        }
        $page = max(1, (int) ($params['page'] ?? 1));

        $to   = date('Y-m-d H:i:s');
        $from = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $summary = PageViewModel::summary($from, $to);
        $data    = PageViewModel::topPages($from, $to, $page, self::PER_PAGE);

        return $this->renderAdmin($request, $response, 'admin/page-views/index.twig', [
            'summary' => $summary,
            'rows'    => $data['rows'],
            'page'    => $page,
            'pages'   => $data['pages'],
            'days'    => $days,
        ]);
    }
}
```

- [ ] **Step 3: Create `templates/admin/page-views/index.twig`**

```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ t('page_views.title') }}{% endblock %}
{% block content %}
<div class="admin-topbar"><h1>{{ t('page_views.title') }}</h1></div>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value">{{ summary.total_views }}</div>
        <div class="stat-label">{{ t('page_views.stats.total_views') }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">{{ summary.unique_visitors }}</div>
        <div class="stat-label">{{ t('page_views.stats.unique_visitors') }}</div>
    </div>
</div>
<form method="GET" action="/admin/page-views" style="margin-bottom:1rem;display:flex;gap:0.5rem;align-items:center;">
    <label>{{ t('page_views.filter.label') }}</label>
    <select name="days" onchange="this.form.submit()">
        <option value="7"  {% if days == 7 %}selected{% endif %}>{{ t('page_views.filter.7') }}</option>
        <option value="30" {% if days == 30 %}selected{% endif %}>{{ t('page_views.filter.30') }}</option>
        <option value="90" {% if days == 90 %}selected{% endif %}>{{ t('page_views.filter.90') }}</option>
    </select>
</form>
<table class="admin-table">
    <thead><tr><th>{{ t('page_views.col.path') }}</th><th>{{ t('page_views.col.views') }}</th></tr></thead>
    <tbody>
    {% for row in rows %}
    <tr>
        <td>{{ row.path }}</td>
        <td>{{ row.views }}</td>
    </tr>
    {% else %}
    <tr><td colspan="2">{{ t('page_views.no_data') }}</td></tr>
    {% endfor %}
    </tbody>
</table>
{% if pages > 1 %}
<div class="pagination" style="margin-top:1rem;">
    {% for p in 1..pages %}
    <a href="?page={{ p }}&days={{ days }}" class="{% if p == page %}active{% endif %}">{{ p }}</a>
    {% endfor %}
</div>
{% endif %}
{% endblock %}
```

- [ ] **Step 4: Add the nav link to `templates/layout/admin-base.twig`**

Change:

```twig
            <a href="/admin/orders">{{ t('nav.orders') }}</a>
            <a href="/admin/gallery">{{ t('nav.gallery') }}</a>
```

to:

```twig
            <a href="/admin/orders">{{ t('nav.orders') }}</a>
            <a href="/admin/page-views">{{ t('nav.page_views') }}</a>
            <a href="/admin/gallery">{{ t('nav.gallery') }}</a>
```

- [ ] **Step 5: Add translation keys to `lang/admin/cs.json`**

Insert `"nav.page_views": "Návštěvy",` between `"nav.orders"` and `"nav.pages"`:
```json
  "nav.orders": "Objednávky",
  "nav.page_views": "Návštěvy",
  "nav.pages": "Stránky",
```

Insert this block between `"orders.title"` and `"pages.col.actions"` (alphabetical: `page_views` < `pages`):
```json
  "page_views.col.path": "Stránka",
  "page_views.col.views": "Zobrazení",
  "page_views.filter.30": "Posledních 30 dní",
  "page_views.filter.7": "Posledních 7 dní",
  "page_views.filter.90": "Posledních 90 dní",
  "page_views.filter.label": "Období:",
  "page_views.no_data": "Žádná data.",
  "page_views.stats.total_views": "Celkem zobrazení",
  "page_views.stats.unique_visitors": "Unikátní návštěvníci",
  "page_views.title": "Návštěvy stránek",
```

- [ ] **Step 6: Add the same keys (translated) to the other 4 files, at the same alphabetical positions**

`lang/admin/en.json`:
```json
  "nav.page_views": "Page Views",
```
```json
  "page_views.col.path": "Page",
  "page_views.col.views": "Views",
  "page_views.filter.30": "Last 30 days",
  "page_views.filter.7": "Last 7 days",
  "page_views.filter.90": "Last 90 days",
  "page_views.filter.label": "Period:",
  "page_views.no_data": "No data.",
  "page_views.stats.total_views": "Total Views",
  "page_views.stats.unique_visitors": "Unique Visitors",
  "page_views.title": "Page Views",
```

`lang/admin/ru.json`:
```json
  "nav.page_views": "Просмотры",
```
```json
  "page_views.col.path": "Страница",
  "page_views.col.views": "Просмотры",
  "page_views.filter.30": "Последние 30 дней",
  "page_views.filter.7": "Последние 7 дней",
  "page_views.filter.90": "Последние 90 дней",
  "page_views.filter.label": "Период:",
  "page_views.no_data": "Нет данных.",
  "page_views.stats.total_views": "Всего просмотров",
  "page_views.stats.unique_visitors": "Уникальные посетители",
  "page_views.title": "Просмотры страниц",
```

`lang/admin/uk.json`:
```json
  "nav.page_views": "Перегляди",
```
```json
  "page_views.col.path": "Сторінка",
  "page_views.col.views": "Перегляди",
  "page_views.filter.30": "Останні 30 днів",
  "page_views.filter.7": "Останні 7 днів",
  "page_views.filter.90": "Останні 90 днів",
  "page_views.filter.label": "Період:",
  "page_views.no_data": "Немає даних.",
  "page_views.stats.total_views": "Всього переглядів",
  "page_views.stats.unique_visitors": "Унікальні відвідувачі",
  "page_views.title": "Перегляди сторінок",
```

`lang/admin/sk.json`:
```json
  "nav.page_views": "Návštevy",
```
```json
  "page_views.col.path": "Stránka",
  "page_views.col.views": "Zobrazenia",
  "page_views.filter.30": "Posledných 30 dní",
  "page_views.filter.7": "Posledných 7 dní",
  "page_views.filter.90": "Posledných 90 dní",
  "page_views.filter.label": "Obdobie:",
  "page_views.no_data": "Žiadne dáta.",
  "page_views.stats.total_views": "Celkom zobrazení",
  "page_views.stats.unique_visitors": "Unikátni návštevníci",
  "page_views.title": "Návštevy stránok",
```

- [ ] **Step 7: Verify all 5 files stay valid JSON with identical key sets**

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
Expected: `OK, all files have <N+11> identical keys` (11 new keys: `nav.page_views` + 10 `page_views.*` keys).

- [ ] **Step 8: Manually verify in the browser**

With the local server running and logged into `/admin/login`, visit `http://localhost:8080/admin/page-views` — confirm the stat cards show non-zero totals (from Task 4's smoke-test view plus any browsing you've done), the days filter switches between 7/30/90, and the top-pages table lists paths with view counts.

- [ ] **Step 9: Commit**

```bash
git add src/routes.php src/Controllers/Admin/PageViewController.php \
        templates/admin/page-views/index.twig templates/layout/admin-base.twig \
        lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/uk.json lang/admin/sk.json
git commit -m "feat: add admin page-views report"
```

---

### Task 6: Full suite verification

**Files:** none (verification only)

**Interfaces:**
- Consumes: everything from Tasks 1–5.

- [ ] **Step 1: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests pass, zero failures/errors.

- [ ] **Step 2: Re-run the local smoke check**

```bash
curl -s -o /dev/null -w "CS homepage:  %{http_code}\n" http://localhost:8080/cs/
curl -s -o /dev/null -w "Admin login:  %{http_code}\n" http://localhost:8080/admin/login
curl -s -o /dev/null -w "Page views:   %{http_code}\n" http://localhost:8080/admin/page-views
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e "SELECT COUNT(*) FROM page_views;"
```
Expected: homepage `200`; admin login `200`; page-views `302` (redirect to login when unauthenticated in this shell session — routing intact, matches the pattern already established for other admin pages); the `page_views` count is greater than 0, confirming the middleware has been recording real requests throughout this session.

- [ ] **Step 3: Final commit if any stragglers remain**

```bash
git status
```
Expected: clean working tree (everything already committed task-by-task). If anything is outstanding, commit it with a clear message.
