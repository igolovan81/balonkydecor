# Instagram Social Icons Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an admin-editable Instagram URL setting and restyle the footer's "Follow us" links (Facebook + Instagram) as circular colored icon badges, replacing the current plain-text link.

**Architecture:** Mirrors the existing `facebook_url` settings pattern exactly (migration → `SettingsController::KEYS` → admin form field → `BaseController` fetch/pass). The footer template gains inline hand-written SVG icons (no icon library/CDN dependency) styled via new CSS as circular brand-colored badges.

**Tech Stack:** Slim 4 (PHP), Twig 3, PDO/MySQL, plain CSS, inline SVG — no build step, no new dependencies.

## Global Constraints

- Scope is Facebook + Instagram only — no Twitter/RSS/YouTube settings or icons.
- Instagram URL seed value (exact, verbatim): `https://www.instagram.com/balonky_praha1?igsh=MTE2Y3luMWJoc3Zycg%3D%3D`.
- No icon library, icon font, or CDN dependency — inline SVG only, embedded directly in the Twig template.
- No new public-facing translation keys beyond the existing `footer.follow_us` — icon `aria-label` values are hardcoded platform names (proper nouns), matching how "Facebook" is already hardcoded as visible link text today.

---

### Task 1: Seed the Instagram setting via migration

**Files:**
- Create: `database/migrations/V012__instagram_setting.sql`

**Interfaces:**
- Produces: a `settings` row with key `instagram_url` — consumed by Task 2 (admin form) and Task 3 (public footer).

- [ ] **Step 1: Write the migration**

Create `database/migrations/V012__instagram_setting.sql`:
```sql
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
  ('instagram_url', 'https://www.instagram.com/balonky_praha1?igsh=MTE2Y3luMWJoc3Zycg%3D%3D');
```

- [ ] **Step 2: Validate against the local dev DB**

This is a data-only migration (no schema change), safe to apply directly to the local dev DB. **Use a properly charset-negotiated connection** — a prior migration in this project got its diacritics corrupted by piping through the `mysql` CLI without `--default-character-set=utf8mb4` (this URL has no diacritics, but always use the charset-safe form as a matter of habit):
```bash
docker compose up -d
docker exec balonkydecor_db mysql --default-character-set=utf8mb4 -ubalonky -pbalonky balonkydecor -e "SELECT COUNT(*) FROM schema_migrations WHERE version='V012__instagram_setting';"
docker exec -i balonkydecor_db mysql --default-character-set=utf8mb4 -ubalonky -pbalonky balonkydecor < database/migrations/V012__instagram_setting.sql
docker exec balonkydecor_db mysql --default-character-set=utf8mb4 -ubalonky -pbalonky balonkydecor -e "INSERT INTO schema_migrations (version) VALUES ('V012__instagram_setting');"
docker exec balonkydecor_db mysql --default-character-set=utf8mb4 -ubalonky -pbalonky balonkydecor -e "SELECT \`key\`, \`value\` FROM settings WHERE \`key\`='instagram_url';"
```
Expected: the first `SELECT COUNT(*)` returns `0` (not yet applied), and the final `SELECT` returns exactly 1 row with the exact seed value from Step 1.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/V012__instagram_setting.sql
git commit -m "feat: seed Instagram URL setting"
```

---

### Task 2: Add Instagram field to the admin Settings page

**Files:**
- Modify: `src/Controllers/Admin/SettingsController.php:11-16`
- Modify: `templates/admin/settings/index.twig:20-24`
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/sk.json`, `lang/admin/ru.json`, `lang/admin/uk.json`

**Interfaces:**
- Consumes: `settings.instagram_url` row seeded in Task 1 (only needed to display a value in the form locally — the whitelist/form changes work regardless of migration state).
- Produces: `instagram_url` becomes admin-editable; Task 3's public footer reads whatever value ends up in the `settings` table (Task 1's seed, or whatever an admin saves through this form afterward).

- [ ] **Step 1: Add `instagram_url` to the whitelist**

In `src/Controllers/Admin/SettingsController.php`, the `KEYS` constant currently reads:
```php
    private const KEYS = [
        'site_name', 'contact_email', 'contact_phone',
        'shipping_address', 'shipping_map_url',
        'facebook_url',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from',
        'gopay_go_id', 'gopay_client_id', 'gopay_client_secret', 'gopay_test_mode',
    ];
```
Change to:
```php
    private const KEYS = [
        'site_name', 'contact_email', 'contact_phone',
        'shipping_address', 'shipping_map_url',
        'facebook_url', 'instagram_url',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from',
        'gopay_go_id', 'gopay_client_id', 'gopay_client_secret', 'gopay_test_mode',
    ];
```

- [ ] **Step 2: Add the field to the admin form's "Social" fieldset**

In `templates/admin/settings/index.twig`, the "Social" section currently reads (lines 20-24):
```twig
    <h3>{{ t('settings.social') }}</h3>
    <div class="form-group">
        <label>{{ t('settings.facebook_url') }}</label>
        <input type="text" name="facebook_url" value="{{ settings.facebook_url ?? '' }}">
    </div>
```
Change to:
```twig
    <h3>{{ t('settings.social') }}</h3>
    <div class="form-group">
        <label>{{ t('settings.facebook_url') }}</label>
        <input type="text" name="facebook_url" value="{{ settings.facebook_url ?? '' }}">
    </div>
    <div class="form-group">
        <label>{{ t('settings.instagram_url') }}</label>
        <input type="text" name="instagram_url" value="{{ settings.instagram_url ?? '' }}">
    </div>
```

- [ ] **Step 3: Add `settings.instagram_url` translation key to all 5 admin lang files**

Each of `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/sk.json`, `lang/admin/ru.json`, `lang/admin/uk.json` currently has `"settings.facebook_url": "..."` at line 165 and `"settings.gopay"` immediately after at line 166. Insert the new key right after `settings.facebook_url` (alphabetical: `facebook_url` < `instagram_url` < `gopay`... wait, check: "facebook_url" vs "instagram_url" vs "gopay" — alphabetically `facebook_url` < `gopay` < `instagram_url` < `phone`. So the correct insertion point is between the existing `settings.gopay_hint` block and `settings.phone`, NOT immediately after `facebook_url`. Locate the exact current line ordering with `grep -n '"settings\.' lang/admin/cs.json` before inserting, and place `settings.instagram_url` immediately before `settings.phone` alphabetically.

`lang/admin/cs.json`:
```json
  "settings.instagram_url": "Odkaz na Instagram",
```

`lang/admin/en.json`:
```json
  "settings.instagram_url": "Instagram URL",
```

`lang/admin/sk.json`:
```json
  "settings.instagram_url": "Odkaz na Instagram",
```

`lang/admin/ru.json`:
```json
  "settings.instagram_url": "Ссылка на Instagram",
```

`lang/admin/uk.json`:
```json
  "settings.instagram_url": "Посилання на Instagram",
```

Insert each one line, in correct alphabetical position (immediately before `settings.phone` in that file — verify via `grep -n '"settings\.' <file>` first since exact line numbers may have shifted since this plan was written).

- [ ] **Step 4: Verify all 5 admin files are still valid JSON with identical key sets**

```bash
for f in lang/admin/cs.json lang/admin/en.json lang/admin/sk.json lang/admin/ru.json lang/admin/uk.json; do
  php -r "json_decode(file_get_contents('$f'), true) !== null || exit(1);" || echo "INVALID: $f"
done
php -r '
$files = ["cs","en","sk","ru","uk"];
$base = array_keys(json_decode(file_get_contents("lang/admin/cs.json"), true));
sort($base);
foreach ($files as $f) {
    $keys = array_keys(json_decode(file_get_contents("lang/admin/$f.json"), true));
    sort($keys);
    if ($keys !== $base) { echo "MISMATCH in $f\n"; }
}
echo "done\n";
'
```
Expected: no `INVALID` or `MISMATCH` lines, ends with `done`.

- [ ] **Step 5: Manually verify the admin form**

```bash
docker compose up -d
php -S localhost:8080 -t www > /tmp/php-ig-settings.log 2>&1 &
echo $! > /tmp/php-ig-settings.pid
for i in $(seq 1 15); do curl -sf http://localhost:8080/cs/ >/dev/null && break; sleep 1; done
```
Log into the admin panel locally (use `/admin/setup` if no admin user exists), navigate to `http://localhost:8080/admin/settings`, and confirm:
- An Instagram field appears in the "Sociální sítě" (Social) section, right after Facebook
- It shows the seeded value from Task 1 if applied locally, or is empty otherwise
- Editing and saving persists correctly (reload and confirm)

```bash
kill "$(cat /tmp/php-ig-settings.pid)" 2>/dev/null
```

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/Admin/SettingsController.php templates/admin/settings/index.twig \
        lang/admin/cs.json lang/admin/en.json lang/admin/sk.json lang/admin/ru.json lang/admin/uk.json
git commit -m "feat: add Instagram URL field to admin settings page"
```

---

### Task 3: Render Facebook + Instagram as icon badges in the public footer

**Files:**
- Modify: `src/Controllers/BaseController.php:35,50`
- Modify: `templates/layout/base.twig:53-60`
- Modify: `www/assets/css/style.css`

**Interfaces:**
- Consumes: `settings.instagram_url` / `settings.facebook_url` DB rows (Task 1 seeds Instagram; Task 2's admin form can change either — this task must read whatever is currently in the table).
- Produces: none (leaf UI change).

- [ ] **Step 1: Fetch `instagram_url` in `BaseController::render()`**

In `src/Controllers/BaseController.php`, the settings query currently reads (line 35):
```php
        $settingsStmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('contact_phone','contact_email','facebook_url')");
```
Change to:
```php
        $settingsStmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('contact_phone','contact_email','facebook_url','instagram_url')");
```

And where `facebook_url` is currently passed to the template (line 50):
```php
            'facebook_url'         => $settingsMap['facebook_url'] ?? '',
```
Add immediately after it:
```php
            'facebook_url'         => $settingsMap['facebook_url'] ?? '',
            'instagram_url'        => $settingsMap['instagram_url'] ?? '',
```

- [ ] **Step 2: Replace the footer markup with icon badges**

In `templates/layout/base.twig`, the footer currently reads (lines 53-60):
```twig
    <footer class="site-footer">
        <div class="container">
            <p>&copy; {{ "now"|date("Y") }} {{ t('site.name') }}</p>
            {% if facebook_url %}
            <p class="footer-social">{{ t('footer.follow_us') }}: <a href="{{ facebook_url }}" target="_blank" rel="noopener">Facebook</a></p>
            {% endif %}
        </div>
    </footer>
```
Change to:
```twig
    <footer class="site-footer">
        <div class="container">
            <p>&copy; {{ "now"|date("Y") }} {{ t('site.name') }}</p>
            {% if facebook_url or instagram_url %}
            <div class="footer-social">
                <span class="footer-social-label">{{ t('footer.follow_us') }}</span>
                <div class="footer-social-icons">
                    {% if facebook_url %}
                    <a href="{{ facebook_url }}" target="_blank" rel="noopener" class="social-icon social-icon-facebook" aria-label="Facebook">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12.06C22 6.48 17.52 2 11.94 2 6.36 2 1.88 6.48 1.88 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.42V9.87c0-2.39 1.42-3.71 3.6-3.71 1.04 0 2.13.19 2.13.19v2.34h-1.2c-1.18 0-1.55.73-1.55 1.48v1.78h2.64l-.42 2.91h-2.22V22c4.78-.76 8.44-4.92 8.44-9.94z"/></svg>
                    </a>
                    {% endif %}
                    {% if instagram_url %}
                    <a href="{{ instagram_url }}" target="_blank" rel="noopener" class="social-icon social-icon-instagram" aria-label="Instagram">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1"/></svg>
                    </a>
                    {% endif %}
                </div>
            </div>
            {% endif %}
        </div>
    </footer>
```

- [ ] **Step 3: Add the icon CSS**

In `www/assets/css/style.css`, add these new rules immediately after the existing `.site-footer` rule (currently line 74: `.site-footer { border-top: 1px solid var(--border); padding: 2rem 0; text-align: center; color: var(--muted); font-family: var(--ui-font); font-size: .85rem; margin-top: 4rem; }`):
```css
.footer-social { margin-top: 1rem; }
.footer-social-label { display: block; margin-bottom: .5rem; font-family: var(--ui-font); font-size: .8rem; }
.footer-social-icons { display: flex; justify-content: center; gap: .75rem; }
.social-icon { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; color: #fff; fill: #fff; transition: opacity .2s; }
.social-icon:hover { opacity: .85; }
.social-icon svg { width: 18px; height: 18px; }
.social-icon-facebook { background: #1877F2; }
.social-icon-instagram { background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fdf497 5%, #fd5949 45%, #d6249f 60%, #285aeb 90%); }
```

- [ ] **Step 4: Run the full test suite**

```bash
docker compose up -d
php vendor/bin/phpunit --testdox 2>&1 | tail -10
```
Expected: 100% green — this task adds no new PHP logic covered by existing tests, no test count regression.

- [ ] **Step 5: Manually verify the icons render**

```bash
php -S localhost:8080 -t www > /tmp/php-ig-footer.log 2>&1 &
echo $! > /tmp/php-ig-footer.pid
for i in $(seq 1 15); do curl -sf http://localhost:8080/cs/ >/dev/null && break; sleep 1; done
curl -s http://localhost:8080/cs/ | grep -o 'social-icon-facebook\|social-icon-instagram\|footer-social-label'
kill "$(cat /tmp/php-ig-footer.pid)" 2>/dev/null
```
Expected: all three classes found (both icons present with the label), assuming Task 1's migration was applied locally. If not applied locally, the `{% if facebook_url or instagram_url %}` block correctly renders nothing for whichever value is empty — verify at least `social-icon-facebook` appears if `facebook_url` was already seeded by an earlier plan's migration (`V011__facebook_setting.sql`), which should already be applied locally from prior work.

Also open `http://localhost:8080/cs/` in a browser if available and visually confirm both icons render as filled circles (blue Facebook, gradient Instagram) with white icon glyphs, roughly 36px, centered under the copyright line.

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/BaseController.php templates/layout/base.twig www/assets/css/style.css
git commit -m "feat: render Facebook and Instagram as icon badges in the footer"
```

---

## Final Verification

- [ ] **Step 1: Run the full suite one more time**

```bash
docker compose up -d
php vendor/bin/phpunit --testdox 2>&1 | tail -10
```
Expected: all tests pass.

- [ ] **Step 2: End-to-end smoke test across languages**

```bash
php -S localhost:8080 -t www > /tmp/php-final.log 2>&1 &
echo $! > /tmp/php-final.pid
for i in $(seq 1 15); do curl -sf http://localhost:8080/cs/ >/dev/null && break; sleep 1; done
for l in cs en sk ru uk; do
  echo "== $l =="
  curl -s "http://localhost:8080/$l/" | grep -o 'footer-social-label\|social-icon-facebook\|social-icon-instagram'
done
kill "$(cat /tmp/php-final.pid)" 2>/dev/null
```
Expected: consistent icon presence across all 5 languages (the icons themselves aren't language-dependent, only the `footer-social-label` text differs by locale).
