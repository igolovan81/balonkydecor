# Follow Us Footer Section Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Follow us" section to the public site footer linking to the
business's Facebook page, with the URL stored as an admin-configurable
setting.

**Architecture:** Add a `facebook_url` row to the existing `settings`
key/value table (migration). Extend `BaseController::render()`'s existing
settings query so every public page gets `facebook_url` in its Twig context,
and render it in the shared footer. Add the field to the admin Settings page
following the exact pattern already used for `shipping_address` /
`shipping_map_url`.

**Tech Stack:** PHP 8 (Slim 4), Twig 3, MySQL 8, PHPUnit 11.

## Global Constraints

- Translation files `lang/{cs,en,ru,uk,sk}.json` must all have identical
  keys; `lang/admin/{cs,en,ru,uk,sk}.json` must all have identical keys.
- Migrations are additive SQL files in `database/migrations/`, named
  `V0NN__description.sql`, applied via `INSERT IGNORE` for seed data (see
  `V010__shipping_settings.sql`).
- `settings` table columns are `` `key` `` and `` `value` `` (backticked
  reserved word `key`).
- Given URL for the setting value: `https://www.facebook.com/search/top?q=balonkytop%20cz`

---

### Task 1: Database migration + backend settings plumbing

**Files:**
- Create: `database/migrations/V011__facebook_setting.sql`
- Modify: `src/Controllers/BaseController.php:31-34`
- Modify: `src/Controllers/Admin/SettingsController.php:10-15`

**Interfaces:**
- Produces: template variable `facebook_url` (string, may be empty) passed
  into every public Twig render via `BaseController::render()`. Task 3
  consumes this in `templates/layout/base.twig`.
- Produces: `facebook_url` as a recognized POST field key in
  `SettingsController::save()`. Task 2's admin form field posts to this key.

- [ ] **Step 1: Add the migration**

Create `database/migrations/V011__facebook_setting.sql`:

```sql
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
  ('facebook_url', 'https://www.facebook.com/search/top?q=balonkytop%20cz');
```

- [ ] **Step 2: Apply the migration locally**

Ensure Docker MySQL is running (`docker compose up -d`), then start the PHP
dev server and hit the token-protected migration endpoint
(`www/migrate.php`), using the `migrate_token` value from
`config/settings.php`:

```bash
php -S localhost:8080 -t www &
sleep 1
curl "http://localhost:8080/migrate.php?token=8b1b4af4ff83a007dda3fc43ab1c7f43372884714905281f9d1e61cc46c3a781"
```

Expected: JSON like `{"applied":["V011__facebook_setting"],"count":1}` (or
`{"applied":[],"count":0}` if it was already applied — safe to re-run,
migrations are tracked in `schema_migrations` and the seed uses
`INSERT IGNORE`).

- [ ] **Step 3: Verify the row exists**

```bash
docker exec -i balonkydecor_db mysql -ubalonky -pbalonky balonkydecor \
  -e "SELECT * FROM settings WHERE \`key\`='facebook_url';"
```

Expected: one row, `facebook_url` /
`https://www.facebook.com/search/top?q=balonkytop%20cz`.

- [ ] **Step 4: Extend `BaseController::render()` to fetch and expose the setting**

In `src/Controllers/BaseController.php`, the existing block is:

```php
        $pdo          = Database::getConnection();
        $settingsStmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('contact_phone','contact_email')");
        $settingsStmt->execute();
        $settingsMap = array_column($settingsStmt->fetchAll(), 'value', 'key');
```

Change the `IN (...)` list to include `facebook_url`:

```php
        $pdo          = Database::getConnection();
        $settingsStmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('contact_phone','contact_email','facebook_url')");
        $settingsStmt->execute();
        $settingsMap = array_column($settingsStmt->fetchAll(), 'value', 'key');
```

Then add `facebook_url` to the array merged into every template. The
existing return statement is:

```php
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
        ], $data));
```

Add a `facebook_url` key:

```php
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
        ], $data));
```

- [ ] **Step 5: Add `facebook_url` to the admin settings save whitelist**

In `src/Controllers/Admin/SettingsController.php`, the constant is:

```php
    private const KEYS = [
        'site_name', 'contact_email', 'contact_phone',
        'shipping_address', 'shipping_map_url',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from',
        'gopay_go_id', 'gopay_client_id', 'gopay_client_secret', 'gopay_test_mode',
    ];
```

Add `'facebook_url'` in a new line near the other simple site settings:

```php
    private const KEYS = [
        'site_name', 'contact_email', 'contact_phone',
        'shipping_address', 'shipping_map_url',
        'facebook_url',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from',
        'gopay_go_id', 'gopay_client_id', 'gopay_client_secret', 'gopay_test_mode',
    ];
```

- [ ] **Step 6: Run the full test suite to confirm no regressions**

Run: `php vendor/bin/phpunit --testdox`
Expected: all existing tests still pass (37 tests, 59 assertions, or
current count) — this task adds no new model logic so no new tests are
expected to appear.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/V011__facebook_setting.sql \
        src/Controllers/BaseController.php \
        src/Controllers/Admin/SettingsController.php
git commit -m "feat: add facebook_url setting and expose it to public templates"
```

---

### Task 2: Admin settings UI

**Files:**
- Modify: `templates/admin/settings/index.twig`
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/ru.json`, `lang/admin/uk.json`, `lang/admin/sk.json`

**Interfaces:**
- Consumes: `facebook_url` POST field accepted by
  `SettingsController::save()` (added in Task 1, Step 5), and
  `settings.facebook_url` value passed into `admin/settings/index.twig` by
  `SettingsController::index()` (already generic — no change needed there).
- Consumes: `t()` Twig function (already registered by
  `AdminBaseController::renderAdmin()`).

- [ ] **Step 1: Add the "Social" section to the admin settings form**

In `templates/admin/settings/index.twig`, insert a new section right after
the existing "Web" section (which ends after the `contact_phone` field) and
before the "Shipping" (`<h3>{{ t('settings.shipping') }}</h3>`) section:

```twig
    <div class="form-group">
        <label>{{ t('settings.phone') }}</label>
        <input type="text" name="contact_phone" value="{{ settings.contact_phone ?? '' }}">
    </div>

    <h3>{{ t('settings.social') }}</h3>
    <div class="form-group">
        <label>{{ t('settings.facebook_url') }}</label>
        <input type="text" name="facebook_url" value="{{ settings.facebook_url ?? '' }}">
    </div>

    <h3>{{ t('settings.shipping') }}</h3>
```

(Only the two new blocks — the `<h3>Social</h3>` section and its field —
are additions; the surrounding "Web" and "Shipping" markup already exists
and is shown here only to anchor the insertion point.)

- [ ] **Step 2: Add translation keys to all 5 admin language files**

In `lang/admin/cs.json`, insert alphabetically (between
`settings.contact_email` and `settings.flash.updated`, and between
`settings.smtp_user` and `settings.test_mode`):

```json
  "settings.facebook_url": "Odkaz na Facebook",
```
```json
  "settings.social": "Sociální sítě",
```

In `lang/admin/en.json`:

```json
  "settings.facebook_url": "Facebook URL",
```
```json
  "settings.social": "Social media",
```

In `lang/admin/ru.json`:

```json
  "settings.facebook_url": "Ссылка на Facebook",
```
```json
  "settings.social": "Социальные сети",
```

In `lang/admin/uk.json`:

```json
  "settings.facebook_url": "Посилання на Facebook",
```
```json
  "settings.social": "Соціальні мережі",
```

In `lang/admin/sk.json`:

```json
  "settings.facebook_url": "Odkaz na Facebook",
```
```json
  "settings.social": "Sociálne siete",
```

Each key goes on its own line, keeping the existing alphabetical ordering
and trailing commas consistent with neighboring lines (see current
`settings.contact_email` / `settings.flash.updated` / `settings.smtp_user` /
`settings.test_mode` lines in each file for exact placement).

- [ ] **Step 3: Verify all 5 admin language files still parse as valid JSON**

```bash
for f in cs en ru uk sk; do php -r "json_decode(file_get_contents('lang/admin/$f.json'), true) === null && exit(1); echo '$f OK'.PHP_EOL;"; done
```

Expected: `cs OK`, `en OK`, `ru OK`, `uk OK`, `sk OK` — no PHP errors.

- [ ] **Step 4: Verify all 5 admin language files have identical key sets**

```bash
php -r '
$base = array_keys(json_decode(file_get_contents("lang/admin/cs.json"), true));
sort($base);
foreach (["en","ru","uk","sk"] as $l) {
    $keys = array_keys(json_decode(file_get_contents("lang/admin/$l.json"), true));
    sort($keys);
    $diff = array_merge(array_diff($base, $keys), array_diff($keys, $base));
    echo $l . ": " . (empty($diff) ? "OK" : "MISMATCH: " . implode(",", $diff)) . PHP_EOL;
}
'
```

Expected: `en: OK`, `ru: OK`, `uk: OK`, `sk: OK`.

- [ ] **Step 5: Manually verify the admin form**

Start the app (`php -S localhost:8080 -t www`), log into `/admin/login`,
visit `/admin/settings`. Confirm:
- A "Social" (or localized equivalent) section appears between "Web" and
  "Shipping" with a "Facebook URL" field pre-filled with the seeded URL.
- Changing the value and clicking Save persists it (reload the page, the
  new value is still there) and shows the "Settings saved." flash message.

- [ ] **Step 6: Commit**

```bash
git add templates/admin/settings/index.twig lang/admin/cs.json lang/admin/en.json lang/admin/ru.json lang/admin/uk.json lang/admin/sk.json
git commit -m "feat: add Facebook URL field to admin settings page"
```

---

### Task 3: Public footer "Follow us" section

**Files:**
- Modify: `templates/layout/base.twig`
- Modify: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`

**Interfaces:**
- Consumes: `facebook_url` template variable (produced by Task 1, Step 4)
  and the public `t()` Twig function (already registered by
  `BaseController::render()`).

- [ ] **Step 1: Add the footer markup**

In `templates/layout/base.twig`, the current footer is:

```twig
    <footer class="site-footer">
        <div class="container">
            <p>&copy; {{ "now"|date("Y") }} {{ t('site.name') }}</p>
        </div>
    </footer>
```

Change it to:

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

- [ ] **Step 2: Add the `footer.follow_us` translation key to all 5 public language files**

Insert alphabetically between `contact.title` and `gallery.back` in each
file.

`lang/cs.json`:
```json
  "footer.follow_us": "Sledujte nás",
```

`lang/en.json`:
```json
  "footer.follow_us": "Follow us",
```

`lang/ru.json`:
```json
  "footer.follow_us": "Подписывайтесь на нас",
```

`lang/uk.json`:
```json
  "footer.follow_us": "Підписуйтесь на нас",
```

`lang/sk.json`:
```json
  "footer.follow_us": "Sledujte nás",
```

- [ ] **Step 3: Verify all 5 public language files still parse and have identical keys**

```bash
for f in cs en ru uk sk; do php -r "json_decode(file_get_contents('lang/$f.json'), true) === null && exit(1); echo '$f OK'.PHP_EOL;"; done
php -r '
$base = array_keys(json_decode(file_get_contents("lang/cs.json"), true));
sort($base);
foreach (["en","ru","uk","sk"] as $l) {
    $keys = array_keys(json_decode(file_get_contents("lang/$l.json"), true));
    sort($keys);
    $diff = array_merge(array_diff($base, $keys), array_diff($keys, $base));
    echo $l . ": " . (empty($diff) ? "OK" : "MISMATCH: " . implode(",", $diff)) . PHP_EOL;
}
'
```

Expected: all `OK`.

- [ ] **Step 4: Manually verify the public footer**

With the app running (`php -S localhost:8080 -t www`), visit
`http://localhost:8080/cs/` (and at least one other language, e.g. `/en/`).
Confirm:
- The footer shows "Sledujte nás: Facebook" (or "Follow us: Facebook" on
  the English page) below the copyright line.
- The Facebook link opens
  `https://www.facebook.com/search/top?q=balonkytop%20cz` in a new tab.
- Temporarily clear the `facebook_url` value via the admin settings page,
  reload a public page, confirm the "Follow us" line disappears; then
  restore the value.

- [ ] **Step 5: Commit**

```bash
git add templates/layout/base.twig lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json
git commit -m "feat: show Follow us / Facebook link in the public site footer"
```
