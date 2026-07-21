# Start Locally

Start the full BalonkyDecor dev environment: Docker MySQL + PHP built-in server, with pending migrations applied.

## Steps

1. Start MySQL and wait until it accepts connections:
   ```bash
   docker compose up -d
   until docker compose exec -T db mysqladmin ping -ubalonky -pbalonky --silent 2>/dev/null; do sleep 1; done
   echo "MySQL ready on 127.0.0.1:3306 (db=balonkydecor, user=balonky, pass=balonky)"
   ```

2. Check whether port 8080 is already serving the app:
   ```bash
   curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/cs/
   ```
   - `200` — a server is already running (often the user's own); **do not start another**, skip to step 4.
   - Connection refused — start the server in the background:
     ```bash
     php -S localhost:8080 -t www
     ```
     (run as a background task; logs go to the task output file)

3. Apply pending migrations to the local DB:
   ```bash
   MIGRATE_TOKEN=$(php -r "echo (require 'config/settings.php')['migrate_token'];")
   curl -s "http://localhost:8080/migrate.php?token=${MIGRATE_TOKEN}" | python3 -m json.tool
   ```
   - `{"applied":[...],"count":N}` — N migrations applied
   - `{"applied":[],"count":0}` — already up to date
   - `{"error":"..."}` — report the error and stop

4. Smoke check and report:
   ```bash
   curl -s -o /dev/null -w "CS homepage: %{http_code}\n"  http://localhost:8080/cs/
   curl -s -o /dev/null -w "Shop:        %{http_code}\n"  http://localhost:8080/cs/shop
   curl -s -o /dev/null -w "Wishlist:    %{http_code}\n"  http://localhost:8080/cs/wishlist
   curl -s -o /dev/null -w "Compare:     %{http_code}\n"  http://localhost:8080/cs/compare
   curl -s -o /dev/null -w "Admin login: %{http_code}\n"  http://localhost:8080/admin/login
   ```
   All must return `200`.

5. Tell the user the environment is ready:
   - Site: http://localhost:8080/cs/ (also `/en/`, `/ru/`, `/uk/`, `/sk/`)
   - Admin: http://localhost:8080/admin/login (first-time setup: `/admin/setup` while `users` table is empty)
   - Dev conveniences: GoPay bypass is active when `gopay_go_id` setting is empty; outgoing mail is logged to `tmp/mail.log`

## Notes

- The dev DB is persistent and shared with the test suite — expect `test-*` fixture rows on local pages; this is normal.
- Stopping: kill the `php -S` background task; `docker compose stop` for MySQL (keep the volume — don't `down -v` unless the user asks to reset the DB).
