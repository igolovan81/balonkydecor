# WhatsApp Contact Link Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a click-to-chat WhatsApp icon to the public site footer (alongside the existing Facebook/Instagram icons), backed by a new `whatsapp_phone` admin-editable setting.

**Architecture:** New `whatsapp_phone` setting (seeded via migration), whitelisted in `SettingsController`, editable in the admin Settings form. `BaseController::render()` derives a `wa.me` URL from the stored phone number (digits only) and passes it to every public template exactly like `facebook_url`/`instagram_url`. `base.twig` renders a third footer icon when the URL is present.

**Tech Stack:** PHP 8 / Slim 4, PDO/MySQL 8, Twig 3, plain CSS.

## Global Constraints

- New migration file: `database/migrations/V018__whatsapp_setting.sql`, idempotent (`INSERT IGNORE`), never edit an already-applied migration.
- New setting key needs both a seed migration **and** an entry in `SettingsController::KEYS` to be admin-editable (`.claude/rules/database.md`).
- New translation key goes in all 5 `lang/admin/{cs,en,ru,uk,sk}.json` files, kept alphabetically sorted (existing convention).
- No hardcoded colors beyond the one-off brand color exception already established for third-party icons (`.claude/rules/css-styling.md` explicitly allows this for `.social-icon-facebook`).
- This is Twig/config wiring — no PHPUnit tests apply; verify by rendering the page locally (`.claude/rules/unit-testing.md`).

---

### Task 1: Seed the `whatsapp_phone` setting

**Files:**
- Create: `database/migrations/V018__whatsapp_setting.sql`

**Interfaces:**
- Produces: `settings` row with `key = 'whatsapp_phone'`. Consumed by Task 2
  (`BaseController`) and Task 4 (admin Settings form).

- [ ] **Step 1: Write the migration file**

```sql
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
  ('whatsapp_phone', '+420 739 922 277');
```

- [ ] **Step 2: Ensure the local DB and app server are running**

```bash
docker compose up -d
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/cs/
```
If the curl doesn't print `200`, start the server in the background:
`php -S localhost:8080 -t www`

- [ ] **Step 3: Apply the migration**

```bash
MIGRATE_TOKEN=$(php -r "echo (require 'config/settings.php')['migrate_token'];")
curl -s "http://localhost:8080/migrate.php?token=${MIGRATE_TOKEN}" | python3 -m json.tool
```
Expected: `{"applied": ["V018__whatsapp_setting"], "count": 1}`.

- [ ] **Step 4: Verify the row**

```bash
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e "SELECT * FROM settings WHERE \`key\`='whatsapp_phone';"
```
Expected: one row, `value = +420 739 922 277`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/V018__whatsapp_setting.sql
git commit -m "feat: seed whatsapp_phone setting"
```

---

### Task 2: Derive the wa.me URL in `BaseController`

**Files:**
- Modify: `src/Controllers/BaseController.php:35-52`

**Interfaces:**
- Consumes: `settings.whatsapp_phone` (Task 1).
- Produces: `whatsapp_url` template variable (empty string when no phone is set),
  injected into every public template's data alongside `facebook_url`/
  `instagram_url`. Consumed by Task 3 (`base.twig`).

- [ ] **Step 1: Update `render()`**

Replace the settings block in `src/Controllers/BaseController.php`:

```php
        $pdo          = Database::getConnection();
        $settingsStmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('contact_phone','contact_email','facebook_url','instagram_url','whatsapp_phone')");
        $settingsStmt->execute();
        $settingsMap = array_column($settingsStmt->fetchAll(), 'value', 'key');

        $whatsappDigits = preg_replace('/\D+/', '', $settingsMap['whatsapp_phone'] ?? '');

        return $this->twig->render($response, $template, array_merge([
            'lang'                 => $lang,
            'current_path'         => $path,
            'base_url'             => Seo::BASE_URL,
            'canonical_url'        => Seo::canonicalUrl($lang, $path),
            'alternate_urls'       => Seo::alternateUrls($path),
            'organization_json_ld' => Seo::organizationJsonLd(
                $i18n->t('site.name'),
                $settingsMap['contact_phone'] ?? '',
                $settingsMap['contact_email'] ?? ''
            ),
            'facebook_url'         => $settingsMap['facebook_url'] ?? '',
            'instagram_url'        => $settingsMap['instagram_url'] ?? '',
            'whatsapp_url'         => $whatsappDigits !== '' ? 'https://wa.me/' . $whatsappDigits : '',
        ], $data));
```

- [ ] **Step 2: Syntax-check**

Run: `php -l src/Controllers/BaseController.php`
Expected: `No syntax errors detected in src/Controllers/BaseController.php`

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/BaseController.php
git commit -m "feat: derive wa.me URL from whatsapp_phone setting"
```

---

### Task 3: Footer icon — `templates/layout/base.twig` + CSS

**Files:**
- Modify: `templates/layout/base.twig:53-74`
- Modify: `www/assets/css/style.css`

**Interfaces:**
- Consumes: `whatsapp_url` (Task 2).

- [ ] **Step 1: Extend the footer condition and add the icon**

In `templates/layout/base.twig`, replace:

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

with:

```twig
    <footer class="site-footer">
        <div class="container">
            <p>&copy; {{ "now"|date("Y") }} {{ t('site.name') }}</p>
            {% if facebook_url or instagram_url or whatsapp_url %}
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
                    {% if whatsapp_url %}
                    <a href="{{ whatsapp_url }}" target="_blank" rel="noopener" class="social-icon social-icon-whatsapp" aria-label="WhatsApp">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.46 1.32 4.96L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21h.01c5.46 0 9.91-4.45 9.91-9.91C21.96 6.45 17.5 2 12.04 2zm0 18.14h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.37c0-4.53 3.69-8.22 8.24-8.22 2.2 0 4.27.86 5.82 2.42a8.17 8.17 0 0 1 2.41 5.81c0 4.54-3.69 8.22-8.22 8.22zm4.51-6.16c-.25-.12-1.47-.72-1.7-.81-.23-.08-.39-.12-.56.12-.17.25-.64.81-.78.97-.14.17-.29.19-.53.06-.25-.12-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.5.11-.11.25-.29.37-.43.12-.14.16-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.42-.14-.01-.31-.01-.47-.01-.17 0-.43.06-.66.31-.23.25-.86.84-.86 2.05 0 1.2.88 2.37 1 2.53.12.17 1.73 2.64 4.2 3.7.59.25 1.05.4 1.41.52.59.19 1.13.16 1.55.1.47-.07 1.47-.6 1.68-1.18.21-.58.21-1.08.15-1.18-.06-.1-.23-.17-.48-.29z"/></svg>
                    </a>
                    {% endif %}
                </div>
            </div>
            {% endif %}
        </div>
    </footer>
```

- [ ] **Step 2: Add the CSS**

In `www/assets/css/style.css`, right after the line `.social-icon-instagram { background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fdf497 5%, #fd5949 45%, #d6249f 60%, #285aeb 90%); }`, add:

```css
.social-icon-whatsapp { background: #25D366; }
```

- [ ] **Step 3: Manually verify in the browser**

With the local server running, visit `http://localhost:8080/cs/` and confirm a green
WhatsApp icon now appears in the footer next to Facebook/Instagram, and clicking it
opens `https://wa.me/420739922277` in a new tab (a WhatsApp chat with that number).

- [ ] **Step 4: Commit**

```bash
git add templates/layout/base.twig www/assets/css/style.css
git commit -m "feat: add WhatsApp icon to public footer"
```

---

### Task 4: Admin Settings form field

**Files:**
- Modify: `src/Controllers/Admin/SettingsController.php:10-16`
- Modify: `templates/admin/settings/index.twig:20-28`
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`

**Interfaces:**
- Produces: `whatsapp_phone` becomes admin-editable; translation key
  `settings.whatsapp_phone`.

- [ ] **Step 1: Whitelist the key**

In `src/Controllers/Admin/SettingsController.php`, change:

```php
    private const KEYS = [
        'site_name', 'contact_email', 'contact_phone',
        'shipping_address', 'shipping_map_url',
        'facebook_url', 'instagram_url',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from',
        'gopay_go_id', 'gopay_client_id', 'gopay_client_secret', 'gopay_test_mode',
    ];
```

to:

```php
    private const KEYS = [
        'site_name', 'contact_email', 'contact_phone',
        'shipping_address', 'shipping_map_url',
        'facebook_url', 'instagram_url', 'whatsapp_phone',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from',
        'gopay_go_id', 'gopay_client_id', 'gopay_client_secret', 'gopay_test_mode',
    ];
```

- [ ] **Step 2: Add the form field**

In `templates/admin/settings/index.twig`, replace:

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

with:

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
    <div class="form-group">
        <label>{{ t('settings.whatsapp_phone') }}</label>
        <input type="text" name="whatsapp_phone" value="{{ settings.whatsapp_phone ?? '' }}">
    </div>
```

- [ ] **Step 3: Add the translation key to all 5 files, right after `"settings.web"`**

`lang/admin/cs.json`:
```json
  "settings.web": "Web",
  "settings.whatsapp_phone": "Telefon pro WhatsApp",
```

`lang/admin/en.json`:
```json
  "settings.web": "Website",
  "settings.whatsapp_phone": "WhatsApp phone number",
```

`lang/admin/ru.json`:
```json
  "settings.web": "Сайт",
  "settings.whatsapp_phone": "Телефон для WhatsApp",
```

`lang/admin/uk.json`:
```json
  "settings.web": "Сайт",
  "settings.whatsapp_phone": "Телефон для WhatsApp",
```

`lang/admin/sk.json`:
```json
  "settings.web": "Web",
  "settings.whatsapp_phone": "Telefón pre WhatsApp",
```

- [ ] **Step 4: Verify all 5 files stay valid JSON with identical key sets**

```bash
python3 -c "
import json
files = ['cs','en','ru','uk','sk']
keysets = {}
for l in files:
    d = json.load(open(f'lang/admin/{l}.json'))
    keysets[l] = set(d.keys())
base = keysets['cs']
for l in files:
    assert keysets[l] == base, f'{l} differs: {keysets[l] ^ base}'
print('OK, all files have', len(base), 'identical keys')
"
```
Expected: `OK, all files have 277 identical keys` (276 existing + 1 new).

- [ ] **Step 5: Syntax-check the controller**

Run: `php -l src/Controllers/Admin/SettingsController.php`
Expected: `No syntax errors detected in src/Controllers/Admin/SettingsController.php`

- [ ] **Step 6: Manually verify in the browser**

Log into `/admin/settings`, confirm the new "WhatsApp phone number" field appears
under the Social section pre-filled with `+420 739 922 277`, and that saving the form
persists a changed value (check the `settings` table or reload the page).

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/Admin/SettingsController.php templates/admin/settings/index.twig \
        lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/uk.json lang/admin/sk.json
git commit -m "feat: add WhatsApp phone number field to admin Settings"
```

---

### Task 5: Full verification

**Files:** none (verification only)

**Interfaces:**
- Consumes: everything from Tasks 1–4.

- [ ] **Step 1: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests pass, zero failures/errors (this feature adds no new PHPUnit
tests, so the count should match the pre-existing baseline).

- [ ] **Step 2: Smoke check**

```bash
curl -s -o /dev/null -w "CS homepage:   %{http_code}\n" http://localhost:8080/cs/
curl -s -o /dev/null -w "Admin login:   %{http_code}\n" http://localhost:8080/admin/login
curl -s -o /dev/null -w "Admin settings: %{http_code}\n" http://localhost:8080/admin/settings
```
Expected: `CS homepage` and `Admin login` return `200`; `Admin settings` returns `302`
if not authenticated in this shell (redirect to login), or `200` if a session cookie
is present. The authenticated browser checks in Task 3 Step 3 and Task 4 Step 6
already cover the real behavior.

- [ ] **Step 3: Final commit if any stragglers remain**

```bash
git status
```
Expected: clean working tree (everything already committed task-by-task). If anything
is outstanding, commit it with a clear message.
