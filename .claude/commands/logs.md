# Observe Logs

View the app/mail logs locally with `lnav`, or pull production's logs down over FTP
first (WEDOS has no shell/cron, so there's no live-tail option there).

Usage: `/logs` or `/logs local` — today's logs, local. `/logs all` — every local log
file. `/logs prod` — download prod's `tmp/` over FTP, then open it the same way.

Background: `.claude/rules/logging.md` — log file formats, and the `lnav` custom
format setup (`~/.lnav/formats/balonkydecor/format.json`, machine-local, not in git).

## Local (default, no arg / `local` / `all`)

1. Confirm `lnav` is installed (`brew install lnav` if not) and the custom format
   exists:
   ```bash
   command -v lnav >/dev/null || brew install lnav
   test -f ~/.lnav/formats/balonkydecor/format.json || echo "Missing custom lnav format — see .claude/rules/logging.md to recreate it"
   ```

2. Open the logs:
   ```bash
   ./scripts/logs.sh          # today's tmp/app-*.log + tmp/mail.log, live tail
   ./scripts/logs.sh --all    # every app-*.log + mail.log
   ```
   Extra args pass straight through to `lnav`, e.g. a one-shot non-interactive query
   instead of opening the TUI:
   ```bash
   ./scripts/logs.sh -n -c ':filter-in ERROR'
   ./scripts/logs.sh -n -c ';SELECT log_time, level, message, context FROM balonkydecor_app_log WHERE level = %s' error
   ```

3. If asked to just check for problems rather than open an interactive session,
   grep instead of launching lnav:
   ```bash
   grep -h 'ERROR\|CRITICAL' tmp/app-*.log
   ```

## Production (`prod`)

WEDOS is FTP-only — there's no SSH/cron, so prod logs must be downloaded before
they can be viewed; there's no way to tail them live.

1. Get `FTP_PASS` the same way `/deploy` does:
   ```bash
   export FTP_PASS=$(grep '^FTP_PASS=' .env | cut -d= -f2-)
   ```
   Fall back to prompting the user if `.env` has no `FTP_PASS` key.

2. Mirror down just `tmp/` (app/mail logs) into a local scratch dir — never mirror
   prod's `tmp/` over the local one, since local logs are useful too:
   ```bash
   mkdir -p /tmp/balonkydecor-prod-logs
   lftp -u w399580,"$FTP_PASS" ftp://399580.w80.wedos.net <<'EOF'
   set ftp:passive-mode yes
   mirror tmp/ /tmp/balonkydecor-prod-logs --verbose
   EOF
   ```

3. Open the downloaded copy the same way (format detection is filename-based, not
   path-based, so the same `lnav` formats apply):
   ```bash
   lnav /tmp/balonkydecor-prod-logs/app-*.log /tmp/balonkydecor-prod-logs/mail.log
   ```

4. Treat findings like a `/verify` failure if they point to a real error — an ERROR
   line in prod's `app-*.log` means something is actually broken on the live site.

## Notes

- Log formats: `.claude/rules/logging.md`.
- `tmp/app-YYYY-MM-DD.log` is pruned by the `log_retention` setting (`AppLogger`) —
  don't expect old prod logs to still be there past that window.
- Don't commit anything downloaded from prod's `tmp/` — it can contain customer
  emails and order details; keep it in `/tmp` (or wherever the user prefers), not the
  repo.
