---
description: How app/mail logs are structured and observed locally — flat files, no aggregation service, lnav for viewing.
globs: ["src/Services/AppLogger.php", "src/Services/Mailer.php", "src/Services/SlowQueryLogger.php", "src/Services/TimedStatement.php", "src/Models/Database.php", "scripts/logs.sh"]
alwaysApply: false
---

# Logging & Observability

There is no log aggregation service (no Splunk/ELK/Loki) — WEDOS shared hosting has
no SSH/cron/agent access, so logs are plain flat files, same locally and in prod:

- `tmp/app-YYYY-MM-DD.log` — `AppLogger` (PSR-3), one file per day, pruned by the
  `log_retention` setting (`src/Services/AppLogger.php`). Line shape:
  `[YYYY-MM-DD HH:MM:SS] LEVEL message {"optional":"json context"}`.
- `tmp/mail.log` — `Mailer`'s dev fallback (used whenever SMTP isn't configured).
  Line shape: `[YYYY-MM-DD HH:MM:SS] TO:{email} SUBJECT:{subject}` followed by the
  raw HTML body until the next timestamped header.
- In prod, `tmp/` is outside the web root and only reachable by downloading it over
  FTP (`/deploy` is FTP-only, no shell) — there is no live-tail from prod.

## Slow query logging

`Database::getConnection()` sets `PDO::ATTR_STATEMENT_CLASS` to `TimedStatement`
(`src/Services/TimedStatement.php`), which times every `execute()` call and hands the
elapsed seconds to `SlowQueryLogger` (`src/Services/SlowQueryLogger.php`). Queries
under 0.5s are dropped silently; slower ones go to `AppLogger::warning()` — always
`WARNING` level regardless of severity label — as a normal `app-*.log` line, so no
lnav format changes were needed to parse it:

```
[YYYY-MM-DD HH:MM:SS] WARNING Slow query [MINOR|MEDIUM|MAJOR|CRITICAL] N.NNNs: <query> {"severity":"...","seconds":N.NNN}
```

Thresholds (`SlowQueryLogger::SEVERITIES`): `>=0.5s` MINOR, `>=1.0s` MEDIUM,
`>=3.0s` MAJOR, `>=6.0s` CRITICAL. This wraps every query transparently — no model or
controller call site needed to change. Query by severity with, e.g.:
```sql
;SELECT log_time, message FROM balonkydecor_app_log WHERE context LIKE '%CRITICAL%'
```

## Viewing locally: lnav

[lnav](https://lnav.org) (`brew install lnav`) is set up to parse both formats via a
custom format at `~/.lnav/formats/balonkydecor/format.json` (machine-local, not
checked into the repo — recreate it with the same content if the machine changes):
two format defs, `balonkydecor_app_log` (`file-pattern` matches `app-*.log`, extracts
`level`/`message`/`context`) and `balonkydecor_mail_log` (`file-pattern` matches
`mail.log`, extracts `mail_to`/`subject`; body lines are multiline continuations of
the header, per lnav's default multiline-until-next-match behavior). Column name is
`mail_to`, not `to` — `to` is a SQLite reserved word and breaks lnav's virtual table.

- `./scripts/logs.sh` — opens today's `app-*.log` + `mail.log` in lnav (live tail).
- `./scripts/logs.sh --all` — opens every `app-*.log` plus `mail.log`.
- Inside lnav: `/` to search, `f` to filter, `:filter-in <regex>` /
  `:filter-out <regex>`, or drop into SQL with `;SELECT log_time, level, message,
  context FROM balonkydecor_app_log WHERE level = 'error'`.
- If downloading prod's `tmp/` via FTP, point `scripts/logs.sh`'s args (or `lnav`
  directly) at the downloaded copy — the format detection is filename-based
  (`file-pattern`), not path-based, so it works the same regardless of location.

## When adding new log output

- Keep using `AppLogger::instance()` (PSR-3 levels) rather than inventing a new
  ad-hoc format — new formats need a matching lnav pattern or they'll fall back to
  lnav's generic unstructured-text handling (still readable, just no level/field
  coloring).
