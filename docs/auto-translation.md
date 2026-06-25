# Auto-Translation (DeepL)

BalonkyDecor uses the DeepL API to auto-populate translation fields in the admin panel. Currently available on the category form; other entity types (products, blog, pages, gallery) are out of scope for now.

---

## Setup

1. Obtain a DeepL API key from [deepl.com](https://www.deepl.com/pro-api) (free tier works).
2. Log in to the admin panel and go to **Nastavení** (`/admin/settings`).
3. Paste the key into the **DeepL API klíč** field and save.

The key is stored in the `settings` table under the key `deepl_api_key`. The free-tier endpoint (`api-free.deepl.com`) is used; switching to a paid plan requires changing the base URL in `src/Services/DeepL.php`.

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
| `deepl_api_key` not set in settings | Inline message "Překlad se nezdařil: DeepL API key not configured" |
| DeepL API error (quota, network, etc.) | Inline message "Překlad se nezdařil: \<error detail\>" |
| Invalid target language | 400 JSON error from the endpoint (not reachable via normal UI) |

Existing field values are never cleared on error.

---

## Architecture

### `src/Services/DeepL.php`

Static service class. `DeepL::translate(array $texts, string $targetLang)` reads `deepl_api_key` from the `settings` table and POSTs all texts in a single request to the DeepL v2 API. Returns translated strings in the same order as input.

The transport (HTTP call) is injectable via an optional `$transport` callable — used by unit tests to avoid real HTTP and DB access.

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

Response 400 — invalid parameters. Response 500 — DeepL failure.

### `templates/admin/categories/form.twig`

Each non-CZ language panel contains a `.translate-btn` button and a `.translate-msg` span for inline status. Vanilla JS reads the CZ fields, POSTs to `/admin/translate`, and writes the result back into the active tab's fields.

---

## Tests

`tests/Unit/Services/DeepLTest.php` — 4 tests covering happy path, malformed response, missing API key, and target language validation. All use an injectable transport; no real HTTP or database access required.

```bash
php vendor/bin/phpunit tests/Unit/Services/DeepLTest.php --testdox
```

---

## Database Migration

`database/migrations/V005__deepl_api_key_setting.sql` — adds the `deepl_api_key` row to the `settings` table (empty string default). Must be applied on any environment where the feature is used.
