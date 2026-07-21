# Deploy to WEDOS

Deploy the BalonkyDecor application to WEDOS shared hosting via FTP.

## Steps

1. Check that `config/settings.prod.php` does NOT exist locally (it must stay gitignored and server-only).

2. Get `FTP_PASS`: read it from the `FTP_PASS` key in `.env` at the project root (gitignored, safe to read directly) and export it — no need to prompt the user:
   ```bash
   export FTP_PASS=$(grep '^FTP_PASS=' .env | cut -d= -f2-)
   ```
   If `.env` doesn't exist or has no `FTP_PASS` key, fall back to asking the user to confirm they have `FTP_PASS` set in their environment, or offer to run:
   ```
   read -s -p "FTP password: " FTP_PASS && export FTP_PASS
   ```

3. Run the deploy script:
   ```bash
   FTP_PASS="$FTP_PASS" ./scripts/deploy.sh
   ```

4. Check for new migration files:
   ```bash
   git diff HEAD~1 --name-only | grep 'database/migrations/'
   ```
   If any new migration files were deployed, run them automatically:
   ```bash
   MIGRATE_TOKEN=$(php -r "echo (require 'config/settings.php')['migrate_token'];")
   curl -s "http://balonkydecor.cz/migrate.php?token=${MIGRATE_TOKEN}" | python3 -m json.tool
   ```
   Interpret the response:
   - `{"applied":[...],"count":N}` — success, N migrations applied
   - `{"applied":[],"count":0}` — nothing pending, already up to date
   - `{"error":"..."}` — failure, report the error to the user and stop

   **Known WEDOS limitation:** If the error mentions `CREATE command denied`, the `schema_migrations` table is out of sync with the actual DB state (previous migrations were applied manually). In that case:
   - Identify which migration actually needs to run (the new one only)
   - Ask the user to run its SQL directly in WEDOS phpMyAdmin
   - Then sync the tracker: `INSERT INTO schema_migrations (version) VALUES ('V00X__name');`

5. After deploy, remind the user:
   - Upload `config/settings.prod.php` via FTP if DB credentials changed or it's a first deploy
   - Run `/verify` for a full health check (public pages, admin portal, migration status) rather than just spot-checking `http://balonkydecor.cz/cs/`

## What gets deployed

Everything except: `.git`, `.idea`, `.phpunit.result.cache`, `docker-compose.yml`, `phpunit.xml`, `.env`, `tests/`, `scripts/`, `docs/`

## What never gets deployed automatically

- `config/settings.prod.php` — contains DB credentials, upload manually via FTP
