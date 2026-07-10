# Site Versioning Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Track which exact commit is live on production and display it as `YYYY-MM-DD (shorthash)` in both the public site footer and the admin sidebar.

**Architecture:** `scripts/deploy.sh` writes a root-level `VERSION` file (git commit hash + today's date) immediately before the FTP mirror, so it ships alongside the deployed code. `VERSION` is gitignored — always regenerated fresh, never committed. A new pure service `App\Services\Version::current()` reads that file, with a git-based fallback for local dev (no `VERSION` file exists until the first deploy after this change). A `site_version()` Twig function (registered in `app.php` next to the existing `asset_v()`) exposes it to templates, which render it in the public footer and admin sidebar.

**Tech Stack:** PHP 8 / Slim 4, Twig 3, PHPUnit 11 (no DB needed for this feature — pure service), bash (`scripts/deploy.sh`).

## Global Constraints

- `VERSION` file lives at the project root, is **gitignored**, and is regenerated on every deploy — never hand-edited, never committed.
- `Version::current()` must never throw or emit warnings on a machine with no `VERSION` file and no git (e.g. some hypothetical stripped-down prod checkout) — always returns a string, worst case `'dev'`.
- No new CSS design tokens — public footer version text reuses `.site-footer`'s existing muted styling; admin sidebar version reuses the sidebar's existing hardcoded dark-theme palette (admin.css doesn't use CSS custom properties).
- Run `php vendor/bin/phpunit` (whole suite) before considering any task done; must be fully green. This feature's own tests don't need Docker MySQL running.

---

### Task 1: `Version` service + tests

**Files:**
- Create: `src/Services/Version.php`
- Test: `tests/Unit/Services/VersionTest.php`

**Interfaces:**
- Produces: `App\Services\Version::current(?string $rootDir = null): string`. Consumed by Task 2 (`app.php`'s `site_version()` Twig function).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Services/VersionTest.php`:

```php
<?php
namespace Tests\Unit\Services;

use App\Services\Version;
use PHPUnit\Framework\TestCase;

class VersionTest extends TestCase
{
    public function test_current_returns_version_file_contents_when_present(): void
    {
        $dir = sys_get_temp_dir() . '/version-test-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/VERSION', "2026-07-10 (abc1234)\n");

        $this->assertSame('2026-07-10 (abc1234)', Version::current($dir));
    }

    public function test_current_returns_dev_when_no_file_and_no_git(): void
    {
        $dir = sys_get_temp_dir() . '/version-test-' . uniqid();
        mkdir($dir);

        $this->assertSame('dev', Version::current($dir));
    }

    public function test_current_falls_back_to_git_hash_when_file_missing(): void
    {
        $dir = sys_get_temp_dir() . '/version-test-' . uniqid();
        mkdir($dir);
        exec('git init -q ' . escapeshellarg($dir));
        exec('git -C ' . escapeshellarg($dir) . ' -c user.email=test@example.com -c user.name=Test commit --allow-empty -q -m init');

        $result = Version::current($dir);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \([0-9a-f]{7,}\)$/', $result);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Services/VersionTest.php --testdox`
Expected: FAIL — `Class "App\Services\Version" not found`.

- [ ] **Step 3: Implement `src/Services/Version.php`**

```php
<?php
namespace App\Services;

class Version
{
    public static function current(?string $rootDir = null): string
    {
        $rootDir = $rootDir ?? __DIR__ . '/../..';
        $file    = rtrim($rootDir, '/') . '/VERSION';

        if (is_file($file)) {
            $contents = trim((string) file_get_contents($file));
            if ($contents !== '') {
                return $contents;
            }
        }

        return self::gitFallback($rootDir);
    }

    private static function gitFallback(string $rootDir): string
    {
        $hash = @shell_exec('git -C ' . escapeshellarg($rootDir) . ' rev-parse --short HEAD 2>/dev/null');
        $hash = $hash !== null ? trim($hash) : '';

        if ($hash === '') {
            return 'dev';
        }

        return date('Y-m-d') . ' (' . $hash . ')';
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Services/VersionTest.php --testdox`
Expected: PASS (all 3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/Version.php tests/Unit/Services/VersionTest.php
git commit -m "feat: add Version service reading deploy-time VERSION file"
```

---

### Task 2: Expose `site_version()` to Twig

**Files:**
- Modify: `src/app.php:24-27`

**Interfaces:**
- Consumes: `App\Services\Version::current(): string` (Task 1).
- Produces: Twig global function `site_version(): string`. Consumed by Task 4 and Task 5 templates.

- [ ] **Step 1: Register the function next to `asset_v`**

In `src/app.php`, change:

```php
        $twig->getEnvironment()->addFunction(new \Twig\TwigFunction('asset_v', function (string $path) {
            $full = __DIR__ . '/../www/' . ltrim($path, '/');
            return file_exists($full) ? filemtime($full) : time();
        }));
        return $twig;
```

to:

```php
        $twig->getEnvironment()->addFunction(new \Twig\TwigFunction('asset_v', function (string $path) {
            $full = __DIR__ . '/../www/' . ltrim($path, '/');
            return file_exists($full) ? filemtime($full) : time();
        }));
        $twig->getEnvironment()->addFunction(new \Twig\TwigFunction('site_version', function () {
            return \App\Services\Version::current();
        }));
        return $twig;
```

- [ ] **Step 2: Verify the app still boots**

Run: `php -l src/app.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/app.php
git commit -m "feat: expose site_version() Twig function"
```

---

### Task 3: `deploy.sh` generates `VERSION`; gitignore it

**Files:**
- Modify: `scripts/deploy.sh`
- Modify: `.gitignore`

**Interfaces:**
- Produces: root-level `VERSION` file, read by `Version::current()` (Task 1) once deployed.

- [ ] **Step 1: Add `VERSION` to `.gitignore`**

Append to `.gitignore` (after the existing `/backups/` line):

```
/VERSION
```

- [ ] **Step 2: Generate `VERSION` in `scripts/deploy.sh` before the mirror step**

In `scripts/deploy.sh`, change:

```bash
echo "Deploying to $FTP_HOST ..."

lftp -u "$FTP_USER","$FTP_PASS" "ftp://$FTP_HOST" <<EOF
```

to:

```bash
echo "Deploying to $FTP_HOST ..."

GIT_HASH="$(git -C "$LOCAL_DIR" rev-parse --short HEAD)"
echo "$(date +%Y-%m-%d) (${GIT_HASH})" > "$LOCAL_DIR/VERSION"

lftp -u "$FTP_USER","$FTP_PASS" "ftp://$FTP_HOST" <<EOF
```

- [ ] **Step 3: Verify the script is still syntactically valid**

Run: `bash -n scripts/deploy.sh`
Expected: no output (no syntax errors).

- [ ] **Step 4: Commit**

```bash
git add scripts/deploy.sh .gitignore
git commit -m "feat: generate VERSION file at deploy time"
```

---

### Task 4: Public footer — display version

**Files:**
- Modify: `templates/layout/base.twig:55`
- Modify: `www/assets/css/style.css` (near line 87, the `.site-footer` rule)

**Interfaces:**
- Consumes: `site_version()` Twig function (Task 2).

- [ ] **Step 1: Update the copyright line**

In `templates/layout/base.twig`, change:

```twig
            <p>&copy; {{ "now"|date("Y") }} {{ t('site.name') }}</p>
```

to:

```twig
            <p>&copy; {{ "now"|date("Y") }} {{ t('site.name') }} <span class="site-version">v{{ site_version() }}</span></p>
```

- [ ] **Step 2: Add a small style for the version span**

In `www/assets/css/style.css`, right after the existing `.site-footer` rule:

```css
.site-footer { border-top: 1px solid var(--border); padding: 2rem 0; text-align: center; color: var(--muted); font-family: var(--ui-font); font-size: .85rem; margin-top: 4rem; }
.site-version { font-size: .75rem; opacity: .7; }
```

(Only the new `.site-version` line is added — `.site-footer` itself is unchanged, shown for anchoring.)

- [ ] **Step 3: Manually verify in the browser**

With the local server running (`php -S localhost:8080 -t www` if not already up), visit `http://localhost:8080/cs/` and confirm the footer now reads `© 2026 BalonkyDecor v<date> (<hash>)` (the hash will be the local git HEAD's short hash, since no `VERSION` file exists locally until a real deploy runs).

- [ ] **Step 4: Commit**

```bash
git add templates/layout/base.twig www/assets/css/style.css
git commit -m "feat: show site version in public footer"
```

---

### Task 5: Admin sidebar — display version

**Files:**
- Modify: `templates/layout/admin-base.twig:50-53`
- Modify: `www/assets/css/admin.css` (near line 127, the `.admin-logout` rules)

**Interfaces:**
- Consumes: `site_version()` Twig function (Task 2).

- [ ] **Step 1: Add the version line after the logout link**

In `templates/layout/admin-base.twig`, change:

```twig
            <a href="/admin/logout" class="admin-logout">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                {{ t('nav.logout') }}
            </a>
        </div>
```

to:

```twig
            <a href="/admin/logout" class="admin-logout">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                {{ t('nav.logout') }}
            </a>
            <div class="admin-version">v{{ site_version() }}</div>
        </div>
```

- [ ] **Step 2: Add the `.admin-version` style**

In `www/assets/css/admin.css`, right after the existing `.admin-logout:hover svg { opacity: 1; }` line:

```css
.admin-logout:hover svg { opacity: 1; }

.admin-version { margin-top: 0.6rem; color: #6a6a94; font-size: 0.68rem; }
```

- [ ] **Step 3: Manually verify in the browser**

With the local server running, log into `/admin/login` and confirm the sidebar footer (below the logout link) shows `v<date> (<hash>)` in small muted text.

- [ ] **Step 4: Commit**

```bash
git add templates/layout/admin-base.twig www/assets/css/admin.css
git commit -m "feat: show site version in admin sidebar"
```

---

### Task 6: Full suite verification

**Files:** none (verification only)

**Interfaces:**
- Consumes: everything from Tasks 1–5.

- [ ] **Step 1: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests pass, zero failures/errors (this feature adds 3 new tests to the existing count, no DB required for them).

- [ ] **Step 2: Re-run the local smoke check**

```bash
curl -s http://localhost:8080/cs/ | grep -o 'site-version">[^<]*'
```
Expected: prints `site-version">v2026-... (........)` (some git short-hash), confirming the version renders on the public homepage.

- [ ] **Step 3: Final commit if any stragglers remain**

```bash
git status
```
Expected: clean working tree (everything already committed task-by-task). If anything is outstanding, commit it with a clear message.
