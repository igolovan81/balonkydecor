# Auto-Translation (MyMemory)

BalonkyDecor uses the [MyMemory](https://mymemory.translated.net/) free translation API to auto-populate translation fields in the admin panel. No API key or registration required. Currently available on the category form; other entity types (products, blog, pages, gallery) are out of scope for now.

---

## Setup

No configuration needed. MyMemory is a public free API with a limit of ~1,000 words/day. No API key or admin settings change is required — the translate buttons work out of the box.

---

## Using Auto-Translation in the Category Form

1. Open a category for editing or create a new one (`/admin/categories/new`).
2. Fill in the **CZ** tab — enter the Czech name and description.
3. Switch to any other language tab (SK, EN, UA, RU).
4. Click **Přeložit z češtiny** — the name and description fields are populated from DeepL.
5. Review and adjust the translation, then save the category normally.

The translate button is available on every non-CZ tab. It always translates from the Czech (CZ) fields, regardless of which tab is currently active.

---

## Language Codes

| Tab label | Internal code | DeepL target |
|-----------|---------------|--------------|
| CZ        | `cs`          | `CS`         |
| SK        | `sk`          | `SK`         |
| EN        | `en`          | `EN`         |
| UA        | `uk`          | `UK`         |
| RU        | `ru`          | `RU`         |

---

## Error Handling

| Situation | Behaviour |
|-----------|-----------|
| CZ name and description both empty | Inline message "Nejprve vyplňte český název." — no API call is made |
| MyMemory quota exceeded (~1,000 words/day) | Inline message "Překlad se nezdařil: \<error detail\>" |
| Network error | Inline message "Překlad se nezdařil: \<error detail\>" |
| Invalid target language | 400 JSON error from the endpoint (not reachable via normal UI) |

Existing field values are never cleared on error.

---

## Architecture

### `src/Services/Translator.php`

Static service class. `Translator::translate(array $texts, string $targetLang)` makes one GET request to the MyMemory API per text and returns translated strings in the same order as input. Source language is always Czech (`cs`).

The transport (HTTP call) is injectable via an optional `$transport` callable — used by unit tests to avoid real HTTP calls.

### `POST /admin/translate`

Inline closure in `src/routes.php`, inside the `$app->group('/admin', ...)` block protected by `AuthMiddleware`. Requires an active admin session.

Request body (JSON):
```json
{ "texts": ["Czech name", "Czech description"], "target": "SK" }
```

Response 200:
```json
{ "texts": ["Slovak name", "Slovak description"] }
```

Response 400 — invalid parameters. Response 500 — translation failure.

### `templates/admin/categories/form.twig`

Each non-CZ language panel contains a `.translate-btn` button and a `.translate-msg` span for inline status. Vanilla JS reads the CZ fields, POSTs to `/admin/translate`, and writes the result back into the active tab's fields.

---

## Tests

`tests/Unit/Services/TranslatorTest.php` — 4 tests covering happy path, malformed response, invalid target language, and URL construction. All use an injectable transport; no real HTTP calls required.

```bash
php vendor/bin/phpunit tests/Unit/Services/TranslatorTest.php --testdox
```
