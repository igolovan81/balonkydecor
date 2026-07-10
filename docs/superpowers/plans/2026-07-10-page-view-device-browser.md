# Page-View Device/Browser Classification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Classify each recorded page view by device type (desktop/mobile-android/mobile-ios/tablet-android/tablet-ios/other) and browser family (chrome/firefox/safari/edge/opera/samsung/ie/other), and surface a device-type breakdown on the existing admin page-views report. Browser is captured now; its report table is deferred.

**Architecture:** Two new columns on `page_views` (`device_type`, `browser`), populated by two new pure static classifier methods on `PageViewModel` (same category as the existing `anonymizeIp()` helper — regex/substring checks on the `User-Agent` string, no DB, no new dependency). The `app.php` recorder closure computes both classifications before calling `PageViewModel::record()`, which gains two new trailing optional parameters. `PageViewMiddleware` is untouched. A new `PageViewModel::deviceBreakdown()` method feeds a new "By Device" table on `/admin/page-views`.

**Tech Stack:** PHP 8 / Slim 4, PDO/MySQL 8, Twig 3, PHPUnit 11 (model tests need Docker MySQL).

## Global Constraints

- Migration file name: `database/migrations/V020__page_view_device_browser.sql` (never edit/delete already-applied migrations).
- No backfill of `device_type`/`browser` for existing rows — they keep the `'other'` column default.
- Browser classification order matters: check `edge` → `opera` → `samsung` → `firefox` → `chrome` → `safari` → `ie` → `other`, in that exact order, because Chromium-based browsers (Edge, Opera, Samsung Internet) all also contain `Chrome` and `Safari` tokens in their user-agent strings.
- `PageViewModel::record()`'s two new parameters must be optional with a `'other'` default, so no other call site breaks.
- `PageViewMiddleware` and its tests are **not** modified in this plan — classification happens entirely in `app.php`'s recorder closure and `PageViewModel`.
- All 5 admin lang files (`lang/admin/{cs,en,ru,uk,sk}.json`) must gain the same new keys, kept alphabetically sorted (existing convention).
- No "By Browser" report table in this plan — `browser` is written but not yet read anywhere in the UI.
- Run `php vendor/bin/phpunit` (whole suite) before considering any task done; must be fully green.
- Local dev DB (`docker compose up -d`) must be running for `PageViewModel` tests.

---

### Task 1: Migration — add `device_type` and `browser` columns

**Files:**
- Create: `database/migrations/V020__page_view_device_browser.sql`

**Interfaces:**
- Produces: columns `page_views.device_type` (VARCHAR(20) NOT NULL DEFAULT 'other'), `page_views.browser` (VARCHAR(20) NOT NULL DEFAULT 'other'). Read/written by `PageViewModel` (Task 2).

- [ ] **Step 1: Write the migration file**

```sql
ALTER TABLE `page_views`
  ADD COLUMN `device_type` varchar(20) NOT NULL DEFAULT 'other' AFTER `user_agent`,
  ADD COLUMN `browser`     varchar(20) NOT NULL DEFAULT 'other' AFTER `device_type`;
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
Expected: `{"applied": ["V020__page_view_device_browser"], "count": 1}`.

- [ ] **Step 4: Verify the schema**

```bash
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e "DESCRIBE page_views;"
```
Expected: `device_type` and `browser` columns appear after `user_agent`, both `varchar(20)`, `NOT NULL`, default `other`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/V020__page_view_device_browser.sql
git commit -m "feat: add device_type/browser columns to page_views"
```

---

### Task 2: `PageViewModel` classifiers + `record()`/`deviceBreakdown()` + tests

**Files:**
- Modify: `src/Models/PageViewModel.php`
- Modify: `tests/Unit/Models/PageViewModelTest.php`

**Interfaces:**
- Consumes: `page_views.device_type`/`page_views.browser` columns (Task 1).
- Produces: `PageViewModel::classifyDevice(?string $userAgent): string`, `PageViewModel::classifyBrowser(?string $userAgent): string`, `PageViewModel::record(string $path, string $lang, ?string $referrer, ?string $ipAnon, ?string $userAgent, string $deviceType = 'other', string $browser = 'other'): void` (signature change — two new trailing optional params), `PageViewModel::deviceBreakdown(string $from, string $to): array` (`[['device_type' => string, 'views' => int], ...]`). Consumed by Task 3 (`app.php`) and Task 4 (`PageViewController`).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Models/PageViewModelTest.php` (append these methods; keep all existing tests as-is):

```php
    public function test_classify_device_detects_ipad_as_tablet_ios(): void
    {
        $ua = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15';
        $this->assertSame('tablet-ios', PageViewModel::classifyDevice($ua));
    }

    public function test_classify_device_detects_iphone_as_mobile_ios(): void
    {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15';
        $this->assertSame('mobile-ios', PageViewModel::classifyDevice($ua));
    }

    public function test_classify_device_detects_android_phone_as_mobile_android(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 Mobile Safari/537.36';
        $this->assertSame('mobile-android', PageViewModel::classifyDevice($ua));
    }

    public function test_classify_device_detects_android_tablet_as_tablet_android(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 Safari/537.36';
        $this->assertSame('tablet-android', PageViewModel::classifyDevice($ua));
    }

    public function test_classify_device_detects_desktop(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0 Safari/537.36';
        $this->assertSame('desktop', PageViewModel::classifyDevice($ua));
    }

    public function test_classify_device_returns_other_for_empty_user_agent(): void
    {
        $this->assertSame('other', PageViewModel::classifyDevice(null));
        $this->assertSame('other', PageViewModel::classifyDevice(''));
    }

    public function test_classify_browser_detects_edge(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0 Safari/537.36 Edg/128.0';
        $this->assertSame('edge', PageViewModel::classifyBrowser($ua));
    }

    public function test_classify_browser_detects_opera(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0 Safari/537.36 OPR/114.0';
        $this->assertSame('opera', PageViewModel::classifyBrowser($ua));
    }

    public function test_classify_browser_detects_samsung_internet(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 13; SM-S911B) AppleWebKit/537.36 SamsungBrowser/24.0 Chrome/115.0 Mobile Safari/537.36';
        $this->assertSame('samsung', PageViewModel::classifyBrowser($ua));
    }

    public function test_classify_browser_detects_firefox(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0';
        $this->assertSame('firefox', PageViewModel::classifyBrowser($ua));
    }

    public function test_classify_browser_detects_chrome(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0 Safari/537.36';
        $this->assertSame('chrome', PageViewModel::classifyBrowser($ua));
    }

    public function test_classify_browser_detects_safari(): void
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15';
        $this->assertSame('safari', PageViewModel::classifyBrowser($ua));
    }

    public function test_classify_browser_detects_ie(): void
    {
        $ua = 'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0)';
        $this->assertSame('ie', PageViewModel::classifyBrowser($ua));
    }

    public function test_classify_browser_returns_other_for_empty_user_agent(): void
    {
        $this->assertSame('other', PageViewModel::classifyBrowser(null));
        $this->assertSame('other', PageViewModel::classifyBrowser(''));
    }

    public function test_record_persists_device_type_and_browser(): void
    {
        $path = '/cs/device-test-' . uniqid();
        PageViewModel::record($path, 'cs', null, '1.2.3.0', 'TestAgent/1.0', 'mobile-android', 'chrome');

        $stmt = Database::getConnection()->prepare('SELECT device_type, browser FROM page_views WHERE path = ?');
        $stmt->execute([$path]);
        $row = $stmt->fetch();

        $this->assertSame('mobile-android', $row['device_type']);
        $this->assertSame('chrome', $row['browser']);
    }

    public function test_device_breakdown_groups_and_orders_by_views(): void
    {
        $pdo   = Database::getConnection();
        $pathA = '/cs/breakdown-test-' . uniqid();
        $pathB = '/cs/breakdown-test-' . uniqid();
        foreach (range(1, 3) as $i) {
            $pdo->prepare("INSERT INTO page_views (path, lang, device_type, created_at) VALUES (?, 'cs', 'desktop', NOW())")->execute([$pathA]);
        }
        $pdo->prepare("INSERT INTO page_views (path, lang, device_type, created_at) VALUES (?, 'cs', 'mobile-android', NOW())")->execute([$pathB]);

        $from = date('Y-m-d H:i:s', strtotime('-1 minute'));
        $to   = date('Y-m-d H:i:s', strtotime('+1 minute'));

        $breakdown = PageViewModel::deviceBreakdown($from, $to);
        $types     = array_column($breakdown, 'device_type');

        $this->assertContains('desktop', $types);
        $this->assertContains('mobile-android', $types);
        $this->assertLessThanOrEqual(array_search('mobile-android', $types), array_search('desktop', $types));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Models/PageViewModelTest.php --testdox`
Expected: FAIL — `Call to undefined method App\Models\PageViewModel::classifyDevice()` (or similar) for the new tests; existing tests still pass unchanged.

- [ ] **Step 3: Implement the classifiers, updated `record()`, and `deviceBreakdown()`**

In `src/Models/PageViewModel.php`, replace the existing `record()` method and add three new methods:

```php
    public static function record(string $path, string $lang, ?string $referrer, ?string $ipAnon, ?string $userAgent, string $deviceType = 'other', string $browser = 'other'): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO page_views (path, lang, referrer, ip_anon, user_agent, device_type, browser) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$path, $lang, $referrer, $ipAnon, $userAgent, $deviceType, $browser]);
    }

    public static function classifyDevice(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return 'other';
        }
        if (stripos($userAgent, 'iPad') !== false) {
            return 'tablet-ios';
        }
        if (stripos($userAgent, 'iPhone') !== false || stripos($userAgent, 'iPod') !== false) {
            return 'mobile-ios';
        }
        if (stripos($userAgent, 'Android') !== false) {
            return stripos($userAgent, 'Mobile') !== false ? 'mobile-android' : 'tablet-android';
        }
        return 'desktop';
    }

    public static function classifyBrowser(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return 'other';
        }
        if (stripos($userAgent, 'Edg/') !== false || stripos($userAgent, 'Edge/') !== false) {
            return 'edge';
        }
        if (stripos($userAgent, 'OPR/') !== false || stripos($userAgent, 'Opera') !== false) {
            return 'opera';
        }
        if (stripos($userAgent, 'SamsungBrowser') !== false) {
            return 'samsung';
        }
        if (stripos($userAgent, 'Firefox') !== false) {
            return 'firefox';
        }
        if (stripos($userAgent, 'Chrome') !== false || stripos($userAgent, 'CriOS') !== false) {
            return 'chrome';
        }
        if (stripos($userAgent, 'Safari') !== false) {
            return 'safari';
        }
        if (stripos($userAgent, 'MSIE') !== false || stripos($userAgent, 'Trident') !== false) {
            return 'ie';
        }
        return 'other';
    }

    public static function deviceBreakdown(string $from, string $to): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT device_type, COUNT(*) AS views
             FROM page_views
             WHERE created_at BETWEEN :from AND :to
             GROUP BY device_type
             ORDER BY views DESC'
        );
        $stmt->execute(['from' => $from, 'to' => $to]);
        return $stmt->fetchAll();
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Models/PageViewModelTest.php --testdox`
Expected: PASS (all tests, existing + new).

- [ ] **Step 5: Commit**

```bash
git add src/Models/PageViewModel.php tests/Unit/Models/PageViewModelTest.php
git commit -m "feat: classify page views by device type and browser"
```

---

### Task 3: Wire classification into `app.php`

**Files:**
- Modify: `src/app.php`

**Interfaces:**
- Consumes: `PageViewModel::classifyDevice()`, `PageViewModel::classifyBrowser()`, updated `PageViewModel::record()` signature (Task 2).
- Produces: no new public interface — internal wiring only.

- [ ] **Step 1: Update the recorder closure**

Change:

```php
$app->add(new PageViewMiddleware($settings['languages'], function (
    string $path, string $lang, ?string $referrer, string $ip, ?string $userAgent
) {
    $ipAnon = \App\Models\PageViewModel::anonymizeIp($ip);
    \App\Models\PageViewModel::record($path, $lang, $referrer, $ipAnon, $userAgent);
    if (random_int(1, 100) === 1) {
        \App\Models\PageViewModel::pruneOlderThan(90);
    }
}));
```

to:

```php
$app->add(new PageViewMiddleware($settings['languages'], function (
    string $path, string $lang, ?string $referrer, string $ip, ?string $userAgent
) {
    $ipAnon     = \App\Models\PageViewModel::anonymizeIp($ip);
    $deviceType = \App\Models\PageViewModel::classifyDevice($userAgent);
    $browser    = \App\Models\PageViewModel::classifyBrowser($userAgent);
    \App\Models\PageViewModel::record($path, $lang, $referrer, $ipAnon, $userAgent, $deviceType, $browser);
    if (random_int(1, 100) === 1) {
        \App\Models\PageViewModel::pruneOlderThan(90);
    }
}));
```

- [ ] **Step 2: Verify the app still boots and classifies a real request**

```bash
php -l src/app.php
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/cs/shop
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e \
  "SELECT path, device_type, browser FROM page_views ORDER BY id DESC LIMIT 1;"
```
Expected: `php -l` reports no syntax errors; the curl request returns `200`; the SQL query shows the new row for `/cs/shop` with a non-default `device_type`/`browser` if your terminal's `curl` sends a real `User-Agent` header, or `'other'`/`'other'` if it doesn't (curl by default does send a `User-Agent: curl/...` header, which won't match any browser/device pattern — so `desktop`/`other` is the expected realistic result here, not a bug).

- [ ] **Step 3: Commit**

```bash
git add src/app.php
git commit -m "feat: wire device/browser classification into page view recording"
```

---

### Task 4: Admin report — "By Device" table + translations

**Files:**
- Modify: `src/Controllers/Admin/PageViewController.php`
- Modify: `templates/admin/page-views/index.twig`
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`

**Interfaces:**
- Consumes: `PageViewModel::deviceBreakdown()` (Task 2).

- [ ] **Step 1: Update `PageViewController::index()`**

In `src/Controllers/Admin/PageViewController.php`, change:

```php
        $summary = PageViewModel::summary($from, $to);
        $data    = PageViewModel::topPages($from, $to, $page, self::PER_PAGE);

        return $this->renderAdmin($request, $response, 'admin/page-views/index.twig', [
            'summary' => $summary,
            'rows'    => $data['rows'],
            'page'    => $page,
            'pages'   => $data['pages'],
            'days'    => $days,
        ]);
```

to:

```php
        $summary = PageViewModel::summary($from, $to);
        $data    = PageViewModel::topPages($from, $to, $page, self::PER_PAGE);
        $devices = PageViewModel::deviceBreakdown($from, $to);

        return $this->renderAdmin($request, $response, 'admin/page-views/index.twig', [
            'summary' => $summary,
            'rows'    => $data['rows'],
            'page'    => $page,
            'pages'   => $data['pages'],
            'days'    => $days,
            'devices' => $devices,
        ]);
```

- [ ] **Step 2: Add the "By Device" table to the template**

In `templates/admin/page-views/index.twig`, insert right before the final `{% endblock %}`:

```twig
<h2>{{ t('page_views.devices.title') }}</h2>
<table class="admin-table">
    <thead><tr><th>{{ t('page_views.devices.col.type') }}</th><th>{{ t('page_views.col.views') }}</th></tr></thead>
    <tbody>
    {% for d in devices %}
    <tr>
        <td>{{ t('page_views.devices.type.' ~ d.device_type) }}</td>
        <td>{{ d.views }}</td>
    </tr>
    {% else %}
    <tr><td colspan="2">{{ t('page_views.no_data') }}</td></tr>
    {% endfor %}
    </tbody>
</table>
{% endblock %}
```

(Only the new `<h2>` + table block is added — the existing `{% endblock %}` moves to the end of it, as shown.)

- [ ] **Step 3: Add translation keys to `lang/admin/cs.json`**

Insert this block between `"page_views.col.views"` and `"page_views.filter.30"` (alphabetical: `devices` sorts after `col`, before `filter`):

```json
  "page_views.devices.col.type": "Typ zařízení",
  "page_views.devices.title": "Podle zařízení",
  "page_views.devices.type.desktop": "Počítač",
  "page_views.devices.type.mobile-android": "Mobil (Android)",
  "page_views.devices.type.mobile-ios": "Mobil (iOS)",
  "page_views.devices.type.other": "Ostatní",
  "page_views.devices.type.tablet-android": "Tablet (Android)",
  "page_views.devices.type.tablet-ios": "Tablet (iOS)",
```

- [ ] **Step 4: Add the same keys (translated) to the other 4 files, at the same alphabetical position**

`lang/admin/en.json`:
```json
  "page_views.devices.col.type": "Device Type",
  "page_views.devices.title": "By Device",
  "page_views.devices.type.desktop": "Desktop",
  "page_views.devices.type.mobile-android": "Mobile (Android)",
  "page_views.devices.type.mobile-ios": "Mobile (iOS)",
  "page_views.devices.type.other": "Other",
  "page_views.devices.type.tablet-android": "Tablet (Android)",
  "page_views.devices.type.tablet-ios": "Tablet (iOS)",
```

`lang/admin/ru.json`:
```json
  "page_views.devices.col.type": "Тип устройства",
  "page_views.devices.title": "По устройствам",
  "page_views.devices.type.desktop": "Компьютер",
  "page_views.devices.type.mobile-android": "Мобильный (Android)",
  "page_views.devices.type.mobile-ios": "Мобильный (iOS)",
  "page_views.devices.type.other": "Другое",
  "page_views.devices.type.tablet-android": "Планшет (Android)",
  "page_views.devices.type.tablet-ios": "Планшет (iOS)",
```

`lang/admin/uk.json`:
```json
  "page_views.devices.col.type": "Тип пристрою",
  "page_views.devices.title": "За пристроями",
  "page_views.devices.type.desktop": "Комп'ютер",
  "page_views.devices.type.mobile-android": "Мобільний (Android)",
  "page_views.devices.type.mobile-ios": "Мобільний (iOS)",
  "page_views.devices.type.other": "Інше",
  "page_views.devices.type.tablet-android": "Планшет (Android)",
  "page_views.devices.type.tablet-ios": "Планшет (iOS)",
```

`lang/admin/sk.json`:
```json
  "page_views.devices.col.type": "Typ zariadenia",
  "page_views.devices.title": "Podľa zariadenia",
  "page_views.devices.type.desktop": "Počítač",
  "page_views.devices.type.mobile-android": "Mobil (Android)",
  "page_views.devices.type.mobile-ios": "Mobil (iOS)",
  "page_views.devices.type.other": "Iné",
  "page_views.devices.type.tablet-android": "Tablet (Android)",
  "page_views.devices.type.tablet-ios": "Tablet (iOS)",
```

- [ ] **Step 5: Verify all 5 files stay valid JSON with identical key sets**

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
Expected: `OK, all files have <N+8> identical keys` (8 new `page_views.devices.*` keys).

- [ ] **Step 6: Manually verify in the browser**

With the local server running and logged into `/admin/login`, visit `http://localhost:8080/admin/page-views` — confirm the new "By Device" table renders below the top-pages table, showing device-type labels and view counts for the selected date range.

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/Admin/PageViewController.php templates/admin/page-views/index.twig \
        lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/uk.json lang/admin/sk.json
git commit -m "feat: add device breakdown table to admin page-views report"
```

---

### Task 5: Full suite verification

**Files:** none (verification only)

**Interfaces:**
- Consumes: everything from Tasks 1–4.

- [ ] **Step 1: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests pass, zero failures/errors.

- [ ] **Step 2: Re-run the local smoke check**

```bash
curl -s -o /dev/null -w "CS homepage:  %{http_code}\n" http://localhost:8080/cs/
curl -s -o /dev/null -w "Admin login:  %{http_code}\n" http://localhost:8080/admin/login
curl -s -o /dev/null -w "Page views:   %{http_code}\n" http://localhost:8080/admin/page-views
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e \
  "SELECT device_type, browser, COUNT(*) FROM page_views GROUP BY device_type, browser;"
```
Expected: homepage `200`; admin login `200`; page-views `302` (unauthenticated redirect — routing intact); the grouped query shows at least one row (confirming classification data exists from this session's testing).

- [ ] **Step 3: Final commit if any stragglers remain**

```bash
git status
```
Expected: clean working tree (everything already committed task-by-task). If anything is outstanding, commit it with a clear message.
