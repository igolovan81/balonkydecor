# Deploy to WEDOS

Deploy the BalonkyDecor application to WEDOS shared hosting via FTP.

## Steps

1. Check that `config/settings.prod.php` does NOT exist locally (it must stay gitignored and server-only).

2. Ask the user to confirm they have `FTP_PASS` set in their environment, or offer to run:
   ```
   read -s -p "FTP password: " FTP_PASS && export FTP_PASS
   ```

3. Run the deploy script:
   ```bash
   FTP_PASS="$FTP_PASS" ./scripts/deploy.sh
   ```

4. After deploy, remind the user:
   - Upload `config/settings.prod.php` via FTP if DB credentials changed or it's a first deploy
   - Run migrations via `GET /migrate.php?token=…` if new migration files were deployed
   - Verify the site at `http://balonkydecor.cz/cs/`

## What gets deployed

Everything except: `.git`, `.idea`, `.phpunit.result.cache`, `docker-compose.yml`, `phpunit.xml`, `.env`, `tests/`, `scripts/`, `docs/`

## What never gets deployed automatically

- `config/settings.prod.php` — contains DB credentials, upload manually via FTP
