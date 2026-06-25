# Category Auto-Translate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-language DeepL translation buttons to the admin category form so editors can auto-populate name and description for SK/EN/UA/RU from the Czech (CZ) fields.

**Architecture:** A `DeepL` service class reads the API key from the `settings` table and calls the DeepL free-tier API. A thin `/admin/translate` POST endpoint (closure in `routes.php`) wraps it and returns JSON. The category form gains per-language buttons that POST to this endpoint via `fetch` and populate the target tab's fields.

**Tech Stack:** PHP 8.1, Slim 4, PDO/MySQL, DeepL REST API v2, vanilla JS `fetch`

## Global Constraints

- Languages in order: `cs` (label CZ), `sk` (SK), `en` (EN), `uk` (UA), `ru` (RU)
- `uk` internal code maps to DeepL target code `UK` and display label `UA`
- DeepL free-tier endpoint: `https://api-free.deepl.com/v2/translate`
- API key stored in `settings` table under key `deepl_api_key`
- Admin templates are hard-coded Czech — no `t()` function
- All `/admin/*` routes protected by `AuthMiddleware` automatically
- PHPUnit 11, tests in `tests/Unit/`, real MySQL DB via Docker for model tests

---

### Task 1: Add `deepl_api_key` to settings table and admin UI

**Files:**
- Create: `database/migrations/V005__deepl_api_key_setting.sql`
- Modify: `src/Controllers/Admin/SettingsController.php`
- Modify: `templates/admin/settings/index.twig`

**Interfaces:**
- Produces: `settings` table row `deepl_api_key` readable by `DeepL` service in Task 2

- [ ] **Step 1: Create the migration file**

`database/migrations/V005__deepl_api_key_setting.sql`:
```sql
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES ('deepl_api_key', '');
```

- [ ] **Step 2: Apply migration locally**

```bash
curl -s "http://localhost:8080/migrate.php?token=8b1b4af4ff83a007dda3fc43ab1c7f43372884714905281f9d1e61cc46c3a781" | python3 -m json.tool
```
Expected:
```json
{"applied": ["V005__deepl_api_key_setting"], "count": 1}
```

- [ ] **Step 3: Add `deepl_api_key` to SettingsController KEYS**

In `src/Controllers/Admin/SettingsController.php`, change:
```php
    private const KEYS = [
        'site_name', 'contact_email', 'contact_phone',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from',
        'gopay_go_id', 'gopay_client_id', 'gopay_client_secret', 'gopay_test_mode',
    ];
```
to:
```php
    private const KEYS = [
        'site_name', 'contact_email', 'contact_phone',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from',
        'gopay_go_id', 'gopay_client_id', 'gopay_client_secret', 'gopay_test_mode',
        'deepl_api_key',
    ];
```

- [ ] **Step 4: Add DeepL section to settings template**

In `templates/admin/settings/index.twig`, add before `<div class="form-actions">`:
```twig
    <h3>DeepL (automatický překlad)</h3>
    <p style="color:#666;font-size:0.9rem;">API klíč pro překlad názvů kategorií. Získejte zdarma na <a href="https://www.deepl.com/pro-api" target="_blank">deepl.com/pro-api</a>.</p>
    <div class="form-group">
        <label>DeepL API klíč</label>
        <input type="password" name="deepl_api_key" value="{{ settings.deepl_api_key ?? '' }}" autocomplete="new-password">
    </div>
```

- [ ] **Step 5: Verify in browser**

Open `http://localhost:8080/admin/settings`. Confirm the DeepL section appears with a password field. Save with an empty value — confirm "Nastavení uloženo." flash appears.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/V005__deepl_api_key_setting.sql \
        src/Controllers/Admin/SettingsController.php \
        templates/admin/settings/index.twig
git commit -m "feat: add deepl_api_key to settings table and admin UI"
```

---

### Task 2: DeepL service class

**Files:**
- Create: `src/Services/DeepL.php`
- Create: `tests/Unit/Services/DeepLTest.php`

**Interfaces:**
- Consumes: `settings` table row `deepl_api_key` (from Task 1); `Database::getConnection()` PDO singleton
- Produces: `DeepL::translate(array $texts, string $targetLang, ?callable $transport = null): array`
  - `$texts`: indexed array of strings to translate (e.g. `['Balónky', 'Popis...']`)
  - `$targetLang`: DeepL target language code, one of `CS SK EN UK RU`
  - `$transport`: optional `callable(string $url, array $headers, string $body): string` — default uses curl; injectable for tests
  - Returns: indexed array of translated strings in same order as `$texts`
  - Throws `\RuntimeException` if API key missing, curl fails, or response malformed

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Services/DeepLTest.php`:
```php
<?php
namespace Tests\Unit\Services;

use App\Services\DeepL;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DeepLTest extends TestCase
{
    private function fakeTransport(string $responseBody): callable
    {
        return function (string $url, array $headers, string $body) use ($responseBody): string {
            return $responseBody;
        };
    }

    public function test_translate_returns_translated_texts(): void
    {
        $response = json_encode([
            'translations' => [
                ['detected_source_language' => 'CS', 'text' => 'Balloons'],
                ['detected_source_language' => 'CS', 'text' => 'Description'],
            ],
        ]);

        $result = DeepL::translate(['Balónky', 'Popis'], 'EN', $this->fakeTransport($response));

        $this->assertSame(['Balloons', 'Description'], $result);
    }

    public function test_translate_throws_on_malformed_response(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unexpected response/i');

        DeepL::translate(['Balónky'], 'EN', $this->fakeTransport('not-json'));
    }

    public function test_translate_throws_when_api_key_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/api key not configured/i');

        // Pass null transport — service should throw before making any HTTP call
        DeepL::translateWithKey('', ['Balónky'], 'EN');
    }

    public function test_transport_receives_correct_target_lang(): void
    {
        $capturedBody = null;
        $transport = function (string $url, array $headers, string $body) use (&$capturedBody): string {
            $capturedBody = $body;
            return json_encode(['translations' => [['text' => 'Balóny']]]);
        };

        DeepL::translate(['Balónky'], 'SK', $transport);

        $decoded = json_decode($capturedBody, true);
        $this->assertSame('SK', $decoded['target_lang']);
        $this->assertSame(['Balónky'], $decoded['text']);
    }
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php vendor/bin/phpunit tests/Unit/Services/DeepLTest.php --testdox
```
Expected: all 4 tests FAIL with "Class not found" or similar.

- [ ] **Step 3: Implement `src/Services/DeepL.php`**

```php
<?php
namespace App\Services;

use App\Models\Database;
use RuntimeException;

class DeepL
{
    private const ENDPOINT = 'https://api-free.deepl.com/v2/translate';
    private const VALID_TARGETS = ['CS', 'SK', 'EN', 'UK', 'RU'];

    public static function translate(array $texts, string $targetLang, ?callable $transport = null): array
    {
        $pdo = Database::getConnection();
        $key = $pdo->query("SELECT `value` FROM settings WHERE `key` = 'deepl_api_key'")->fetchColumn();

        return self::translateWithKey((string) $key, $texts, $targetLang, $transport);
    }

    public static function translateWithKey(string $key, array $texts, string $targetLang, ?callable $transport = null): array
    {
        if ($key === '') {
            throw new RuntimeException('DeepL API key not configured.');
        }

        $body    = json_encode(['text' => $texts, 'target_lang' => strtoupper($targetLang)]);
        $headers = [
            'Authorization: DeepL-Auth-Key ' . $key,
            'Content-Type: application/json',
        ];

        $transport  = $transport ?? self::curlTransport();
        $rawResponse = $transport(self::ENDPOINT, $headers, $body);

        $decoded = json_decode($rawResponse, true);
        if (!isset($decoded['translations']) || !is_array($decoded['translations'])) {
            throw new RuntimeException('DeepL unexpected response: ' . substr($rawResponse, 0, 200));
        }

        return array_map(fn($t) => $t['text'], $decoded['translations']);
    }

    private static function curlTransport(): callable
    {
        return function (string $url, array $headers, string $body): string {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 10,
            ]);
            $response = curl_exec($ch);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new RuntimeException('DeepL request failed: ' . $error);
            }

            return $response;
        };
    }
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
php vendor/bin/phpunit tests/Unit/Services/DeepLTest.php --testdox
```
Expected:
```
DeepL (Tests\Unit\Services\DeepL)
 ✔ Translate returns translated texts
 ✔ Translate throws on malformed response
 ✔ Translate throws when api key missing
 ✔ Transport receives correct target lang
```

- [ ] **Step 5: Commit**

```bash
git add src/Services/DeepL.php tests/Unit/Services/DeepLTest.php
git commit -m "feat: add DeepL service with injectable transport for testing"
```

---

### Task 3: AJAX `/admin/translate` endpoint

**Files:**
- Modify: `src/routes.php`

**Interfaces:**
- Consumes: `DeepL::translate(array $texts, string $targetLang): array` (from Task 2)
- Produces:
  - `POST /admin/translate` — JSON body `{"texts": string[], "target": string}`
  - Success `200`: `{"texts": string[]}`
  - Bad request `400`: `{"error": string}`
  - Server error `500`: `{"error": string}`

- [ ] **Step 1: Add the route closure in `src/routes.php`**

Inside the `$app->group('/admin', function (RouteCollectorProxy $group) use ($app)` block, after the settings routes, add:

```php
    // Auto-translate (DeepL)
    $group->post('/translate', function (Request $request, Response $response) {
        $body       = (array) $request->getParsedBody();
        $texts      = $body['texts'] ?? null;
        $targetLang = strtoupper(trim($body['target'] ?? ''));
        $allowed    = ['CS', 'SK', 'EN', 'UK', 'RU'];

        if (!is_array($texts) || count($texts) === 0 || !in_array($targetLang, $allowed, true)) {
            $response->getBody()->write(json_encode(['error' => 'Invalid parameters.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $translated = \App\Services\DeepL::translate($texts, $targetLang);
            $response->getBody()->write(json_encode(['texts' => $translated]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
```

- [ ] **Step 2: Verify endpoint exists and rejects bad requests**

```bash
curl -s -X POST http://localhost:8080/admin/translate \
  -d 'texts[]=hello&target=SK' | python3 -m json.tool
```
Expected: HTTP 302 redirect to `/admin/login` (no session). This confirms the route is registered and `AuthMiddleware` is protecting it.

- [ ] **Step 3: Commit**

```bash
git add src/routes.php
git commit -m "feat: add POST /admin/translate AJAX endpoint"
```

---

### Task 4: Category form — rename tabs, reorder, translate buttons, JS

**Files:**
- Modify: `templates/admin/categories/form.twig`

**Interfaces:**
- Consumes: `POST /admin/translate` endpoint from Task 3

- [ ] **Step 1: Replace the category form template**

Replace the entire contents of `templates/admin/categories/form.twig` with:

```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ category ? 'Upravit kategorii' : 'Nová kategorie' }}{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>{{ category ? 'Upravit kategorii' : 'Nová kategorie' }}</h1>
    <a href="/admin/categories" class="btn btn-secondary">← Zpět</a>
</div>
<form method="POST" action="{{ category ? '/admin/categories/' ~ category.id ~ '/edit' : '/admin/categories/new' }}" class="admin-form">
    <div class="form-group">
        <label>Slug (URL)</label>
        <input type="text" name="slug" value="{{ category.slug ?? '' }}" required>
    </div>
    <div class="form-group">
        <label>Pořadí</label>
        <input type="number" name="sort_order" value="{{ category.sort_order ?? 0 }}">
    </div>
    <h3>Překlady</h3>
    {% set lang_labels = {cs: 'CZ', sk: 'SK', en: 'EN', uk: 'UA', ru: 'RU'} %}
    <div class="lang-tabs">
        {% for lang in langs %}
        <button type="button" class="lang-tab {% if loop.first %}active{% endif %}" data-lang="{{ lang }}">{{ lang_labels[lang] ?? lang|upper }}</button>
        {% endfor %}
    </div>
    {% for lang in langs %}
    <div class="lang-panel {% if loop.first %}active{% endif %}" id="panel-{{ lang }}">
        <div class="form-group">
            <label>Název ({{ lang_labels[lang] ?? lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][name]" value="{{ translations[lang].name ?? '' }}">
        </div>
        <div class="form-group">
            <label>Popis ({{ lang_labels[lang] ?? lang|upper }})</label>
            <textarea name="t[{{ lang }}][description]">{{ translations[lang].description ?? '' }}</textarea>
        </div>
        {% if lang != 'cs' %}
        <div class="form-group">
            <button type="button" class="btn btn-secondary btn-translate" data-target-lang="{{ lang }}">
                Přeložit z češtiny
            </button>
            <span class="translate-status" style="margin-left:0.75rem;font-size:0.85rem;color:#666;"></span>
        </div>
        {% endif %}
    </div>
    {% endfor %}
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Uložit</button>
        <a href="/admin/categories" class="btn btn-secondary">Zrušit</a>
    </div>
</form>
{% block scripts %}
<script>
// Tab switching
document.querySelectorAll('.lang-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.lang-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.lang-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel-' + tab.dataset.lang).classList.add('active');
    });
});

// Slug auto-generation from CZ name
(function () {
    const slugInput = document.querySelector('input[name="slug"]');
    const csName    = document.querySelector('input[name="t[cs][name]"]');
    if (!slugInput || !csName) return;

    function toSlug(s) {
        return s.toLowerCase()
            .replace(/[áä]/g,'a').replace(/č/g,'c').replace(/ď/g,'d')
            .replace(/[éě]/g,'e').replace(/í/g,'i').replace(/ň/g,'n')
            .replace(/[óö]/g,'o').replace(/[řŕ]/g,'r').replace(/š/g,'s')
            .replace(/ť/g,'t').replace(/[úůü]/g,'u').replace(/ý/g,'y')
            .replace(/ž/g,'z')
            .replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
    }

    let locked = slugInput.value !== '';
    slugInput.addEventListener('input', () => { locked = slugInput.value !== ''; });
    csName.addEventListener('input', () => { if (!locked) slugInput.value = toSlug(csName.value); });
})();

// DeepL translate buttons
document.querySelectorAll('.btn-translate').forEach(btn => {
    btn.addEventListener('click', async () => {
        const targetLang = btn.dataset.targetLang;
        const panel      = document.getElementById('panel-' + targetLang);
        const status     = panel.querySelector('.translate-status');
        const nameInput  = document.querySelector('input[name="t[cs][name]"]');
        const descInput  = document.querySelector('textarea[name="t[cs][description]"]');

        if (!nameInput.value.trim() && !descInput.value.trim()) {
            status.textContent = 'Nejprve vyplňte český název.';
            status.style.color = '#c00';
            return;
        }

        btn.disabled       = true;
        btn.textContent    = 'Překládám…';
        status.textContent = '';

        // DeepL language code: uk → UK (already uppercase of internal code)
        const deeplTarget = targetLang.toUpperCase();

        try {
            const res = await fetch('/admin/translate', {
                method:  'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body:    new URLSearchParams({
                    'texts[]': nameInput.value,
                    'texts[1]': descInput.value,
                    target: deeplTarget,
                }),
            });

            const data = await res.json();

            if (!res.ok || data.error) {
                status.textContent = 'Překlad se nezdařil: ' + (data.error ?? res.status);
                status.style.color = '#c00';
                return;
            }

            panel.querySelector('input[name="t[' + targetLang + '][name]"]').value         = data.texts[0] ?? '';
            panel.querySelector('textarea[name="t[' + targetLang + '][description]"]').value = data.texts[1] ?? '';
            status.textContent = '✓ Přeloženo';
            status.style.color = '#080';
        } catch (e) {
            status.textContent = 'Překlad se nezdařil: ' + e.message;
            status.style.color = '#c00';
        } finally {
            btn.disabled    = false;
            btn.textContent = 'Přeložit z češtiny';
        }
    });
});
</script>
{% endblock %}
{% endblock %}
```

- [ ] **Step 2: Verify tabs render correctly in browser**

Open `http://localhost:8080/admin/categories/new`. Confirm:
- Tabs appear in order: CZ / SK / EN / UA / RU
- CZ tab is active by default, has no translate button
- SK, EN, UA, RU tabs each have a "Přeložit z češtiny" button
- Slug auto-generates from CZ name field

- [ ] **Step 3: Test translate button error state (no CZ input)**

On the new category form:
1. Leave CZ name empty
2. Click to SK tab
3. Click "Přeložit z češtiny"
Expected: inline message "Nejprve vyplňte český název." in red; no network request made.

- [ ] **Step 4: Test translate button with valid CZ input and real API key**

Pre-condition: set a valid DeepL API key in `/admin/settings`.
1. Type "Balónky" in CZ name, "Dekorace z balónků" in CZ description
2. Click SK tab → click "Přeložit z češtiny"
Expected: SK name and description fields populated, "✓ Přeloženo" shown in green.

If no real API key is available, skip to Task 4 Step 5 and verify the error handling path instead: with empty key, clicking translate should show "Překlad se nezdařil: DeepL API key not configured."

- [ ] **Step 5: Run full test suite to confirm no regressions**

```bash
php vendor/bin/phpunit --testdox
```
Expected: all existing tests pass (37 + 4 new DeepL tests = 41 tests).

- [ ] **Step 6: Commit**

```bash
git add templates/admin/categories/form.twig
git commit -m "feat: add per-language DeepL translate buttons to category form"
```

---

## Post-implementation checklist

- [ ] Deploy to prod: `source .env.prod && FTP_PASS="$FTP_PASS" ./scripts/deploy.sh`
- [ ] Run V005 migration on prod: `GET /migrate.php?token=…`
- [ ] Set DeepL API key in `/admin/settings` on prod
