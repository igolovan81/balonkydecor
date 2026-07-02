# Preferred-Language Auto-Translate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the admin product/category forms translate from each admin user's preferred language (instead of hardcoded Czech), both automatically on save and via a manual per-tab button, and make the preferred-language tab the default-selected, persistently-marked tab.

**Architecture:** Generalize the existing `Translator` service to take an explicit source language instead of assuming Czech, add a new `Translator::autoFill()` helper that fills blank translation fields from the source language, wire it into `ProductController`/`CategoryController` save paths, and update the `/admin/translate` endpoint plus both admin form templates (and their JS) to use `admin_lang` (already available on every admin request) as the source/default/highlighted language.

**Tech Stack:** PHP 8 / Slim 4, Twig 3, vanilla JS (no build step), PHPUnit 11, MyMemory translation API (`https://api.mymemory.translated.net/get`).

## Global Constraints

- Supported languages (uppercase codes for `Translator`, lowercase for everything else): `CS, SK, EN, UK, RU`.
- Category translatable fields: `name`, `description`.
- Product translatable fields: `name`, `description`, `meta_title`, `meta_desc`.
- Auto-fill-on-save only fills a target-language field when it is empty **and** the source-language field is non-empty. It never overwrites a field that already has a value.
- If a translation call fails for a language during auto-fill, that language's fields are left blank and the save proceeds normally — no error shown, no partial-language state (a language's fields are filled all-or-nothing per save, since all its missing fields are requested in one `translate()` call).
- The manual "Translate" button's source language is always the requesting admin's `admin_lang` (read server-side from the request attribute set by `AdminLangMiddleware`), never client-supplied.
- The "Translate" button is not shown on the preferred-language tab itself.
- If the `/admin/translate` endpoint is called with `target == source` (uppercase-normalized), it returns HTTP 400 `{"error": "Invalid parameters."}`.
- The preferred-language tab/panel is the default-active one on page load (`lang == admin_lang`, replacing today's "first tab in the array" behavior).
- The preferred-language tab keeps a persistent visual marker (a `★ ` prefix and a `.preferred` CSS class) regardless of which tab is currently active.
- JS runtime strings (loading/error messages inside the translate button handler) stay hardcoded Czech text, per the existing convention already noted in `categories/form.twig`'s JS — this is admin-tool chrome, not public-facing i18n.
- Scope is products and categories only. No changes to blog, pages, gallery, or `AdminLangMiddleware`/language-switcher behavior.

---

## File Structure

- `src/Services/Translator.php` — add explicit `$sourceLang` param to `translate()`, add new `autoFill()` method.
- `tests/Unit/Services/TranslatorTest.php` — update existing tests for the new signature, add tests for source validation, same-source-target guard, and `autoFill()`.
- `src/routes.php` — `/admin/translate` closure reads `admin_lang` off the request as the source.
- `src/Controllers/Admin/ProductController.php` / `src/Controllers/Admin/CategoryController.php` — call `Translator::autoFill()` before persisting translations in `createSubmit`/`editSubmit`.
- `lang/admin/{cs,en,sk,ru,uk}.json` — reword `categories.form.translate_btn` to be language-neutral; add `products.form.translate_btn`.
- `templates/admin/categories/form.twig` — default/highlight preferred tab, generalize translate-button JS source.
- `templates/admin/products/form.twig` — default/highlight preferred tab, add translate button UI + JS (currently missing entirely).
- `www/assets/css/admin.css` — add `.lang-tab.preferred` styling.

---

## Task 1: Generalize `Translator::translate()` to take an explicit source language

**Files:**
- Modify: `src/Services/Translator.php`
- Test: `tests/Unit/Services/TranslatorTest.php`

**Interfaces:**
- Produces: `Translator::translate(array $texts, string $sourceLang, string $targetLang, ?callable $transport = null): array` — throws `RuntimeException` if `$sourceLang` or `$targetLang` isn't one of `CS, SK, EN, UK, RU` (case-insensitive), or if they normalize to the same value.

- [ ] **Step 1: Replace the test file with the updated-signature tests**

Replace the full contents of `tests/Unit/Services/TranslatorTest.php` with:

```php
<?php
namespace Tests\Unit\Services;

use App\Services\Translator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TranslatorTest extends TestCase
{
    private function fakeTransport(string $responseBody): callable
    {
        return function (string $url) use ($responseBody): string {
            return $responseBody;
        };
    }

    public function test_translate_returns_translated_texts(): void
    {
        $transport = function (string $url): string {
            if (str_contains($url, rawurlencode('Balónky'))) {
                return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Balloons']]);
            }
            return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Description']]);
        };

        $result = Translator::translate(['Balónky', 'Popis'], 'CS', 'EN', $transport);

        $this->assertSame(['Balloons', 'Description'], $result);
    }

    public function test_translate_throws_on_malformed_response(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unexpected response/i');

        Translator::translate(['Balónky'], 'CS', 'EN', $this->fakeTransport('not-json'));
    }

    public function test_translate_throws_on_invalid_target_lang(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid target language/i');

        Translator::translate(['Balónky'], 'CS', 'DE', $this->fakeTransport('{}'));
    }

    public function test_translate_throws_on_invalid_source_lang(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid source language/i');

        Translator::translate(['Balónky'], 'DE', 'EN', $this->fakeTransport('{}'));
    }

    public function test_translate_throws_when_source_equals_target(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/source and target language are the same/i');

        Translator::translate(['Balónky'], 'EN', 'en', $this->fakeTransport('{}'));
    }

    public function test_transport_receives_correct_langpair(): void
    {
        $capturedUrl = null;
        $transport = function (string $url) use (&$capturedUrl): string {
            $capturedUrl = $url;
            return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Balóny']]);
        };

        Translator::translate(['Balónky'], 'CS', 'SK', $transport);

        $this->assertStringContainsString('langpair=cs|sk', $capturedUrl);
        $this->assertStringContainsString(rawurlencode('Balónky'), $capturedUrl);
    }

    public function test_transport_receives_non_czech_source_langpair(): void
    {
        $capturedUrl = null;
        $transport = function (string $url) use (&$capturedUrl): string {
            $capturedUrl = $url;
            return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Balóny']]);
        };

        Translator::translate(['Balloons'], 'EN', 'SK', $transport);

        $this->assertStringContainsString('langpair=en|sk', $capturedUrl);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Services/TranslatorTest.php --testdox`
Expected: FAIL — `translate()` currently only accepts `(texts, targetLang, transport)`, so calls passing 4 positional args (or a string in the wrong slot) will throw a `TypeError` or fail assertions.

- [ ] **Step 3: Replace `Translator::translate()` with the source-aware implementation**

Replace the full contents of `src/Services/Translator.php` with:

```php
<?php
namespace App\Services;

use RuntimeException;

class Translator
{
    private const ENDPOINT    = 'https://api.mymemory.translated.net/get';
    private const VALID_LANGS = ['CS', 'SK', 'EN', 'UK', 'RU'];
    private const LANG_MAP    = ['CS' => 'cs', 'SK' => 'sk', 'EN' => 'en', 'UK' => 'uk', 'RU' => 'ru'];

    public static function translate(array $texts, string $sourceLang, string $targetLang, ?callable $transport = null): array
    {
        $sourceLang = strtoupper($sourceLang);
        $targetLang = strtoupper($targetLang);

        if (!in_array($sourceLang, self::VALID_LANGS, true)) {
            throw new RuntimeException('Invalid source language: ' . $sourceLang);
        }

        if (!in_array($targetLang, self::VALID_LANGS, true)) {
            throw new RuntimeException('Invalid target language: ' . $targetLang);
        }

        if ($sourceLang === $targetLang) {
            throw new RuntimeException('Source and target language are the same: ' . $targetLang);
        }

        $sourceCode = self::LANG_MAP[$sourceLang];
        $targetCode = self::LANG_MAP[$targetLang];
        $transport  = $transport ?? self::curlTransport();
        $results    = [];

        foreach ($texts as $text) {
            $url  = self::ENDPOINT . '?q=' . rawurlencode((string) $text) . '&langpair=' . $sourceCode . '|' . $targetCode;
            $raw  = $transport($url);
            $data = json_decode($raw, true);

            if (!isset($data['responseData']['translatedText'])) {
                throw new RuntimeException('MyMemory unexpected response: ' . substr($raw, 0, 200));
            }

            if (isset($data['responseStatus']) && $data['responseStatus'] !== 200) {
                throw new RuntimeException('MyMemory error ' . $data['responseStatus'] . ': ' . $data['responseData']['translatedText']);
            }

            $results[] = $data['responseData']['translatedText'];
        }

        return $results;
    }

    private static function curlTransport(): callable
    {
        return function (string $url): string {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => ['User-Agent: BalonkyDecor/1.0'],
            ]);
            $response = curl_exec($ch);
            $error    = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                throw new RuntimeException('MyMemory request failed: ' . $error);
            }

            if ($httpCode !== 200) {
                throw new RuntimeException('MyMemory HTTP error ' . $httpCode);
            }

            return $response;
        };
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Services/TranslatorTest.php --testdox`
Expected: PASS, all 7 tests green.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Translator.php tests/Unit/Services/TranslatorTest.php
git commit -m "feat: generalize Translator to accept an explicit source language"
```

---

## Task 2: Add `Translator::autoFill()` for auto-fill-on-save

**Files:**
- Modify: `src/Services/Translator.php`
- Test: `tests/Unit/Services/TranslatorTest.php`

**Interfaces:**
- Consumes: `Translator::translate(array $texts, string $sourceLang, string $targetLang, ?callable $transport = null): array` from Task 1.
- Produces: `Translator::autoFill(array $translations, string $sourceLang, array $allLangs, array $fields, ?callable $transport = null): array` — returns an augmented copy of `$translations` (shape: `[langCode => [field => value, ...], ...]`) with empty target fields filled in from the source language where possible. Never mutates fields that already have a non-empty value. Swallows any `Throwable` from `translate()` per-language (leaving that language's requested fields untouched) so the caller never has to catch anything.

- [ ] **Step 1: Add failing tests for `autoFill()`**

Add these four methods inside the `TranslatorTest` class (before the closing `}` of the class), right after `test_transport_receives_non_czech_source_langpair`:

```php
    public function test_autofill_fills_empty_target_fields_from_source(): void
    {
        $transport = function (string $url): string {
            if (str_contains($url, rawurlencode('Balónky'))) {
                return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Balloons']]);
            }
            return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Description here']]);
        };

        $translations = [
            'cs' => ['name' => 'Balónky', 'description' => 'Popis'],
            'en' => ['name' => '', 'description' => ''],
        ];

        $result = Translator::autoFill($translations, 'cs', ['cs', 'en'], ['name', 'description'], $transport);

        $this->assertSame('Balloons', $result['en']['name']);
        $this->assertSame('Description here', $result['en']['description']);
    }

    public function test_autofill_skips_language_with_nothing_to_fill(): void
    {
        $calls = 0;
        $transport = function (string $url) use (&$calls): string {
            $calls++;
            return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'x']]);
        };

        $translations = [
            'cs' => ['name' => 'Balónky', 'description' => 'Popis'],
            'en' => ['name' => 'Balloons', 'description' => 'Description'],
        ];

        $result = Translator::autoFill($translations, 'cs', ['cs', 'en'], ['name', 'description'], $transport);

        $this->assertSame(0, $calls);
        $this->assertSame('Balloons', $result['en']['name']);
        $this->assertSame('Description', $result['en']['description']);
    }

    public function test_autofill_leaves_language_blank_when_translation_fails(): void
    {
        $transport = function (string $url): string {
            return 'not-json';
        };

        $translations = [
            'cs' => ['name' => 'Balónky', 'description' => 'Popis'],
            'en' => ['name' => '', 'description' => ''],
        ];

        $result = Translator::autoFill($translations, 'cs', ['cs', 'en'], ['name', 'description'], $transport);

        $this->assertSame('', $result['en']['name']);
        $this->assertSame('', $result['en']['description']);
    }

    public function test_autofill_does_not_overwrite_existing_partial_value(): void
    {
        $transport = function (string $url): string {
            return json_encode(['responseStatus' => 200, 'responseData' => ['translatedText' => 'Description here']]);
        };

        $translations = [
            'cs' => ['name' => 'Balónky', 'description' => 'Popis'],
            'en' => ['name' => 'Custom Name', 'description' => ''],
        ];

        $result = Translator::autoFill($translations, 'cs', ['cs', 'en'], ['name', 'description'], $transport);

        $this->assertSame('Custom Name', $result['en']['name']);
        $this->assertSame('Description here', $result['en']['description']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Services/TranslatorTest.php --testdox`
Expected: FAIL — `Translator::autoFill()` does not exist yet (`Error: Call to undefined method`).

- [ ] **Step 3: Implement `autoFill()`**

In `src/Services/Translator.php`, add this method directly after `translate()` and before `curlTransport()`:

```php
    public static function autoFill(array $translations, string $sourceLang, array $allLangs, array $fields, ?callable $transport = null): array
    {
        $source = $translations[$sourceLang] ?? [];

        foreach ($allLangs as $lang) {
            if ($lang === $sourceLang) {
                continue;
            }

            $missingFields = [];
            foreach ($fields as $field) {
                $targetValue = trim((string) ($translations[$lang][$field] ?? ''));
                $sourceValue = trim((string) ($source[$field] ?? ''));
                if ($targetValue === '' && $sourceValue !== '') {
                    $missingFields[] = $field;
                }
            }

            if (!$missingFields) {
                continue;
            }

            $texts = array_map(static fn (string $field) => $source[$field], $missingFields);

            try {
                $translated = self::translate($texts, $sourceLang, $lang, $transport);
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($missingFields as $i => $field) {
                $translations[$lang][$field] = $translated[$i] ?? '';
            }
        }

        return $translations;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Services/TranslatorTest.php --testdox`
Expected: PASS, all 11 tests green.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Translator.php tests/Unit/Services/TranslatorTest.php
git commit -m "feat: add Translator::autoFill for filling blank translations from a source language"
```

---

## Task 3: `/admin/translate` endpoint uses the admin's preferred language as source

**Files:**
- Modify: `src/routes.php:93-113`

**Interfaces:**
- Consumes: `Translator::translate(array $texts, string $sourceLang, string $targetLang, ?callable $transport = null): array` from Task 1. `$request->getAttribute('admin_lang', 'cs')` (already set by `AdminLangMiddleware`, which runs on this route group).

There is no existing automated test harness for Slim route closures in this repo (only PHPUnit unit tests for services/models exist) — this task is verified manually in Task 8.

- [ ] **Step 1: Update the `/admin/translate` closure**

In `src/routes.php`, replace the existing closure (currently lines 93-113):

```php
    // Auto-translate (MyMemory)
    $group->post('/translate', function ($request, $response) {
        $body       = json_decode((string) $request->getBody(), true) ?? [];
        $texts      = $body['texts'] ?? null;
        $targetLang = strtoupper(trim(is_string($body['target'] ?? '') ? ($body['target'] ?? '') : ''));
        $allowed    = ['CS', 'SK', 'EN', 'UK', 'RU'];

        if (!is_array($texts) || count($texts) === 0 || !in_array($targetLang, $allowed, true)) {
            $response->getBody()->write(json_encode(['error' => 'Invalid parameters.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $translated = \App\Services\Translator::translate($texts, $targetLang);
            $response->getBody()->write(json_encode(['texts' => $translated]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
```

with:

```php
    // Auto-translate (MyMemory) — source is always the requesting admin's preferred language
    $group->post('/translate', function ($request, $response) {
        $body       = json_decode((string) $request->getBody(), true) ?? [];
        $texts      = $body['texts'] ?? null;
        $targetLang = strtoupper(trim(is_string($body['target'] ?? '') ? ($body['target'] ?? '') : ''));
        $sourceLang = strtoupper((string) $request->getAttribute('admin_lang', 'cs'));
        $allowed    = ['CS', 'SK', 'EN', 'UK', 'RU'];

        if (!is_array($texts) || count($texts) === 0 || !in_array($targetLang, $allowed, true) || $targetLang === $sourceLang) {
            $response->getBody()->write(json_encode(['error' => 'Invalid parameters.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $translated = \App\Services\Translator::translate($texts, $sourceLang, $targetLang);
            $response->getBody()->write(json_encode(['texts' => $translated]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
```

- [ ] **Step 2: Sanity-check the file still parses**

Run: `php -l src/routes.php`
Expected: `No syntax errors detected in src/routes.php`

- [ ] **Step 3: Commit**

```bash
git add src/routes.php
git commit -m "feat: derive /admin/translate source language from the admin's session"
```

---

## Task 4: Wire auto-fill-on-save into `ProductController` and `CategoryController`

**Files:**
- Modify: `src/Controllers/Admin/ProductController.php:32-45` (`createSubmit`), `:61-75` (`editSubmit`)
- Modify: `src/Controllers/Admin/CategoryController.php:27-34` (`createSubmit`), `:48-56` (`editSubmit`)

**Interfaces:**
- Consumes: `Translator::autoFill(array $translations, string $sourceLang, array $allLangs, array $fields, ?callable $transport = null): array` from Task 2.

No automated controller-level tests exist for these methods (no HTTP/functional test harness in this repo — confirmed during design). Verified manually in Task 8. `ProductModelTest`/`CategoryModelTest` are unaffected since `setTranslations()` itself is unchanged.

- [ ] **Step 1: Update `ProductController`**

In `src/Controllers/Admin/ProductController.php`, add a new constant next to the existing `LANGS` constant (around line 12):

```php
    private const LANGS                = ['cs', 'en', 'ru', 'uk', 'sk'];
    private const TRANSLATABLE_FIELDS  = ['name', 'description', 'meta_title', 'meta_desc'];
    private const UPLOAD_DIR           = __DIR__ . '/../../../www/assets/uploads/products';
```

Then in `createSubmit`, replace:

```php
        ProductModel::setTranslations($id, $body['t'] ?? []);
```

with:

```php
        $translations = \App\Services\Translator::autoFill(
            $body['t'] ?? [],
            $request->getAttribute('admin_lang', 'cs'),
            self::LANGS,
            self::TRANSLATABLE_FIELDS
        );
        ProductModel::setTranslations($id, $translations);
```

And in `editSubmit`, replace the same line (`ProductModel::setTranslations($id, $body['t'] ?? []);`) with the identical block above.

- [ ] **Step 2: Update `CategoryController`**

In `src/Controllers/Admin/CategoryController.php`, add a new constant next to the existing `LANGS` constant (around line 10):

```php
    private const LANGS               = ['cs', 'sk', 'en', 'uk', 'ru'];
    private const TRANSLATABLE_FIELDS = ['name', 'description'];
```

Then in `createSubmit`, replace:

```php
        CategoryModel::setTranslations($id, $body['t'] ?? []);
```

with:

```php
        $translations = \App\Services\Translator::autoFill(
            $body['t'] ?? [],
            $request->getAttribute('admin_lang', 'cs'),
            self::LANGS,
            self::TRANSLATABLE_FIELDS
        );
        CategoryModel::setTranslations($id, $translations);
```

And in `editSubmit`, replace the same line (`CategoryModel::setTranslations($id, $body['t'] ?? []);`) with the identical block above.

- [ ] **Step 3: Run the full test suite to confirm nothing broke**

Run: `php vendor/bin/phpunit --testdox`
Expected: PASS — all existing tests green (model tests call `setTranslations()` directly and don't go through the controllers, so they're unaffected).

- [ ] **Step 4: Commit**

```bash
git add src/Controllers/Admin/ProductController.php src/Controllers/Admin/CategoryController.php
git commit -m "feat: auto-fill blank product/category translations on save"
```

---

## Task 5: Category form — preferred-tab default/highlight, generalized translate button

**Files:**
- Modify: `templates/admin/categories/form.twig`
- Modify: `www/assets/css/admin.css:38-43`
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/sk.json`, `lang/admin/ru.json`, `lang/admin/uk.json`

**Interfaces:**
- Consumes: `admin_lang` (Twig variable, already injected into every admin template render by `AdminBaseController::renderAdmin()` — no controller change needed). `POST /admin/translate` from Task 3 (now source-agnostic on the server side).

No automated template/JS tests exist in this repo. Verified manually in Task 8.

- [ ] **Step 1: Reword `categories.form.translate_btn` in all 5 admin lang files**

The button previously always said "Translate from Czech" — now the source can be any preferred language, so reword it to be language-neutral. Update the `categories.form.translate_btn` line in each file:

`lang/admin/cs.json:46`: `"categories.form.translate_btn": "Přeložit z výchozího jazyka",`
`lang/admin/en.json:46`: `"categories.form.translate_btn": "Translate from default language",`
`lang/admin/sk.json:46`: `"categories.form.translate_btn": "Preložiť z predvoleného jazyka",`
`lang/admin/ru.json:46`: `"categories.form.translate_btn": "Перевести с языка по умолчанию",`
`lang/admin/uk.json:46`: `"categories.form.translate_btn": "Перекласти з мови за замовчуванням",`

- [ ] **Step 2: Add `.lang-tab.preferred` CSS**

In `www/assets/css/admin.css`, replace lines 38-43:

```css
/* Lang tabs */
.lang-tabs { display:flex; gap:0.5rem; margin-bottom:1rem; border-bottom:2px solid #eee; }
.lang-tab { padding:0.5rem 1rem; cursor:pointer; border:none; background:none; font-size:0.9rem; color:#777; border-bottom:2px solid transparent; margin-bottom:-2px; }
.lang-tab.active { color:#e91e8c; border-bottom-color:#e91e8c; font-weight:600; }
.lang-panel { display:none; }
.lang-panel.active { display:block; }
```

with:

```css
/* Lang tabs */
.lang-tabs { display:flex; gap:0.5rem; margin-bottom:1rem; border-bottom:2px solid #eee; }
.lang-tab { padding:0.5rem 1rem; cursor:pointer; border:none; background:none; font-size:0.9rem; color:#777; border-bottom:2px solid transparent; margin-bottom:-2px; }
.lang-tab.active { color:#e91e8c; border-bottom-color:#e91e8c; font-weight:600; }
.lang-tab.preferred { color:#b8006b; }
.lang-tab.preferred.active { color:#e91e8c; }
.lang-panel { display:none; }
.lang-panel.active { display:block; }
```

- [ ] **Step 3: Update `templates/admin/categories/form.twig`**

Replace the full file contents with:

```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ category ? t('categories.form.title_edit') : t('categories.form.title_new') }}{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>{{ category ? t('categories.form.title_edit') : t('categories.form.title_new') }}</h1>
    <a href="/admin/categories" class="btn btn-secondary">{{ t('categories.form.back') }}</a>
</div>
<form method="POST" action="{{ category ? '/admin/categories/' ~ category.id ~ '/edit' : '/admin/categories/new' }}" class="admin-form">
    <div class="form-group">
        <label>{{ t('categories.form.slug') }}</label>
        <input type="text" name="slug" value="{{ category.slug ?? '' }}" required>
    </div>
    <div class="form-group">
        <label>{{ t('categories.form.order') }}</label>
        <input type="number" name="sort_order" value="{{ category.sort_order ?? 0 }}">
    </div>
    <h3>{{ t('categories.form.translations') }}</h3>
    {% set lang_labels = {cs: 'CZ', sk: 'SK', en: 'EN', uk: 'UA', ru: 'RU'} %}
    <div class="lang-tabs">
        {% for lang in langs %}
        <button type="button" class="lang-tab {% if lang == admin_lang %}active preferred{% endif %}" data-lang="{{ lang }}">{% if lang == admin_lang %}★ {% endif %}{{ lang_labels[lang] ?? lang|upper }}</button>
        {% endfor %}
    </div>
    {% for lang in langs %}
    <div class="lang-panel {% if lang == admin_lang %}active{% endif %}" id="panel-{{ lang }}">
        <div class="form-group">
            <label>{{ t('categories.form.name_label') }} ({{ lang_labels[lang] ?? lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][name]" value="{{ translations[lang].name ?? '' }}">
        </div>
        <div class="form-group">
            <label>{{ t('categories.form.desc_label') }} ({{ lang_labels[lang] ?? lang|upper }})</label>
            <textarea name="t[{{ lang }}][description]">{{ translations[lang].description ?? '' }}</textarea>
        </div>
        {% if lang != admin_lang %}
        <div class="form-group">
            <button type="button" class="btn btn-secondary translate-btn" data-lang="{{ lang }}">{{ t('categories.form.translate_btn') }}</button>
            <span class="translate-msg" style="display:none;margin-left:0.5rem;font-size:0.85rem;"></span>
        </div>
        {% endif %}
    </div>
    {% endfor %}
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">{{ t('categories.form.save') }}</button>
        <a href="/admin/categories" class="btn btn-secondary">{{ t('categories.form.cancel') }}</a>
    </div>
</form>
{% block scripts %}
<script>
const PREFERRED_LANG = "{{ admin_lang }}";

// Tab switching
document.querySelectorAll('.lang-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.lang-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.lang-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel-' + tab.dataset.lang).classList.add('active');
    });
});

// Slug auto-generation from preferred-language name
(function () {
    const slugInput = document.querySelector('input[name="slug"]');
    const prefName  = document.querySelector('input[name="t[' + PREFERRED_LANG + '][name]"]');
    if (!slugInput || !prefName) return;

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
    prefName.addEventListener('input', () => { if (!locked) slugInput.value = toSlug(prefName.value); });
})();

// Translate buttons — JS runtime strings remain hardcoded Czech per spec
document.querySelectorAll('.translate-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const targetLang  = btn.dataset.lang;
        const panel       = document.getElementById('panel-' + targetLang);
        const msgSpan     = panel.querySelector('.translate-msg');
        const prefNameEl  = document.querySelector('input[name="t[' + PREFERRED_LANG + '][name]"]');
        const prefDescEl  = document.querySelector('textarea[name="t[' + PREFERRED_LANG + '][description]"]');

        if (!prefNameEl.value.trim() && !prefDescEl.value.trim()) {
            msgSpan.textContent = 'Nejprve vyplňte texty ve výchozím jazyce.';
            msgSpan.style.color = '#c00';
            msgSpan.style.display = 'inline';
            return;
        }

        btn.disabled = true;
        const originalLabel = btn.textContent;
        btn.textContent = 'Překládám…';
        msgSpan.style.display = 'none';
        msgSpan.textContent   = '';

        try {
            const res = await fetch('/admin/translate', {
                method:  'POST',
                headers: {'Content-Type': 'application/json'},
                body:    JSON.stringify({
                    texts:  [prefNameEl.value, prefDescEl.value],
                    target: targetLang.toUpperCase(),
                }),
            });

            const data = await res.json();

            if (!res.ok || data.error) {
                msgSpan.textContent   = 'Překlad se nezdařil: ' + (data.error ?? res.status);
                msgSpan.style.color   = '#c00';
                msgSpan.style.display = 'inline';
                return;
            }

            panel.querySelector('input[name="t[' + targetLang + '][name]"]').value           = data.texts[0] ?? '';
            panel.querySelector('textarea[name="t[' + targetLang + '][description]"]').value = data.texts[1] ?? '';
            msgSpan.style.display = 'none';
            msgSpan.textContent   = '';
        } catch (e) {
            msgSpan.textContent   = 'Překlad se nezdařil: ' + e.message;
            msgSpan.style.color   = '#c00';
            msgSpan.style.display = 'inline';
        } finally {
            btn.disabled    = false;
            btn.textContent = originalLabel;
        }
    });
});
</script>
{% endblock %}
{% endblock %}
```

- [ ] **Step 4: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: PASS (this task touches no PHP logic covered by tests, this just confirms nothing else broke).

- [ ] **Step 5: Commit**

```bash
git add templates/admin/categories/form.twig www/assets/css/admin.css lang/admin/cs.json lang/admin/en.json lang/admin/sk.json lang/admin/ru.json lang/admin/uk.json
git commit -m "feat: default/highlight preferred-language tab on category form, generalize translate source"
```

---

## Task 6: Product form — preferred-tab default/highlight, add translate button (currently missing)

**Files:**
- Modify: `templates/admin/products/form.twig`
- Modify: `lang/admin/cs.json`, `lang/admin/en.json`, `lang/admin/sk.json`, `lang/admin/ru.json`, `lang/admin/uk.json`

**Interfaces:**
- Consumes: `admin_lang` (Twig variable, already injected). `POST /admin/translate` from Task 3. `.lang-tab.preferred` CSS from Task 5 (already added to the shared `admin.css`, reused here — no further CSS changes needed).

No automated template/JS tests exist in this repo. Verified manually in Task 8.

- [ ] **Step 1: Add `products.form.translate_btn` to all 5 admin lang files**

Insert a new line immediately before the `products.form.translations` line in each file (products.form entries are alphabetically ordered, and `translate_btn` sorts before `translations`):

`lang/admin/cs.json` (before line 162, `"products.form.translations": "Překlady",`):
```json
  "products.form.translate_btn": "Přeložit z výchozího jazyka",
```

`lang/admin/en.json` (before the matching `products.form.translations` line):
```json
  "products.form.translate_btn": "Translate from default language",
```

`lang/admin/sk.json` (before the matching `products.form.translations` line):
```json
  "products.form.translate_btn": "Preložiť z predvoleného jazyka",
```

`lang/admin/ru.json` (before the matching `products.form.translations` line):
```json
  "products.form.translate_btn": "Перевести с языка по умолчанию",
```

`lang/admin/uk.json` (before the matching `products.form.translations` line):
```json
  "products.form.translate_btn": "Перекласти з мови за замовчуванням",
```

- [ ] **Step 2: Verify all 5 admin lang files still have identical key sets**

Run:
```bash
for f in lang/admin/*.json; do php -r "echo count(json_decode(file_get_contents('$f'), true)) . ' $f' . PHP_EOL;"; done
```
Expected: all 5 files report the same key count (one more than before this task, consistently across all files).

- [ ] **Step 3: Update `templates/admin/products/form.twig`**

Replace the full file contents with:

```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ product ? t('products.form.title_edit') : t('products.form.title_new') }}{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>{{ product ? t('products.form.title_edit') : t('products.form.title_new') }}</h1>
    <a href="/admin/products" class="btn btn-secondary">{{ t('products.form.back') }}</a>
</div>
<form method="POST" action="{{ product ? '/admin/products/' ~ product.id ~ '/edit' : '/admin/products/new' }}" enctype="multipart/form-data" class="admin-form">
    <div class="form-group">
        <label>{{ t('products.form.sku') }}</label>
        <input type="text" name="sku" value="{{ product.sku ?? '' }}" required>
    </div>
    <div class="form-group">
        <label>{{ t('products.form.price') }}</label>
        <input type="number" name="price" step="0.01" min="0" value="{{ product.price ?? '0.00' }}" required>
    </div>
    <div class="form-group">
        <label>{{ t('products.form.category') }}</label>
        <select name="category_id">
            {% for cat in categories %}
            <option value="{{ cat.id }}" {% if product.category_id == cat.id %}selected{% endif %}>{{ cat.name }}</option>
            {% endfor %}
        </select>
    </div>
    <div class="form-group">
        <label>
            <input type="checkbox" name="is_active" value="1" {% if product is null or product.is_active %}checked{% endif %}>
            {{ t('products.form.active') }}
        </label>
    </div>
    <div class="form-group">
        <label>{{ t('products.form.add_image') }}</label>
        <input type="file" name="image" accept="image/*">
    </div>
    {% if product and product.images %}
    <div class="form-group">
        <label>{{ t('products.form.existing_images') }}</label>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.5rem;">
        {% for img in product.images %}
        <div style="text-align:center;">
            <img src="/assets/uploads/products/thumb_{{ img.filename }}" class="img-thumb"><br>
            <form method="POST" action="/admin/products/{{ product.id }}/image/{{ img.id }}/delete">
                <button class="btn-link" style="font-size:0.8rem">{{ t('products.form.delete_image') }}</button>
            </form>
        </div>
        {% endfor %}
        </div>
    </div>
    {% endif %}
    <h3>{{ t('products.form.translations') }}</h3>
    <div class="lang-tabs">
        {% for lang in langs %}
        <button type="button" class="lang-tab {% if lang == admin_lang %}active preferred{% endif %}" data-lang="{{ lang }}">{% if lang == admin_lang %}★ {% endif %}{{ lang|upper }}</button>
        {% endfor %}
    </div>
    {% for lang in langs %}
    <div class="lang-panel {% if lang == admin_lang %}active{% endif %}" id="panel-{{ lang }}">
        <div class="form-group">
            <label>{{ t('products.form.name_label') }} ({{ lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][name]" value="{{ translations[lang].name ?? '' }}">
        </div>
        <div class="form-group">
            <label>{{ t('products.form.desc_label') }} ({{ lang|upper }})</label>
            <textarea name="t[{{ lang }}][description]">{{ translations[lang].description ?? '' }}</textarea>
        </div>
        <div class="form-group">
            <label>{{ t('products.form.meta_title_label') }} ({{ lang|upper }})</label>
            <input type="text" name="t[{{ lang }}][meta_title]" value="{{ translations[lang].meta_title ?? '' }}" maxlength="255">
        </div>
        <div class="form-group">
            <label>{{ t('products.form.meta_desc_label') }} ({{ lang|upper }})</label>
            <textarea name="t[{{ lang }}][meta_desc]" maxlength="500">{{ translations[lang].meta_desc ?? '' }}</textarea>
        </div>
        {% if lang != admin_lang %}
        <div class="form-group">
            <button type="button" class="btn btn-secondary translate-btn" data-lang="{{ lang }}">{{ t('products.form.translate_btn') }}</button>
            <span class="translate-msg" style="display:none;margin-left:0.5rem;font-size:0.85rem;"></span>
        </div>
        {% endif %}
    </div>
    {% endfor %}
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">{{ t('products.form.save') }}</button>
        <a href="/admin/products" class="btn btn-secondary">{{ t('products.form.cancel') }}</a>
    </div>
</form>
{% block scripts %}
<script>
const PREFERRED_LANG = "{{ admin_lang }}";

document.querySelectorAll('.lang-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.lang-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.lang-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel-' + tab.dataset.lang).classList.add('active');
    });
});

// Translate buttons — JS runtime strings remain hardcoded Czech per spec
document.querySelectorAll('.translate-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const targetLang   = btn.dataset.lang;
        const panel        = document.getElementById('panel-' + targetLang);
        const msgSpan      = panel.querySelector('.translate-msg');
        const prefNameEl   = document.querySelector('input[name="t[' + PREFERRED_LANG + '][name]"]');
        const prefDescEl   = document.querySelector('textarea[name="t[' + PREFERRED_LANG + '][description]"]');
        const prefMTitleEl = document.querySelector('input[name="t[' + PREFERRED_LANG + '][meta_title]"]');
        const prefMDescEl  = document.querySelector('textarea[name="t[' + PREFERRED_LANG + '][meta_desc]"]');

        if (!prefNameEl.value.trim() && !prefDescEl.value.trim() && !prefMTitleEl.value.trim() && !prefMDescEl.value.trim()) {
            msgSpan.textContent = 'Nejprve vyplňte texty ve výchozím jazyce.';
            msgSpan.style.color = '#c00';
            msgSpan.style.display = 'inline';
            return;
        }

        btn.disabled = true;
        const originalLabel = btn.textContent;
        btn.textContent = 'Překládám…';
        msgSpan.style.display = 'none';
        msgSpan.textContent   = '';

        try {
            const res = await fetch('/admin/translate', {
                method:  'POST',
                headers: {'Content-Type': 'application/json'},
                body:    JSON.stringify({
                    texts:  [prefNameEl.value, prefDescEl.value, prefMTitleEl.value, prefMDescEl.value],
                    target: targetLang.toUpperCase(),
                }),
            });

            const data = await res.json();

            if (!res.ok || data.error) {
                msgSpan.textContent   = 'Překlad se nezdařil: ' + (data.error ?? res.status);
                msgSpan.style.color   = '#c00';
                msgSpan.style.display = 'inline';
                return;
            }

            panel.querySelector('input[name="t[' + targetLang + '][name]"]').value        = data.texts[0] ?? '';
            panel.querySelector('textarea[name="t[' + targetLang + '][description]"]').value = data.texts[1] ?? '';
            panel.querySelector('input[name="t[' + targetLang + '][meta_title]"]').value   = data.texts[2] ?? '';
            panel.querySelector('textarea[name="t[' + targetLang + '][meta_desc]"]').value   = data.texts[3] ?? '';
            msgSpan.style.display = 'none';
            msgSpan.textContent   = '';
        } catch (e) {
            msgSpan.textContent   = 'Překlad se nezdařil: ' + e.message;
            msgSpan.style.color   = '#c00';
            msgSpan.style.display = 'inline';
        } finally {
            btn.disabled    = false;
            btn.textContent = originalLabel;
        }
    });
});
</script>
{% endblock %}
{% endblock %}
```

- [ ] **Step 4: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add templates/admin/products/form.twig lang/admin/cs.json lang/admin/en.json lang/admin/sk.json lang/admin/ru.json lang/admin/uk.json
git commit -m "feat: add translate button and preferred-tab default/highlight to product form"
```

---

## Task 7: Manual verification in the browser

**Files:** none (verification only, per this project's guidance that UI changes must be checked in a running browser before being called done).

- [ ] **Step 1: Start the local stack**

```bash
docker compose up -d
php -S localhost:8080 -t www
```

- [ ] **Step 2: Log in to the admin panel**

Visit `http://localhost:8080/admin/login` (or `http://localhost:8080/admin/setup` first if no admin user exists yet in the local DB) and log in.

- [ ] **Step 3: Switch preferred language and verify tab defaults/highlighting**

Use the admin language switcher to set the preferred language to something other than Czech (e.g. English). Open `/admin/categories/new` and `/admin/products/new`:
- Confirm the English tab is active/selected by default (not the first tab).
- Confirm the English tab shows the `★` marker.
- Click another tab (e.g. SK) and confirm the `★` marker stays on the English tab while SK becomes the active (pink) tab — i.e. the two visual states (active vs. preferred) are both visible at once and distinguishable.

- [ ] **Step 4: Verify the manual translate button on both forms**

On `/admin/products/new`, fill in the English (preferred) name/description/meta fields, switch to the SK tab, click the translate button, and confirm all 4 SK fields populate. Repeat on `/admin/categories/new` for name/description.

- [ ] **Step 5: Verify auto-fill-on-save**

Create a new category with only the preferred-language (English) name+description filled in, leaving all other language tabs blank, and submit. Reopen the category for editing and confirm the other language tabs were auto-filled. Repeat for a new product, confirming all 4 fields auto-fill across the non-preferred languages.

- [ ] **Step 6: Verify auto-fill never overwrites existing text**

Edit the product/category created in Step 5, manually change one non-preferred-language field to custom text, leave everything else as-is, and re-save without touching the preferred-language fields. Confirm the manually-edited field keeps the custom text (not overwritten by a fresh translation).

This task has no commit — it's a verification pass over the work committed in Tasks 1-6.

---

## Self-Review Notes

- **Spec coverage:** source-language generalization (Task 1), auto-fill-on-save (Tasks 2, 4), manual button now sourced from preferred language (Tasks 1, 3, 5, 6), preferred-tab default selection (Tasks 5, 6), persistent highlight marker (Tasks 5, 6), product form gets the translate button it previously lacked (Task 6), scope limited to products/categories (no other controllers touched) — all covered.
- **Placeholder scan:** no TBD/TODO; every step has complete code.
- **Type consistency:** `Translator::translate(texts, sourceLang, targetLang, transport)` and `Translator::autoFill(translations, sourceLang, allLangs, fields, transport)` signatures are consistent across Tasks 1, 2, 3, 4. `TRANSLATABLE_FIELDS` constant names/values match what's passed into `autoFill()` in Task 4 and what the templates actually post in Tasks 5-6 (`name`, `description` for categories; `name`, `description`, `meta_title`, `meta_desc` for products).
