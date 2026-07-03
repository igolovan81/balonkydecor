# Shipping Address Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show a warehouse address and Google Maps link on the public `/{lang}/shipping-payment` page, sourced from two new admin-editable settings.

**Architecture:** Two new rows in the existing generic `settings` key/value table, seeded with real values via a new migration. Admin Settings page gets a new "Shipping" fieldset (reusing the existing `SettingsController`/`settings/index.twig` pattern). The public page controller reads both values with an ad-hoc raw query (matching `ContactController`'s existing pattern) and the template conditionally renders them.

**Tech Stack:** Slim 4 (PHP), Twig 3, PDO/MySQL, plain translation JSON files — no new abstractions, no build step.

## Global Constraints

- Target page is `/{lang}/shipping-payment` only — the Contact page is not touched.
- Map link renders as a plain `target="_blank"` text link, not an embedded iframe.
- Seed values (exact, verbatim): address `skladový areál Kamýcká 234 a 235, 160 00 Praha 6`; map URL `https://maps.app.goo.gl/LfyD3DbX6TnMpBsd6?g_st=a`.
- No new shared settings-reading abstraction — follow `ContactController::send()`'s existing ad-hoc raw-query pattern for reading settings on the public side.
- Both new settings fields are plain `<input type="text">` in the admin form, matching `site_name`/`contact_phone`'s existing control type.

---

### Task 1: Seed the two new settings via migration

**Files:**
- Create: `database/migrations/V010__shipping_settings.sql`

**Interfaces:**
- Produces: `settings` rows with keys `shipping_address` and `shipping_map_url` — consumed by Task 2 (admin form) and Task 3 (public page).

- [ ] **Step 1: Write the migration**

Create `database/migrations/V010__shipping_settings.sql`:
```sql
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
  ('shipping_address', 'skladový areál Kamýcká 234 a 235, 160 00 Praha 6'),
  ('shipping_map_url', 'https://maps.app.goo.gl/LfyD3DbX6TnMpBsd6?g_st=a');
```

- [ ] **Step 2: Validate against the local dev DB**

This migration only adds two rows to an existing table (no `CREATE`/`ALTER`), so it's safe to apply directly to the local dev DB (unlike a schema-changing migration, there's no need for a throwaway container):

```bash
docker compose up -d
docker exec balonkydecor_db mysql -ubalonky -pbalonky balonkydecor -e "SELECT COUNT(*) FROM schema_migrations WHERE version='V010__shipping_settings';"
docker exec -i balonkydecor_db mysql -ubalonky -pbalonky balonkydecor < database/migrations/V010__shipping_settings.sql
docker exec balonkydecor_db mysql -ubalonky -pbalonky balonkydecor -e "INSERT INTO schema_migrations (version) VALUES ('V010__shipping_settings');"
docker exec balonkydecor_db mysql -ubalonky -pbalonky balonkydecor -e "SELECT \`key\`, \`value\` FROM settings WHERE \`key\` IN ('shipping_address','shipping_map_url');"
```
Expected: the first `SELECT COUNT(*)` returns `0` (not yet applied), and the final `SELECT` returns exactly 2 rows with the exact seed values from Step 1.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/V010__shipping_settings.sql
git commit -m "feat: seed shipping address and map link settings"
```

---

### Task 2: Add the Shipping fieldset to the admin Settings page

**Files:**
- Modify: `src/Controllers/Admin/SettingsController.php:10-13`
- Modify: `templates/admin/settings/index.twig`
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/sk.json`, `lang/admin/ru.json`, `lang/admin/uk.json`

**Interfaces:**
- Consumes: `settings` rows seeded in Task 1 (only needed for the local DB read to display current values in the form — the whitelist/form changes work regardless of migration state).
- Produces: `shipping_address`/`shipping_map_url` become admin-editable; Task 3's public page reads whatever value ends up in the `settings` table (either the Task 1 seed, or whatever an admin saves through this form afterward).

- [ ] **Step 1: Add the two keys to the whitelist**

In `src/Controllers/Admin/SettingsController.php`, the `KEYS` constant currently reads (lines 10-13):
```php
    private const KEYS = [
        'site_name', 'contact_email', 'contact_phone',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from',
        'gopay_go_id', 'gopay_client_id', 'gopay_client_secret', 'gopay_test_mode',
    ];
```
Change to:
```php
    private const KEYS = [
        'site_name', 'contact_email', 'contact_phone',
        'shipping_address', 'shipping_map_url',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from',
        'gopay_go_id', 'gopay_client_id', 'gopay_client_secret', 'gopay_test_mode',
    ];
```
No other changes needed in this controller — `index()` and `save()` already loop over `KEYS` generically.

- [ ] **Step 2: Add the Shipping fieldset to the form template**

In `templates/admin/settings/index.twig`, insert a new fieldset between the closing `</div>` of the `contact_phone` field group and the `<h3>{{ t('settings.smtp') }}</h3>` heading. The relevant existing block currently reads:
```twig
    <div class="form-group">
        <label>{{ t('settings.phone') }}</label>
        <input type="text" name="contact_phone" value="{{ settings.contact_phone ?? '' }}">
    </div>

    <h3>{{ t('settings.smtp') }}</h3>
```
Change to:
```twig
    <div class="form-group">
        <label>{{ t('settings.phone') }}</label>
        <input type="text" name="contact_phone" value="{{ settings.contact_phone ?? '' }}">
    </div>

    <h3>{{ t('settings.shipping') }}</h3>
    <div class="form-group">
        <label>{{ t('settings.shipping_address') }}</label>
        <input type="text" name="shipping_address" value="{{ settings.shipping_address ?? '' }}">
    </div>
    <div class="form-group">
        <label>{{ t('settings.shipping_map_url') }}</label>
        <input type="text" name="shipping_map_url" value="{{ settings.shipping_map_url ?? '' }}">
    </div>

    <h3>{{ t('settings.smtp') }}</h3>
```

- [ ] **Step 3: Add translation keys to all 5 admin lang files**

Each of `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/sk.json`, `lang/admin/ru.json`, `lang/admin/uk.json` currently has this block (lines 169-172):
```json
  "settings.phone": "...",
  "settings.save": "...",
  "settings.site_name": "...",
  "settings.smtp": "...",
```
Insert 3 new keys between `settings.save` and `settings.site_name` (alphabetical: `save` < `shipping` < `shipping_address` < `shipping_map_url` < `site_name`).

`lang/admin/cs.json`:
```json
  "settings.phone": "Telefon",
  "settings.save": "Uložit nastavení",
  "settings.shipping": "Doprava",
  "settings.shipping_address": "Adresa skladu",
  "settings.shipping_map_url": "Odkaz na mapu (Google Maps)",
  "settings.site_name": "Název webu",
  "settings.smtp": "SMTP (odesílání e-mailů)",
```

`lang/admin/en.json`:
```json
  "settings.phone": "Phone",
  "settings.save": "Save settings",
  "settings.shipping": "Shipping",
  "settings.shipping_address": "Warehouse address",
  "settings.shipping_map_url": "Map link (Google Maps)",
  "settings.site_name": "Site name",
  "settings.smtp": "SMTP (email sending)",
```

`lang/admin/sk.json`:
```json
  "settings.phone": "Telefón",
  "settings.save": "Uložiť nastavenie",
  "settings.shipping": "Doprava",
  "settings.shipping_address": "Adresa skladu",
  "settings.shipping_map_url": "Odkaz na mapu (Google Maps)",
  "settings.site_name": "Názov webu",
  "settings.smtp": "SMTP (odosielanie e-mailov)",
```

`lang/admin/ru.json`:
```json
  "settings.phone": "Телефон",
  "settings.save": "Сохранить настройки",
  "settings.shipping": "Доставка",
  "settings.shipping_address": "Адрес склада",
  "settings.shipping_map_url": "Ссылка на карту (Google Maps)",
  "settings.site_name": "Название сайта",
  "settings.smtp": "SMTP (отправка e-mailов)",
```

`lang/admin/uk.json`:
```json
  "settings.phone": "Телефон",
  "settings.save": "Зберегти налаштування",
  "settings.shipping": "Доставка",
  "settings.shipping_address": "Адреса складу",
  "settings.shipping_map_url": "Посилання на карту (Google Maps)",
  "settings.site_name": "Назва сайту",
  "settings.smtp": "SMTP (відправка e-mailів)",
```

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
php -S localhost:8080 -t www > /tmp/php-shipping-settings.log 2>&1 &
echo $! > /tmp/php-shipping-settings.pid
for i in $(seq 1 15); do curl -sf http://localhost:8080/cs/ >/dev/null && break; sleep 1; done
```
Log into the admin panel (an admin user must already exist locally — if not, visit `http://localhost:8080/admin/setup` to create one), navigate to `http://localhost:8080/admin/settings`, and confirm:
- A "Doprava" heading appears between the phone field and the SMTP section
- Both new fields show the seeded values from Task 1 (`skladový areál Kamýcká 234 a 235, 160 00 Praha 6` and the maps.app.goo.gl URL) if Task 1's migration was applied locally, or are empty if not
- Editing and saving the fields persists correctly (reload the page, confirm the new value sticks)

```bash
kill "$(cat /tmp/php-shipping-settings.pid)" 2>/dev/null
```

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/Admin/SettingsController.php templates/admin/settings/index.twig \
        lang/admin/cs.json lang/admin/en.json lang/admin/sk.json lang/admin/ru.json lang/admin/uk.json
git commit -m "feat: add shipping address settings to admin settings page"
```

---

### Task 3: Render the address and map link on the public Shipping page

**Files:**
- Modify: `src/Controllers/PageController.php`
- Modify: `templates/public/shipping.twig`
- Modify: `lang/cs.json`, `lang/en.json`, `lang/sk.json`, `lang/ru.json`, `lang/uk.json`

**Interfaces:**
- Consumes: `settings.shipping_address` / `settings.shipping_map_url` DB rows (Task 1 seeds them; Task 2's admin form can change them — this task must read whatever is currently in the table, not a hardcoded value).
- Produces: none (leaf UI change).

- [ ] **Step 1: Fetch the settings in the controller**

In `src/Controllers/PageController.php`, add the `Database` import (currently only `PageModel` is imported):
```php
<?php
namespace App\Controllers;

use App\Models\Database;
use App\Models\PageModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PageController extends BaseController
{
    public function services(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        $page = PageModel::find('services', $lang);
        return $this->render($request, $response, 'public/services.twig', [
            'page' => $page,
        ]);
    }

    public function shippingPayment(Request $request, Response $response, array $args): Response
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('shipping_address', 'shipping_map_url')");
        $stmt->execute();
        $settings = array_column($stmt->fetchAll(), 'value', 'key');

        return $this->render($request, $response, 'public/shipping.twig', [
            'shipping_address' => $settings['shipping_address'] ?? '',
            'shipping_map_url' => $settings['shipping_map_url'] ?? '',
        ]);
    }
}
```

- [ ] **Step 2: Render the address and map link in the template**

`templates/public/shipping.twig` currently reads:
```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('shipping.title') }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{{ t('shipping.body') }}{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container">
        <h1>{{ t('shipping.title') }}</h1>
    </div>
</section>
<div class="container content-page">
    <p>{{ t('shipping.body') }}</p>
</div>
{% endblock %}
```
Change the content block to:
```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('shipping.title') }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{{ t('shipping.body') }}{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container">
        <h1>{{ t('shipping.title') }}</h1>
    </div>
</section>
<div class="container content-page">
    <p>{{ t('shipping.body') }}</p>
    {% if shipping_address %}
    <p>{{ shipping_address }}
        {% if shipping_map_url %}
        <br><a href="{{ shipping_map_url }}" target="_blank" rel="noopener">{{ t('shipping.map_link') }}</a>
        {% endif %}
    </p>
    {% endif %}
</div>
{% endblock %}
```

- [ ] **Step 3: Add the `shipping.map_link` translation key to all 5 public lang files**

Each of `lang/cs.json`, `lang/en.json`, `lang/sk.json`, `lang/ru.json`, `lang/uk.json` currently has (lines 63-64):
```json
  "shipping.body": "...",
  "shipping.title": "...",
```
Insert the new key between them (alphabetical: `body` < `map_link` < `title`).

`lang/cs.json`:
```json
  "shipping.body": "Podrobnosti o dopravě a platbě budou brzy k dispozici.",
  "shipping.map_link": "Zobrazit na mapě",
  "shipping.title": "Doprava a platba",
```

`lang/en.json`:
```json
  "shipping.body": "Details about shipping and payment will be available soon.",
  "shipping.map_link": "View on Google Maps",
  "shipping.title": "Shipping and payment",
```

`lang/sk.json`:
```json
  "shipping.body": "Podrobnosti o doprave a platbe budú čoskoro k dispozícii.",
  "shipping.map_link": "Zobraziť na mape",
  "shipping.title": "Doprava a platba",
```

`lang/ru.json`:
```json
  "shipping.body": "Информация о доставке и оплате скоро появится.",
  "shipping.map_link": "Посмотреть на карте",
  "shipping.title": "Доставка и оплата",
```

`lang/uk.json`:
```json
  "shipping.body": "Інформація про доставку та оплату незабаром з'явиться.",
  "shipping.map_link": "Переглянути на карті",
  "shipping.title": "Доставка та оплата",
```

- [ ] **Step 4: Verify all 5 public files are still valid JSON with identical key sets**

```bash
for f in lang/cs.json lang/en.json lang/sk.json lang/ru.json lang/uk.json; do
  php -r "json_decode(file_get_contents('$f'), true) !== null || exit(1);" || echo "INVALID: $f"
done
php -r '
$files = ["cs","en","sk","ru","uk"];
$base = array_keys(json_decode(file_get_contents("lang/cs.json"), true));
sort($base);
foreach ($files as $f) {
    $keys = array_keys(json_decode(file_get_contents("lang/$f.json"), true));
    sort($keys);
    if ($keys !== $base) { echo "MISMATCH in $f\n"; }
}
echo "done\n";
'
```
Expected: no `INVALID` or `MISMATCH` lines, ends with `done`.

- [ ] **Step 5: Run the full test suite**

```bash
docker compose up -d
php vendor/bin/phpunit --testdox 2>&1 | tail -10
```
Expected: 100% green (this task adds no new PHP logic paths covered by existing tests, and doesn't change any tested behavior — no test count regression).

- [ ] **Step 6: Manually verify all 5 languages render the address and map link**

```bash
php -S localhost:8080 -t www > /tmp/php-shipping-public.log 2>&1 &
echo $! > /tmp/php-shipping-public.pid
for i in $(seq 1 15); do curl -sf http://localhost:8080/cs/ >/dev/null && break; sleep 1; done
for l in cs en sk ru uk; do
  echo "== $l =="
  curl -s "http://localhost:8080/$l/shipping-payment" | grep -A2 "content-page"
done
kill "$(cat /tmp/php-shipping-public.pid)" 2>/dev/null
```
Expected: if Task 1's migration was applied locally (either directly or via `database/reset.sh`, which re-applies the full chain), each language's output includes the address text (`skladový areál Kamýcká 234 a 235, 160 00 Praha 6`, unchanged across languages since it's a proper-noun address, not translated) and a "View on Google Maps"/localized-equivalent link pointing at the maps.app.goo.gl URL. If the migration wasn't applied locally, the `{% if shipping_address %}` block correctly renders nothing (no blank paragraph) — either state confirms the conditional logic works correctly.

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/PageController.php templates/public/shipping.twig \
        lang/cs.json lang/en.json lang/sk.json lang/ru.json lang/uk.json
git commit -m "feat: render shipping address and map link on the shipping-payment page"
```

---

## Final Verification

- [ ] **Step 1: Run the full suite one more time**

```bash
docker compose up -d
php vendor/bin/phpunit --testdox 2>&1 | tail -10
```
Expected: all tests pass.

- [ ] **Step 2: End-to-end smoke test — admin edit reflects on the public page**

```bash
docker exec -i balonkydecor_db mysql -ubalonky -pbalonky balonkydecor < database/migrations/V010__shipping_settings.sql 2>/dev/null
docker exec balonkydecor_db mysql -ubalonky -pbalonky balonkydecor -e \
  "UPDATE settings SET value='Test Address 123' WHERE \`key\`='shipping_address';"
php -S localhost:8080 -t www > /tmp/php-final.log 2>&1 &
echo $! > /tmp/php-final.pid
for i in $(seq 1 15); do curl -sf http://localhost:8080/cs/ >/dev/null && break; sleep 1; done
curl -s http://localhost:8080/cs/shipping-payment | grep -o "Test Address 123"
docker exec balonkydecor_db mysql -ubalonky -pbalonky balonkydecor -e \
  "UPDATE settings SET value='skladový areál Kamýcká 234 a 235, 160 00 Praha 6' WHERE \`key\`='shipping_address';"
kill "$(cat /tmp/php-final.pid)" 2>/dev/null
```
Expected: `Test Address 123` is found in the public page output, confirming the page reads live from the `settings` table (not a cached/hardcoded value); the final `UPDATE` restores the real seed value for local dev.
