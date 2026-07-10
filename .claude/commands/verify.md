# Verify Site Health

Check that balonkydecor.cz is healthy — public pages and admin portal.

## Steps

Run all checks with curl and report pass/fail for each:

```bash
BASE="https://balonkydecor.cz"

check() {
  local label="$1" url="$2" expected="$3"
  local code
  code=$(curl -s -o /dev/null -w "%{http_code}" -L --max-redirs 0 "$url")
  if [ "$code" = "$expected" ]; then
    echo "✓ $label ($code)"
  else
    echo "✗ $label — expected $expected, got $code  [$url]"
  fi
}

echo "=== Public pages ==="
check "Homepage redirect"        "$BASE/"             302
check "CS homepage"              "$BASE/cs/"          200
check "EN homepage"              "$BASE/en/"          200
check "SK homepage"              "$BASE/sk/"          200
check "CS shop"                  "$BASE/cs/shop"      200
check "CS gallery"               "$BASE/cs/services/archive" 200
check "CS contact"               "$BASE/cs/contact"   200
check "CS services"              "$BASE/cs/services"  200

echo ""
echo "=== Admin portal ==="
check "Admin login page"         "$BASE/admin/login"  200
check "Admin dashboard → login"  "$BASE/admin/dashboard" 302
check "Admin set-lang (unauth)"  "$BASE/admin/set-lang?l=en" 302

echo ""
echo "=== Migration status ==="
MIGRATE_TOKEN=$(php -r "echo (require 'config/settings.php')['migrate_token'];")
curl -s "$BASE/migrate.php?token=${MIGRATE_TOKEN}&action=status" | python3 -m json.tool
```

## Interpreting results

- All `✓` lines — site is healthy
- Any `✗` line — investigate that URL; check server error logs or re-deploy if a file is missing
- Migration status: every entry should show `"applied": true`; any `false` means a pending migration needs to run

## Quick fix if something is broken

- **Public page 500** — check Twig template or controller for a PHP error; deploy may have been partial
- **Admin 500** — likely a missing DB column or broken middleware; run `/deploy` then check migrations
- **Migration pending** — run `curl "$BASE/migrate.php?token=${MIGRATE_TOKEN}"` to apply
