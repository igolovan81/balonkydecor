# Remove Blog Functionality Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the Blog feature end-to-end — public pages, admin management, model, routes, nav links, translations, sitemap entries, tests, and the underlying `blog_posts`/`blog_post_t` database tables.

**Architecture:** Mechanical removal across layers (DB migration, backend, templates, translations, SEO, tests), ordered so the full test suite stays green after every task's commit. A production data backup is taken first, via a temporary token-protected HTTP dump endpoint (WEDOS blocks direct external MySQL connections, so `mysqldump` from a local machine isn't possible). The actual `DROP TABLE` migration is written and validated in this plan but is *applied* to production later, as part of the normal `/deploy` flow (which auto-detects and runs new migration files) — not as one of this plan's numbered tasks.

**Tech Stack:** Slim 4 (PHP), Twig 3, PDO/MySQL, PHPUnit 11, Docker (local MySQL + throwaway migration-validation container).

## Global Constraints

- Existing applied migrations (`V001__schema.sql`, `V002__demo_data.sql`) are **not edited** — migrations are append-only. The drop is a new `V009__drop_blog.sql`.
- No blog image uploads exist (`www/assets/uploads/` only has `products/`) — nothing to clean up there.
- No special handling for old `/{lang}/blog...` URLs post-removal (no redirect, no custom 410) — out of scope per the design doc.
- `robots.txt` and the admin dashboard need no changes — neither references blog.
- The full PHPUnit suite must pass after every task's commit (each task is an independent, reviewable gate).

---

### Task 1: Back up production blog data

**Files:**
- Create (temporary, not committed): `www/backup-blog.php`
- Modify: `.gitignore` (add `/backups/`)

**Interfaces:**
- Produces: a local JSON file under `backups/` containing every row of `blog_posts` and `blog_post_t` from production, to be kept as a safety net before Task 2's migration is ever applied to production.

- [ ] **Step 1: Create the temporary backup endpoint**

Create `www/backup-blog.php`, modeled directly on the existing `www/migrate.php` (same settings-loading, same token check, same `db_admin` preference):

```php
<?php
// TEMPORARY, one-off token-protected dump for blog_posts/blog_post_t.
// Usage: GET /backup-blog.php?token=YOUR_TOKEN
// Returns JSON: {"blog_posts": [...], "blog_post_t": [...]}
// Delete this file (and redeploy) immediately after use.

$settings   = require __DIR__ . '/../config/settings.php';
$prodConfig = __DIR__ . '/../config/settings.prod.php';
if (file_exists($prodConfig)) {
    $settings = array_replace_recursive($settings, require $prodConfig);
}

$token = $settings['migrate_token'] ?? '';
if ($token === '' || ($_GET['token'] ?? '') !== $token) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

try {
    $db  = $settings['db_admin'] ?? $settings['db'];
    $pdo = new PDO(
        "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
        $db['user'], $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $result = [
        'blog_posts'  => $pdo->query('SELECT * FROM blog_posts')->fetchAll(),
        'blog_post_t' => $pdo->query('SELECT * FROM blog_post_t')->fetchAll(),
    ];

    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
```

- [ ] **Step 2: Add a gitignored backups directory**

Add this line to `.gitignore` (the repo root file, currently 8 lines):

```
/backups/
```

- [ ] **Step 3: Deploy the temporary endpoint**

From the repo root:

```bash
mkdir -p backups
export FTP_PASS=$(grep '^FTP_PASS=' .env | cut -d= -f2-)
FTP_PASS="$FTP_PASS" ./scripts/deploy.sh
```

Expected: deploy completes, uploading `www/backup-blog.php` (everything else is unchanged from the last deploy).

- [ ] **Step 4: Fetch the backup and save it locally**

```bash
TOKEN=$(php -r "echo (require 'config/settings.php')['migrate_token'];")
STAMP=$(date +%Y%m%d-%H%M%S)
curl -s "https://balonkydecor.cz/backup-blog.php?token=${TOKEN}" -o "backups/blog-backup-${STAMP}.json"
python3 -m json.tool "backups/blog-backup-${STAMP}.json" > /dev/null && echo "valid JSON"
php -r "\$d = json_decode(file_get_contents(glob('backups/blog-backup-*.json')[0]), true); echo 'blog_posts rows: ' . count(\$d['blog_posts']) . \"\n\" . 'blog_post_t rows: ' . count(\$d['blog_post_t']) . \"\n\";"
```

Expected: `valid JSON` printed, and both row counts printed (0 or more — either is fine, this just confirms the endpoint worked and returned the real table contents, not an error).

- [ ] **Step 5: Remove the temporary endpoint and redeploy**

```bash
rm www/backup-blog.php
export FTP_PASS=$(grep '^FTP_PASS=' .env | cut -d= -f2-)
FTP_PASS="$FTP_PASS" ./scripts/deploy.sh
curl -s -o /dev/null -w "backup-blog.php status after removal: %{http_code}\n" https://balonkydecor.cz/backup-blog.php
```

Expected: deploy removes the file from production (mirror `--delete` behavior); the final curl returns `404` (or the site's existing unmatched-route behavior — anything other than a working JSON dump, confirming the endpoint is gone).

- [ ] **Step 6: Commit the .gitignore change only**

`www/backup-blog.php` was never committed (created, used, and deleted within this task) — only the `.gitignore` addition is a real repo change.

```bash
git add .gitignore
git commit -m "chore: gitignore /backups/ for local DB backup dumps"
```

---

### Task 2: Write and validate the blog-table drop migration

**Files:**
- Create: `database/migrations/V009__drop_blog.sql`

**Interfaces:**
- Consumes: `App\Services\Migrator` (existing, unchanged) — used only to validate this migration runs cleanly through the full chain.
- Produces: `database/migrations/V009__drop_blog.sql`, which the `/deploy` flow will apply to production automatically once this branch is deployed (via `www/migrate.php`, outside this plan's scope).

- [ ] **Step 1: Write the migration**

Create `database/migrations/V009__drop_blog.sql`:

```sql
DROP TABLE blog_post_t;
DROP TABLE blog_posts;
```

(Child table first — `blog_post_t.post_id` has a `FOREIGN KEY ... REFERENCES blog_posts(id)`.)

- [ ] **Step 2: Validate the full migration chain against a throwaway container**

This runs the *entire* `database/migrations/` chain (V001 through V009) against a disposable MySQL instance — not the shared local dev DB — via the project's own `Migrator` class, confirming V009 applies cleanly on top of everything else and actually removes both tables.

```bash
docker run -d --name blog-migration-check \
  -e MYSQL_ROOT_PASSWORD=test -e MYSQL_DATABASE=migrationcheck \
  -p 3307:3306 mysql:8.4
for i in $(seq 1 30); do
  docker exec blog-migration-check mysqladmin ping -uroot -ptest --silent 2>/dev/null && break
  sleep 1
done
cat > /tmp/migration-check.php <<'EOF'
<?php
require __DIR__ . '/vendor/autoload.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=migrationcheck;charset=utf8mb4', 'root', 'test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$migrator = new App\Services\Migrator($pdo, __DIR__ . '/database/migrations');
$applied = $migrator->run();
echo "Applied: " . implode(', ', $applied) . "\n";
$remaining = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='migrationcheck' AND table_name IN ('blog_posts','blog_post_t')")->fetchColumn();
echo "blog tables remaining: {$remaining}\n";
EOF
php /tmp/migration-check.php
docker rm -f blog-migration-check
rm /tmp/migration-check.php
```

Expected: `Applied:` lists `V001__schema` through `V009__drop_blog` (9 versions, comma-separated), and `blog tables remaining: 0`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/V009__drop_blog.sql
git commit -m "feat: add migration to drop blog_posts/blog_post_t tables"
```

---

### Task 3: Remove backend code (model, controllers, routes, model test)

**Files:**
- Delete: `src/Models/BlogModel.php`
- Delete: `tests/Unit/Models/BlogModelTest.php`
- Delete: `src/Controllers/BlogController.php`
- Delete: `src/Controllers/Admin/BlogController.php`
- Modify: `src/routes.php`
- Modify: `src/Services/Sitemap.php` (see Step 4 — pulled forward from a later concern to keep the suite green)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing later tasks depend on. `src/Services/Sitemap.php` still calls `BlogModel::published(...)` before this task runs; deleting `BlogModel.php` in Step 1 would leave that call referencing a nonexistent class, so this task also strips the blog reference out of `Sitemap.php` (Step 4) in the same commit. Task 6 then only has to fix `SitemapTest.php`'s assertions, not `Sitemap.php` itself.

- [ ] **Step 1: Delete the model and its test together**

Deleting `BlogModel.php` without deleting its test in the same commit would break the suite (the test class references `App\Models\BlogModel`, which wouldn't autoload). Delete both:

```bash
rm src/Models/BlogModel.php
rm tests/Unit/Models/BlogModelTest.php
```

- [ ] **Step 2: Delete both controllers**

```bash
rm src/Controllers/BlogController.php
rm src/Controllers/Admin/BlogController.php
```

- [ ] **Step 3: Remove blog routes and use-statements from `src/routes.php`**

Remove line 2:
```php
use App\Controllers\BlogController;
```

Remove line 14:
```php
use App\Controllers\Admin\BlogController as AdminBlogController;
```

Remove the admin block (currently lines 73-79):
```php
    // Blog
    $group->get('/blog',                     AdminBlogController::class . ':index');
    $group->get('/blog/new',                 AdminBlogController::class . ':createForm');
    $group->post('/blog/new',                AdminBlogController::class . ':createSubmit');
    $group->get('/blog/{id:[0-9]+}/edit',    AdminBlogController::class . ':editForm');
    $group->post('/blog/{id:[0-9]+}/edit',   AdminBlogController::class . ':editSubmit');
    $group->post('/blog/{id:[0-9]+}/delete', AdminBlogController::class . ':delete');

```
(Remove the trailing blank line after it too, so `// Pages` directly follows `// Gallery`'s block with a single blank line separator, matching the existing style between other route groups.)

Remove the two public routes (currently lines 141-142):
```php
$app->get('/{lang}/blog',             BlogController::class    . ':index');
$app->get('/{lang}/blog/{slug}',      BlogController::class    . ':post');
```

- [ ] **Step 4: Verify `Sitemap.php`'s dangling reference doesn't break the suite yet**

`src/Services/Sitemap.php` still calls `BlogModel::published(...)` at this point (it's fixed in Task 6, not here) — deleting `BlogModel.php` in this task makes that a call to a nonexistent class. Confirm this is inert until Task 6 by checking whether any currently-passing test exercises `Sitemap::paths()` or `Sitemap::entries()`:

```bash
grep -rn "Sitemap::" tests/
```

`tests/Unit/Services/SitemapTest.php` does call `Sitemap::paths()`/`Sitemap::entries()` — so the full suite **will fail** after this task's commit, specifically in `SitemapTest`, with a `Class "App\Models\BlogModel" not found` fatal error. This is expected and acceptable *within this task's own verification* only if the task is scoped correctly — but per the "suite must stay green after every task" constraint, **this task must not leave the suite red**.

Resolve this by pulling `Sitemap.php`'s blog reference removal into this task instead of leaving it for Task 6: after Step 3, also apply Task 6's `Sitemap.php` edit now (Task 6 will then only touch `SitemapTest.php`). Edit `src/Services/Sitemap.php`:

Remove:
```php
use App\Models\BlogModel;
```

Change:
```php
        $paths = ['/', '/shop', '/services', '/services/archive', '/blog', '/contact', '/shipping-payment'];
```
to:
```php
        $paths = ['/', '/shop', '/services', '/services/archive', '/contact', '/shipping-payment'];
```

Remove:
```php
        $blog = BlogModel::published(Seo::DEFAULT_LANG, 1, 1000);
        foreach ($blog['posts'] as $post) {
            $paths[] = '/blog/' . $post['slug'];
        }
```

- [ ] **Step 5: Run the full suite**

```bash
docker compose up -d
php vendor/bin/phpunit --testdox 2>&1 | tail -40
```

Expected: exactly 2 `SitemapTest` failures at this point — `test_paths_includes_static_pages` (still expects `/blog` in the array) and `test_paths_includes_published_blog_post` (still expects a `/blog/...` path to exist). `test_paths_excludes_draft_blog_post` will *pass* (it's a negative assertion — `/blog/sitemap-test-draft` is absent, which is trivially true now that no `/blog/*` paths exist at all). All of these are `SitemapTest.php`'s own outdated assertions, fixed in Task 6. All other tests in the suite pass. Confirm no *fatal errors* (no "Class not found") — only the 2 expected `SitemapTest` assertion failures.

- [ ] **Step 6: Commit**

```bash
git add src/Models/BlogModel.php tests/Unit/Models/BlogModelTest.php \
        src/Controllers/BlogController.php src/Controllers/Admin/BlogController.php \
        src/routes.php src/Services/Sitemap.php
git commit -m "feat: remove Blog backend code, routes, and Sitemap blog wiring"
```

Note: this commit intentionally leaves `SitemapTest.php` red (2 failing assertions, 0 fatal errors) — Task 6 fixes the test file. Say so explicitly in the task report so the reviewer isn't surprised by red tests in this diff.

---

### Task 4: Remove Blog templates and nav links

**Files:**
- Delete: `templates/public/blog/index.twig`
- Delete: `templates/public/blog/post.twig`
- Delete: `templates/admin/blog/index.twig`
- Delete: `templates/admin/blog/form.twig`
- Modify: `templates/layout/base.twig:30`
- Modify: `templates/layout/admin-base.twig:20`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing later tasks depend on.

- [ ] **Step 1: Delete the template directories**

```bash
rm -rf templates/public/blog templates/admin/blog
```

- [ ] **Step 2: Remove the public nav link**

In `templates/layout/base.twig`, remove line 30:
```twig
                <a href="/{{ lang }}/blog">{{ t('nav.blog') }}</a>
```
(It currently sits between the closing `</div>` of the Services dropdown and the opening `<div class="nav-item-dropdown">` of the Info dropdown — after removal those two blocks are adjacent, separated by nothing but the existing indentation/newlines of the surrounding `<nav>`.)

- [ ] **Step 3: Remove the admin nav link**

In `templates/layout/admin-base.twig`, remove line 20:
```twig
            <a href="/admin/blog">{{ t('nav.blog') }}</a>
```

- [ ] **Step 4: Run the full suite**

```bash
php vendor/bin/phpunit --testdox 2>&1 | tail -40
```

Expected: same state as end of Task 3 (`SitemapTest` still has its 2 known, not-yet-fixed failures — `test_paths_includes_static_pages` and `test_paths_includes_published_blog_post`; nothing new broken — this task touches no PHP logic, only Twig templates with no template-rendering tests in this suite).

- [ ] **Step 5: Manually verify the templates are gone from rendered output**

```bash
docker compose up -d
php -S localhost:8080 -t www > /tmp/php-blog-removal.log 2>&1 &
echo $! > /tmp/php-blog-removal.pid
for i in $(seq 1 15); do curl -sf http://localhost:8080/cs/ >/dev/null && break; sleep 1; done
curl -s http://localhost:8080/cs/ | grep -c "nav.blog\|/cs/blog" || true
curl -s -o /dev/null -w "public /cs/blog status: %{http_code}\n" http://localhost:8080/cs/blog
kill "$(cat /tmp/php-blog-removal.pid)" 2>/dev/null
```

Expected: the `grep -c` count is `0` (no "Blog" link markup left in the rendered nav — the two dropdown blocks now sit directly adjacent). The `/cs/blog` status will likely be `500` due to this project's pre-existing unmatched-route/middleware-ordering issue (documented in the design doc's Out of Scope section, already dispositioned by the project owner in earlier work) — that is expected and not a defect introduced by this task; do not attempt to fix it.

- [ ] **Step 6: Commit**

```bash
git add templates/layout/base.twig templates/layout/admin-base.twig
git rm -r templates/public/blog templates/admin/blog
git commit -m "feat: remove Blog templates and nav links"
```

---

### Task 5: Remove Blog translation keys from all 10 language files

**Files:**
- Modify: `lang/cs.json`, `lang/en.json`, `lang/sk.json`, `lang/ru.json`, `lang/uk.json`
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/sk.json`, `lang/admin/ru.json`, `lang/admin/uk.json`

**Interfaces:**
- Consumes: nothing from earlier tasks (Task 4 already removed the only `t('nav.blog')`/`t('blog.*')` template usages, so removing the underlying keys now is safe — no template will render a raw key fallback).
- Produces: nothing later tasks depend on.

- [ ] **Step 1: Remove blog keys from the 5 public lang files**

Each of `lang/cs.json`, `lang/en.json`, `lang/sk.json`, `lang/ru.json`, `lang/uk.json` currently has a `blog.*` block (`blog.back`, `blog.no_posts`, `blog.read_more`, `blog.title` — 4 keys) near the top of the file, and a `nav.blog` key inside the `nav.*` block. Remove all 5 keys from each file:

```bash
for f in lang/cs.json lang/en.json lang/sk.json lang/ru.json lang/uk.json; do
  sed -i '' -e '/^  "blog\./d' -e '/^  "nav\.blog"/d' "$f"
done
```

- [ ] **Step 2: Remove blog keys from the 5 admin lang files**

Each of `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/sk.json`, `lang/admin/ru.json`, `lang/admin/uk.json` currently has a 29-key `blog.*` block at the top of the file (`blog.add` through `blog.title` — everything from admin post-list columns to the post form labels) plus a separate `nav.blog` key inside the `nav.*` block. Remove all 30 keys from each file with the same pattern:

```bash
for f in lang/admin/cs.json lang/admin/en.json lang/admin/sk.json lang/admin/ru.json lang/admin/uk.json; do
  sed -i '' -e '/^  "blog\./d' -e '/^  "nav\.blog"/d' "$f"
done
```

- [ ] **Step 3: Verify all 10 files are still valid JSON with no blog keys left**

```bash
for f in lang/cs.json lang/en.json lang/sk.json lang/ru.json lang/uk.json \
         lang/admin/cs.json lang/admin/en.json lang/admin/sk.json lang/admin/ru.json lang/admin/uk.json; do
  php -r "json_decode(file_get_contents('$f'), true) !== null || exit(1);" || echo "INVALID: $f"
  grep -qi '"blog\.\|"nav\.blog"' "$f" && echo "STILL HAS BLOG KEYS: $f"
done
echo "done"
```

Expected: no `INVALID` or `STILL HAS BLOG KEYS` lines, ends with `done`.

- [ ] **Step 4: Verify the 5 public files still have identical key sets to each other (and same for the 5 admin files)**

```bash
php -r '
$files = ["cs","en","sk","ru","uk"];
$base = array_keys(json_decode(file_get_contents("lang/cs.json"), true));
sort($base);
foreach ($files as $f) {
    $keys = array_keys(json_decode(file_get_contents("lang/$f.json"), true));
    sort($keys);
    if ($keys !== $base) { echo "PUBLIC MISMATCH in $f\n"; }
}
$baseAdmin = array_keys(json_decode(file_get_contents("lang/admin/cs.json"), true));
sort($baseAdmin);
foreach ($files as $f) {
    $keys = array_keys(json_decode(file_get_contents("lang/admin/$f.json"), true));
    sort($keys);
    if ($keys !== $baseAdmin) { echo "ADMIN MISMATCH in $f\n"; }
}
echo "parity check done\n";
'
```

Expected: no `MISMATCH` lines, ends with `parity check done`.

- [ ] **Step 5: Run the full suite**

```bash
php vendor/bin/phpunit --testdox 2>&1 | tail -40
```

Expected: same as end of Task 4 — `SitemapTest`'s 2 known failures (`test_paths_includes_static_pages`, `test_paths_includes_published_blog_post`) remain, fixed next in Task 6. Nothing else changed (no test suite coverage touches raw lang JSON key presence).

- [ ] **Step 6: Commit**

```bash
git add lang/cs.json lang/en.json lang/sk.json lang/ru.json lang/uk.json \
        lang/admin/cs.json lang/admin/en.json lang/admin/sk.json lang/admin/ru.json lang/admin/uk.json
git commit -m "feat: remove blog translation keys from all 10 language files"
```

---

### Task 6: Fix `SitemapTest.php` (drop the last 2 known failures)

**Files:**
- Modify: `tests/Unit/Services/SitemapTest.php`

**Interfaces:**
- Consumes: `Sitemap::paths()` / `Sitemap::entries()` as already fixed in Task 3 (no `/blog` path, no `BlogModel` call).
- Produces: nothing later tasks depend on — this is the last task before final verification.

- [ ] **Step 1: Remove the blog fixture inserts from `setUpBeforeClass()`**

In `tests/Unit/Services/SitemapTest.php`, remove these two lines (currently 20-21, immediately after the `gallery_albums` insert):
```php
        $pdo->exec("INSERT IGNORE INTO blog_posts (slug, status, published_at) VALUES ('sitemap-test-post', 'published', NOW())");
        $pdo->exec("INSERT IGNORE INTO blog_posts (slug, status) VALUES ('sitemap-test-draft', 'draft')");
```

- [ ] **Step 2: Remove `/blog` from the static-pages assertion**

Change:
```php
    public function test_paths_includes_static_pages(): void
    {
        $paths = Sitemap::paths();
        foreach (['/', '/shop', '/services', '/services/archive', '/blog', '/contact', '/shipping-payment'] as $expected) {
            $this->assertContains($expected, $paths);
        }
    }
```
to:
```php
    public function test_paths_includes_static_pages(): void
    {
        $paths = Sitemap::paths();
        foreach (['/', '/shop', '/services', '/services/archive', '/contact', '/shipping-payment'] as $expected) {
            $this->assertContains($expected, $paths);
        }
    }
```

- [ ] **Step 3: Delete the two blog-specific test methods**

Delete these two methods entirely:
```php
    public function test_paths_includes_published_blog_post(): void
    {
        $this->assertContains('/blog/sitemap-test-post', Sitemap::paths());
    }

    public function test_paths_excludes_draft_blog_post(): void
    {
        $this->assertNotContains('/blog/sitemap-test-draft', Sitemap::paths());
    }
```

- [ ] **Step 4: Run the focused test file**

```bash
docker compose up -d
php vendor/bin/phpunit tests/Unit/Services/SitemapTest.php --testdox
```

Expected: all remaining `SitemapTest` tests pass (6 tests: `test_paths_includes_static_pages`, `test_paths_includes_active_product`, `test_paths_excludes_inactive_product`, `test_paths_includes_gallery_album`, `test_entries_produces_one_row_per_path_per_language`, `test_entries_include_all_language_alternates`).

- [ ] **Step 5: Run the full suite**

```bash
php vendor/bin/phpunit --testdox 2>&1 | tail -20
```

Expected: 100% green, zero failures, zero errors. This is the first fully-green run since Task 3.

- [ ] **Step 6: Commit**

```bash
git add tests/Unit/Services/SitemapTest.php
git commit -m "test: remove blog assertions and fixtures from SitemapTest"
```

---

## Final Verification

- [ ] **Step 1: Run the full suite one more time**

```bash
docker compose up -d
php vendor/bin/phpunit --testdox 2>&1 | tail -20
```

Expected: all tests pass, zero blog references remain anywhere in the suite.

- [ ] **Step 2: Grep the whole repo for any remaining blog reference**

```bash
grep -rli "blog" src/ templates/ lang/ database/migrations/ tests/ --include="*.php" --include="*.twig" --include="*.json" --include="*.sql" | grep -v "V001__schema.sql\|V002__demo_data.sql\|V009__drop_blog.sql"
```

Expected: no output. (The three excluded migration files are expected to still mention "blog" — `V001`/`V002` are historical and never edited; `V009` is the drop migration itself, whose filename and `DROP TABLE blog_...` statements legitimately say "blog".)

- [ ] **Step 3: Manual smoke test — all 5 languages, public and admin nav**

```bash
php -S localhost:8080 -t www > /tmp/php-final.log 2>&1 &
echo $! > /tmp/php-final.pid
for i in $(seq 1 15); do curl -sf http://localhost:8080/cs/ >/dev/null && break; sleep 1; done
for l in cs en sk ru uk; do
  echo "== $l public nav =="
  curl -s "http://localhost:8080/$l/" | grep -io "blog" || echo "  (none found, good)"
done
kill "$(cat /tmp/php-final.pid)" 2>/dev/null
```

Expected: "(none found, good)" for every language — the word "blog" does not appear anywhere in the rendered public homepage.

- [ ] **Step 4: Confirm the backup file from Task 1 still exists**

```bash
ls -la backups/
```

Expected: at least one `blog-backup-*.json` file present, non-empty (this is a local, gitignored safety net — it stays on this machine, not committed).
