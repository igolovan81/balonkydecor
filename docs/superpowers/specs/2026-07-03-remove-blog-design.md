# Remove Blog functionality completely

## Goal

Remove the Blog feature end-to-end: public blog pages, admin blog management, the
`BlogModel`, all routes, nav links, translations, sitemap entries, tests, and the
underlying `blog_posts`/`blog_post_t` database tables. After this ships, the site has
no blog surface anywhere — public, admin, or database.

## Decisions (confirmed with user)

- **Database tables are dropped**, not just orphaned. A new migration
  (`V009__drop_blog.sql`) drops `blog_post_t` (child, holds the FK) then
  `blog_posts` (parent).
- **Production data is backed up first.** Before the drop migration runs on
  production, dump `blog_posts` and `blog_post_t` via `mysqldump` to a local,
  timestamped `.sql` file, using the credentials already present in the repo's
  root `.env` (`MYSQL_HOST`, `MYSQL_DB_NAME`, `MYSQL_DB_ACCOUNT`,
  `MYSQL_DB_ACCOUNT_PASSWORD`). If WEDOS blocks external MySQL connections from
  this host, fall back to a manual phpMyAdmin export (same pattern as the
  documented WEDOS migration-tracker fallback in `/deploy`).
- Existing applied migrations (`V001__schema.sql`, `V002__demo_data.sql`) are
  **not edited** — migrations are append-only once applied. `V009` is a new
  migration on top, consistent with the project's existing `V003`–`V008`
  pattern.
- No blog image uploads exist (`www/assets/uploads/` only has `products/`), so
  there's no upload directory to clean up.

## Full removal footprint (confirmed by inspection)

**Database:**
- `blog_posts`, `blog_post_t` tables (`database/migrations/V001__schema.sql:134-156`)
- Demo data insert (`database/migrations/V002__demo_data.sql:137-144`) — left as-is (historical, ran before the drop)

**Backend:**
- `src/Models/BlogModel.php` — delete
- `src/Controllers/BlogController.php` — delete
- `src/Controllers/Admin/BlogController.php` — delete
- `src/routes.php`:
  - `use App\Controllers\BlogController;` (line 2)
  - `use App\Controllers\Admin\BlogController as AdminBlogController;` (line 14)
  - Admin block, lines 73-79 (`// Blog` comment + 6 routes)
  - Public routes, lines 141-142 (`GET /{lang}/blog`, `GET /{lang}/blog/{slug}`)

**Templates:**
- `templates/public/blog/index.twig`, `templates/public/blog/post.twig` — delete directory
- `templates/admin/blog/index.twig`, `templates/admin/blog/form.twig` — delete directory
- `templates/layout/base.twig:30` — remove `<a href="/{{ lang }}/blog">{{ t('nav.blog') }}</a>` (public nav)
- `templates/layout/admin-base.twig:20` — remove `<a href="/admin/blog">{{ t('nav.blog') }}</a>` (admin sidebar)

**Translations** — remove all `blog.*` keys plus `nav.blog`:
- Public: `lang/cs.json`, `lang/en.json`, `lang/sk.json`, `lang/ru.json`, `lang/uk.json` — 5 `blog.*` keys + 1 `nav.blog` key each (contiguous block near the top of each file, plus `nav.blog` alphabetically among the `nav.*` keys)
- Admin: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/sk.json`, `lang/admin/ru.json`, `lang/admin/uk.json` — 29 `blog.*` keys each (contiguous block at the top of each file)

**SEO / sitemap** (`src/Services/Sitemap.php`):
- Remove `use App\Models\BlogModel;`
- Remove `/blog` from the static `$paths` array
- Remove the `$blog = BlogModel::published(...)` block and its `foreach` loop

**Tests:**
- `tests/Unit/Models/BlogModelTest.php` — delete
- `tests/Unit/Services/SitemapTest.php`:
  - `setUpBeforeClass()` — remove the two `blog_posts` `INSERT IGNORE` lines
  - `test_paths_includes_static_pages` — remove `/blog` from the expected array
  - Delete `test_paths_includes_published_blog_post` and `test_paths_excludes_draft_blog_post` entirely

## Out of scope

- No special handling for old `/{lang}/blog...` URLs post-removal (no redirect,
  no custom 410). They'll hit the same pre-existing "unmatched route surfaces
  as an uncaught exception instead of a clean 404" behavior every other
  nonexistent URL on this site already has (a known, previously
  out-of-scope-dispositioned issue in `src/app.php`'s middleware ordering —
  not touched here).
- `robots.txt` (`SeoController::robots`) doesn't mention blog paths and needs
  no change.
- No admin dashboard changes — `DashboardController`/`dashboard.twig` don't
  reference blog stats.

## Verification

- Full PHPUnit suite green after all code/test changes (no blog references
  left in the suite).
- Manual smoke test: `/cs/blog` and `/admin/blog` both stop resolving to blog
  content (404-equivalent per the out-of-scope note above); nav no longer
  shows "Blog" in either public header or admin sidebar, in all 5 languages.
- After deploy, confirm the production backup dump file exists and has
  content before running `V009` via `/migrate.php`.
