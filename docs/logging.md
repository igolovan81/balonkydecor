# Logging

BalonkyDecor writes application-level diagnostic logs (errors, warnings) to
day-split files under `tmp/`, via a small PSR-3 logger — `src/Services/AppLogger.php`.
There is no third-party logging library; `psr/log` already ships transitively via
`slim/slim`, so `AppLogger` just implements `Psr\Log\AbstractLogger` directly.

This is separate from:
- **`tmp/mail.log`** — `Mailer`'s dev-only SMTP fallback (see `docs/emails.md`), not
  general logging.
- **`page_views` table** — traffic/device/browser analytics (`PageViewModel`), not
  diagnostics.
- **`notifications` table** — the admin-facing "who did what" audit trail for specific
  business events (product/category/service/customer CRUD). Not for errors.

---

## Where logs live

One file per calendar day: `tmp/app-YYYY-MM-DD.log` (outside the web root, like
`tmp/mail.log`). Each line:

```
[2026-07-23 11:47:23] ERROR GoPay createPayment: no gw_url in response {"order_number":"ORD-042","response":"..."}
```

`[timestamp] LEVEL message {optional JSON context}` — plain text, `tail`/`grep`-able,
no structured format beyond that.

`tmp/app-*.log` is covered by the `/tmp/*.log` pattern in `.gitignore` and excluded
from the FTP deploy mirror (`scripts/deploy.sh`) — these are runtime artifacts that
differ per environment and must never be committed or overwritten by a deploy.

## Retention

Controlled by `config/settings.php`'s `log_retention` (default `'3m'`). Format is
`<N>d` / `<N>w` / `<N>m` for days/weeks/months (a month is treated as 30 days —
`AppLogger::retentionToDays()` does the parsing and throws `InvalidArgumentException`
on anything else). Override per-environment in `config/settings.prod.php` if
production should keep a different window than local dev.

Pruning is **whole-file deletion** — `AppLogger::prune()` globs `tmp/app-*.log`,
parses the date out of each filename, and `unlink()`s anything older than the cutoff.
There's no cron on WEDOS shared hosting, so pruning runs opportunistically inside
`log()` itself on a 1-in-100-requests chance — the same idiom `PageViewMiddleware`
already uses to prune the `page_views` table (see `src/app.php`). This means:
- Pruning cost is cheap (deleting whole files, no line-by-line rewriting).
- It's probabilistic, not scheduled — on a low-traffic site, an old file might
  outlive its retention window by a day or two before the next prune happens to fire.
  Acceptable for this project's scale; not a guarantee of exact-day deletion.

## Where it's wired in

- **`src/app.php`** — passed as the 4th argument to `$app->addErrorMiddleware(...)`,
  so any uncaught exception is logged instead of going to PHP's default
  `error_log()` destination (which depends on hosting config and isn't documented or
  rotated).
- **`src/Services/GoPay.php`** — `getToken()`, `createPayment()`, `getStatus()` already
  degrade gracefully on a cURL failure or malformed API response (returns empty
  strings/arrays, no thrown exception — see `.claude/rules/backend.md`'s dev-fallback
  convention). They now also log the failure (with order/payment-id context) before
  returning, since a silent empty `gw_url` previously left zero trace of *why* a
  customer's payment attempt failed.
- **`src/Services/Translator.php`** — `autoFill()`'s per-field `catch` (deliberately
  swallows failures so one bad field doesn't abort translating its siblings) now logs
  a warning instead of silently continuing.
- **`src/routes.php`** — the `/admin/translate` route's `catch` logs the failure
  server-side in addition to returning the raw message in the JSON response.

## Adding logging elsewhere

```php
\App\Services\AppLogger::instance()->error('Something failed', ['key' => 'context']);
// ->warning(...), ->info(...), etc. — any PSR-3 level method works (AbstractLogger
// delegates them all to log()).
```

Follow the existing call sites' shape: log at the point where an error is being
swallowed or gracefully degraded (not for routine/expected control flow), and include
enough context (order number, payment ID, entity ID — never passwords or full
customer PII) to diagnose the failure from the log line alone.

## Testing

`tests/Unit/Services/AppLoggerTest.php` — pure unit tests (no DB), covering
timestamp/JSON-context formatting, day-file naming, retention parsing, and pruning
(old files deleted, recent files and non-`.log` files left alone). `AppLogger`'s
constructor takes the log directory as a parameter specifically so tests can point it
at a throwaway `sys_get_temp_dir()` path rather than the real `tmp/`.
