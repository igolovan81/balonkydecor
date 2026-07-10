# Page-view device type + browser classification — design

Date: 2026-07-10

## Problem

The page-view tracking feature (`page_views` table, `PageViewMiddleware`,
`PageViewModel`) records path/lang/referrer/anonymized-IP/user-agent per
public page visit, but doesn't break that down by what kind of device or
browser visitors are using. There's no way to answer "how many visitors are
on mobile vs desktop" or "what device type is most common" from the admin
report.

## Scope

Two new derived fields, computed from the already-captured `user_agent`
string at record time:

- **Device type**: `desktop`, `mobile-android`, `mobile-ios`,
  `tablet-android`, `tablet-ios`, `other`.
- **Browser**: `chrome`, `firefox`, `safari`, `edge`, `opera`, `samsung`,
  `ie`, `other`. Captured for **every** row (not just desktop) — a visit
  from Chrome on Android is just as identifiable as Chrome on desktop.

Both are "good enough" regex/substring classifications on the raw
`User-Agent` header — the same positioning as the existing `anonymizeIp()`
helper: not bulletproof (UAs can be spoofed, new devices/browsers can slip
through), but useful for aggregate reporting without adding a UA-parsing
library dependency.

Only **device type** gets a report table this round. **Browser** is
captured and stored now so no data is lost, but its report table is
explicitly deferred — a small follow-up using the identical pattern once
it's wanted.

## Data model — `database/migrations/V020__page_view_device_browser.sql`

```sql
ALTER TABLE `page_views`
  ADD COLUMN `device_type` varchar(20) NOT NULL DEFAULT 'other' AFTER `user_agent`,
  ADD COLUMN `browser`     varchar(20) NOT NULL DEFAULT 'other' AFTER `device_type`;
```

Existing rows (from testing/early production traffic) keep the `'other'`
default for both columns — no backfill from their stored `user_agent`. The
historical volume is trivial and a SQL-based `REGEXP` backfill would have to
be kept in sync with the PHP classifier indefinitely; simpler to just
classify going forward.

## Classifiers — `src/Models/PageViewModel.php`

Two pure static helpers, no DB access, same category as the existing
`anonymizeIp()`:

- `classifyDevice(?string $userAgent): string`
  1. Empty/null → `other`
  2. Contains `iPad` → `tablet-ios`
  3. Contains `iPhone` or `iPod` → `mobile-ios`
  4. Contains `Android` **and** `Mobile` → `mobile-android`
  5. Contains `Android` (no `Mobile` token) → `tablet-android`
  6. Otherwise → `desktop`

- `classifyBrowser(?string $userAgent): string` — order matters, most
  specific first, because Chromium-based browsers all carry a `Chrome` and
  `Safari` token alongside their own:
  1. Empty/null → `other`
  2. Contains `Edg/` or `Edge/` → `edge`
  3. Contains `OPR/` or `Opera` → `opera`
  4. Contains `SamsungBrowser` → `samsung`
  5. Contains `Firefox` → `firefox`
  6. Contains `Chrome` or `CriOS` (Chrome on iOS) → `chrome`
  7. Contains `Safari` (none of the above matched) → `safari`
  8. Contains `MSIE` or `Trident` → `ie`
  9. Otherwise → `other`

## Wiring — `src/app.php`

The recorder closure (already computing `$ipAnon = PageViewModel::anonymizeIp($ip)`
before calling `record()`) gains two more computed values:

```php
$ipAnon     = \App\Models\PageViewModel::anonymizeIp($ip);
$deviceType = \App\Models\PageViewModel::classifyDevice($userAgent);
$browser    = \App\Models\PageViewModel::classifyBrowser($userAgent);
\App\Models\PageViewModel::record($path, $lang, $referrer, $ipAnon, $userAgent, $deviceType, $browser);
```

`PageViewModel::record()` gains two new trailing parameters:
`string $deviceType = 'other'`, `string $browser = 'other'` — defaulted so
any other hypothetical call site doesn't break.

`PageViewMiddleware` is unchanged — it already passes `$userAgent` through
to the recorder closure; classification happens on the `app.php` side, not
in the middleware.

## Reporting — `PageViewModel::deviceBreakdown()`

```php
public static function deviceBreakdown(string $from, string $to): array
{
    // SELECT device_type, COUNT(*) AS views FROM page_views
    // WHERE created_at BETWEEN :from AND :to
    // GROUP BY device_type ORDER BY views DESC
}
```

Returns `[['device_type' => string, 'views' => int], ...]`, no pagination
(at most 6 rows). Rendered as a new "By Device" table on the existing
`/admin/page-views` report, below the top-pages table, reusing the same
`days` filter already on that page. Device labels are translated via
`t('page_views.devices.type.' ~ d.device_type)`, mirroring the existing
`t('orders.status.' ~ o.status)` pattern. New translation keys
(`page_views.devices.title`, `page_views.devices.col.type`,
`page_views.devices.type.*` for all 6 buckets) added to all 5
`lang/admin/*.json` files.

No `browserBreakdown()` method and no "By Browser" table this round — the
`browser` column is written but not yet read anywhere.

## Testing

`tests/Unit/Models/PageViewModelTest.php` gains:
- `classifyDevice()` — one case per bucket (6 cases).
- `classifyBrowser()` — one case per bucket (8 cases), including at least
  one Edge or Opera UA string to prove the "most specific first" ordering
  actually works (a naive `Chrome` check first would misclassify them).
- `record()` persists `device_type` and `browser`.
- `deviceBreakdown()` groups and orders by view count correctly.

No changes to `tests/Unit/Middleware/PageViewMiddlewareTest.php` — the
middleware itself is untouched.

## Out of scope

- Browser breakdown report table (deferred — data is captured, UI isn't
  built yet).
- Browser *version* (e.g. "Chrome 128") — only browser family.
- Backfilling `device_type`/`browser` for rows that existed before this
  migration.
- Any UA-parsing library dependency — hand-rolled substring/regex checks
  only, consistent with the rest of this feature's "good enough" approach.
