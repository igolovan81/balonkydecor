#!/usr/bin/env bash
# Open the local app/mail logs in lnav (merged, tailing, filterable).
#
# Requires the custom format at ~/.lnav/formats/balonkydecor/format.json
# (parses tmp/app-YYYY-MM-DD.log and tmp/mail.log) — see .claude/rules/logging.md.
#
# Usage:
#   ./scripts/logs.sh            # today's app log + mail log, live tail
#   ./scripts/logs.sh --all      # every app-*.log + mail.log
#   ./scripts/logs.sh <extra lnav args...>

set -euo pipefail

LOCAL_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TMP_DIR="$LOCAL_DIR/tmp"

if [[ "${1:-}" == "--all" ]]; then
  shift
  exec lnav "$TMP_DIR"/app-*.log "$TMP_DIR/mail.log" "$@"
fi

exec lnav "$TMP_DIR/app-$(date +%Y-%m-%d).log" "$TMP_DIR/mail.log" "$@"
