# Page-view tracking — design

Date: 2026-07-10

## Problem

There is no visibility into which pages visitors actually look at, how many
visits the site gets, or where traffic comes from. The site has no analytics
of any kind today.

## Scope

Public pages only (`/{lang}/...`) — not admin panel navigation. Admin clicks
around the panel are staff usage, not visitor traffic, and mixing the two
would make the report meaningless.

## Privacy / GDPR

A raw IP address is personal data under GDPR. To avoid needing a
privacy-policy update, consent banner, or legal-basis discussion for this
feature, IPs are **anonymized before being stored**: the last IPv4 octet (or
last 16 bits of an IPv6 address) is zeroed, e.g. `89.24.130.57` →
`89.24.130.0`. This is the same approach Google Analytics' `anonymizeIp`
option uses. It's still useful for a rough unique-visitor estimate and
deduplication, but is no longer precise enough to identify an individual.

Records are retained for a rolling 90-day window (see "Retention" below),
consistent with GDPR's data-minimization principle — analytics data isn't
kept indefinitely.

## Data model — `database/migrations/V019__page_views.sql`

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

## Capturing views — `src/Middleware/PageViewMiddleware.php`

Registered globally in `app.php`, alongside `LangMiddleware`. On every
request:

- Only acts on `GET` requests.
- Extracts the first path segment; only proceeds if it's one of the
  supported language codes (`cs`, `sk`, `en`, `uk`, `ru`) — this naturally
  excludes `/admin/*`, `/payment/*`, `robots.txt`, `sitemap.xml` without an
  explicit exclude list, and static assets never reach the PHP router at
  all (served directly by Apache).
- Reads `REMOTE_ADDR`, the `Referer` header, and the `User-Agent` header
  from the request.

To keep the middleware unit-testable without a database — matching the
existing `LangMiddlewareTest` pattern (fake `RequestHandlerInterface`, no
DB) — it does **not** call `PageViewModel` directly. Instead it takes an
injected recorder closure in its constructor:

```php
new PageViewMiddleware(
    $settings['languages'],
    function (string $path, string $lang, ?string $referrer, string $ip, ?string $userAgent) {
        $ipAnon = \App\Models\PageViewModel::anonymizeIp($ip);
        \App\Models\PageViewModel::record($path, $lang, $referrer, $ipAnon, $userAgent);
        if (random_int(1, 100) === 1) {
            \App\Models\PageViewModel::pruneOlderThan(90);
        }
    }
)
```

Tests inject a spy closure instead and assert on the captured arguments —
no DB needed for the middleware's own test suite.

## Retention — lazy prune, no cron needed

WEDOS shared hosting has no cron job configured for this project (per
`CLAUDE.md`, deploys are FTP-only, no CI/CD). Rather than add new
infrastructure, the recorder closure above has roughly a 1-in-100 chance
per page view of also calling `PageViewModel::pruneOlderThan(90)`, deleting
rows older than 90 days. On a low-traffic brochure/e-commerce site this
still runs the cleanup multiple times a day once there's any real traffic,
without needing a scheduled task.

## Model — `src/Models/PageViewModel.php`

- `anonymizeIp(string $ip): string` — pure static helper (no DB), same
  category as the existing `CategoryModel::slugify()` pure helper. Zeroes
  the last IPv4 octet or the last 16 bits of an IPv6 address; returns
  unrecognized input unchanged (defensive, shouldn't happen from
  `REMOTE_ADDR`).
- `record(string $path, string $lang, ?string $referrer, ?string $ipAnon, ?string $userAgent): void`
  — inserts a row.
- `summary(string $from, string $to): array` — returns
  `['total_views' => int, 'unique_visitors' => int]` for the date range;
  `unique_visitors` counts distinct `(ip_anon, DATE(created_at))` pairs —
  the same anonymized IP visiting on two different days counts twice,
  approximating daily uniques.
- `topPages(string $from, string $to, int $page, int $perPage): array` —
  paths grouped and counted, ordered by view count descending, paginated
  the same shape as `OrderModel::adminList()`
  (`['pages' => [...], 'total' => int, 'pages_count' => int]`).
- `pruneOlderThan(int $days): int` — deletes rows older than `$days` days,
  returns the number of rows deleted.

## Admin report — `/admin/page-views`

- Route added inside the existing `$app->group('/admin', ...)` block.
- `PageViewController::index()` reads `?days=` (7/30/90, default 30) and
  `?page=` from the query string, calls `PageViewModel::summary()` and
  `::topPages()`, renders `admin/page-views/index.twig`.
- Template styled like the existing Orders list: a `stat-grid` (reusing the
  Dashboard's `.stat-card` markup) showing total views and unique visitors,
  then a paginated table of paths + view counts. The days filter is a
  `<select onchange="this.form.submit()">`, mirroring the Orders status
  filter; pagination links preserve the `days` query param the same way
  Orders' pagination preserves `status`.
- New sidebar nav link `nav.page_views`, new translation keys
  (`page_views.title`, `page_views.stats.*`, `page_views.col.*`,
  `page_views.filter.*`) added to all 5 `lang/admin/*.json` files.

## Testing

- `tests/Unit/Models/PageViewModelTest.php` (real MySQL, per
  `.claude/rules/unit-testing.md`): `anonymizeIp()` for IPv4/IPv6,
  `record()` persists a row, `summary()` aggregates correctly against
  fixture rows with fixed timestamps, `topPages()` ordering/pagination,
  `pruneOlderThan()` deletes old rows and leaves recent ones.
- `tests/Unit/Middleware/PageViewMiddlewareTest.php` (no DB, fake handler +
  spy closure, mirroring `LangMiddlewareTest`): only invokes the recorder
  for GET requests under a supported-language path; skips `/admin/*` and
  non-GET requests; passes through path/lang/referrer/IP/user-agent
  correctly.
- Templates/CSS verified by rendering locally, per existing convention —
  not unit tested.

## Out of scope

- Bot/crawler filtering — no user-agent denylist in this version.
- Per-visitor session/journey tracking (no cookies, no visitor IDs beyond
  the anonymized IP).
- Charts/graphs — stats + table only, per explicit choice this round.
- Tracking admin panel navigation.
