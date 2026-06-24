#!/usr/bin/env bash
# Deploy BalonkyDecor to WEDOS via FTP.
# Credentials are read from environment variables — never hardcode them here.
#
# Usage:
#   export FTP_PASS="your-ftp-password"
#   ./scripts/deploy.sh
#
# Or one-liner:
#   FTP_PASS="..." ./scripts/deploy.sh

set -euo pipefail

FTP_HOST="399580.w80.wedos.net"
FTP_USER="w399580"
REMOTE_DIR="/"
LOCAL_DIR="$(cd "$(dirname "$0")/.." && pwd)"

if [[ -z "${FTP_PASS:-}" ]]; then
  echo "Error: FTP_PASS environment variable is not set."
  echo "Usage: FTP_PASS='...' ./scripts/deploy.sh"
  exit 1
fi

echo "Deploying to $FTP_HOST ..."

lftp -u "$FTP_USER","$FTP_PASS" "ftp://$FTP_HOST" <<EOF
set ftp:passive-mode yes
set mirror:use-pget-n 4
mirror --reverse --delete --verbose \
  --exclude '^\.' \
  --exclude '^docker-compose\.yml$' \
  --exclude '^phpunit\.xml$' \
  --exclude '^composer\.(json|lock)$' \
  --exclude '^(CLAUDE|README)\.md$' \
  --exclude '^tests/' \
  --exclude '^scripts/' \
  --exclude '^docs/' \
  $LOCAL_DIR $REMOTE_DIR
quit
EOF

echo ""
echo "Deploy complete."
echo ""
echo "Remember: if this is a first deploy or DB credentials changed,"
echo "upload config/settings.prod.php separately via FTP."
