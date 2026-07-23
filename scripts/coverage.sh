#!/usr/bin/env bash
# Generate a PHPUnit code coverage report for src/ (requires the PCOV extension).
#
# Usage:
#   ./scripts/coverage.sh
#
# Output:
#   tmp/coverage.txt       - per-class text summary
#   tmp/coverage-html/     - browsable HTML report (open index.html)

set -euo pipefail

LOCAL_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$LOCAL_DIR"

if ! php -m | grep -i '^pcov$' >/dev/null; then
  echo "PCOV extension not found. Install it with: pecl install pcov"
  exit 1
fi

docker compose up -d
until docker compose exec -T db mysqladmin ping -ubalonky -pbalonky --silent 2>/dev/null; do sleep 1; done

# -d memory_limit=1G works around the shared dev DB's accumulated leftover
# test-fixture rows inflating Sitemap::entries() past PHP's default 128M
# (see .claude/rules/unit-testing.md on why leftovers accumulate) — nothing
# to do with coverage collection itself, which PCOV keeps cheap.
php -d memory_limit=1G vendor/bin/phpunit \
  --coverage-html="$LOCAL_DIR/tmp/coverage-html" \
  --coverage-text="$LOCAL_DIR/tmp/coverage.txt" \
  "$@"

echo ""
echo "Text summary: tmp/coverage.txt"
echo "HTML report:  tmp/coverage-html/index.html"
