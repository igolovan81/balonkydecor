# Auto-Translation (MyMemory)

BalonkyDecor uses the [MyMemory](https://mymemory.translated.net/) free translation API to auto-populate translation fields in the admin panel. No API key or registration required. Available on the **categories, products, gallery, services, and hero-slides** forms; the **pages** form does not have it. There is no "blog" entity in this codebase (removed entirely — see `database/migrations/V009__drop_blog.sql`).

---

## Setup

No configuration needed. MyMemory is a public free API with a limit of ~1,000 words/day. No API key or admin settings change is required — the translate buttons work out of the box.

---

## Using Auto-Translation

Two mechanisms work together:

### 1. The translate button (client-side, on demand)

Using the category form as an example (`/admin/categories/new` or an existing category's edit page — products, gallery, services, and hero-slides forms work identically):

1. Open the entity for editing or create a new one.
2. Fill in the fields on your own admin UI language tab (marked with a ★ — this is your `admin_lang`, not necessarily Czech).
3. Switch to any other language tab.
4. Click **Translate from default language** (`categories.form.translate_btn` etc.) — the tab's translatable fields are populated via MyMemory from your preferred-language tab's values.
5. Review and adjust the translation, then save normally.

The translate button appears on every tab *except* the admin's own preferred-language tab, and it always translates from that preferred-language tab's fields — not hardcoded to Czech. Loading/error status is shown in an inline `.translate-msg` span next to the button; these runtime strings are hardcoded Czech in the JS on all five forms regardless of the admin's UI language (e.g. "Nejprve vyplňte texty ve výchozím jazyce." when the source fields are empty, "Překládám…" while in flight, and a failure message naming the field(s) or error on failure).

### 2. Server-side auto-fill on create (automatic safety net)

`createSubmit` on each of the five controllers (`CategoryController`, `ProductController`, `GalleryController`, `ServiceController`, `HeroSlideController`) calls `Translator::autoFill()` before saving, so any translatable field left blank for a non-preferred language is auto-translated from the admin's preferred-language value at save time — even if the admin never clicked a translate button. `ProductController` also uses `autoFill()` per-row for product subtypes and specs. This safety net only runs on **create**; `editSubmit` on each controller saves `$body['t']` as submitted, with no server-side backfill — on edit, filling other-language fields is left entirely to the client-side button.

`pages` has neither the button nor the server-side auto-fill.

---

## Language Codes

| Tab label | Internal code | MyMemory langpair code |
|-----------|---------------|-------------------------|
| CZ        | `cs`          | `CS`                    |
| SK        | `sk`          | `SK`                    |
| EN        | `en`          | `EN`                    |
| UA        | `uk`          | `UK`                    |
| RU        | `ru`          | `RU`                    |

These are `Translator::VALID_LANGS` / `LANG_MAP` in `src/Services/Translator.php`, and the same allow-list is duplicated in the `/admin/translate` route closure in `src/routes.php`.

---

## Error Handling

| Situation | Behaviour |
|-----------|-----------|
| Preferred-language fields all empty | Inline message "Nejprve vyplňte texty ve výchozím jazyce." — no API call is made |
| MyMemory quota exceeded (~1,000 words/day) or other translation failure | Inline failure message (wording varies slightly by form — e.g. "Překlad se nezdařil pro: \<field names\>" on categories/products/hero-slides, "Překlad se nezdařil: \<error detail\>" on gallery/services) |
| Network error | Same inline failure message path as above |
| Invalid target language, missing `texts`, or `target` equal to the admin's own language | 400 JSON error from `/admin/translate` (not reachable via normal UI, which always sends a valid different target) |
| `Translator::autoFill()` fails for a field during server-side create | That field is silently left blank (or, for product subtypes/specs, falls back to the admin's own text) — sibling fields/languages are unaffected and the save still succeeds |

Existing field values are never cleared or overwritten on error; on the button path a field with an existing non-empty value is never overwritten by auto-fill.

---

## Architecture

### `src/Services/Translator.php`

Static service class.

- `Translator::translate(array $texts, string $sourceLang, string $targetLang, ?callable $transport = null): array` — makes one GET request to the MyMemory API **per text** and returns translated strings in the same order as input. Both `$sourceLang` and `$targetLang` are required (uppercase, validated against `VALID_LANGS`); passing the same language for both throws.
- `Translator::autoFill(array $translations, string $sourceLang, array $allLangs, array $fields, ?callable $transport = null): array` — given a `[lang => [field => value]]` map, fills any empty `$field` for any non-source `$lang` by translating that field's source-language value, one request per field so a single over-length or failing field can never abort translation of its sibling fields. Failures are logged via `AppLogger` and leave the field blank rather than throwing.

The transport (HTTP call) is injectable via an optional `$transport` callable — used by unit tests to avoid real HTTP calls.

### `POST /admin/translate`

Inline closure in `src/routes.php`, inside the `$app->group('/admin', ...)` block protected by `AuthMiddleware`. Requires an active admin session. The source language is **not** sent by the client — it is read server-side from the `admin_lang` request attribute (the requesting admin's preferred UI language, set by `AdminLangMiddleware`).

Request body (JSON):
```json
{ "texts": ["Czech name", "Czech description"], "target": "SK" }
```

Response 200:
```json
{ "texts": ["Slovak name", "Slovak description"] }
```

Response 400 — invalid parameters: `texts` missing/empty, `target` not one of `CS`/`SK`/`EN`/`UK`/`RU`, or `target` equal to the admin's own language. Response 500 — translation failure (MyMemory error, malformed response, or network/transport error), with the error logged via `AppLogger`.

### Form templates

Each of `templates/admin/categories/form.twig`, `templates/admin/products/form.twig`, `templates/admin/gallery/form.twig`, `templates/admin/services/form.twig`, and `templates/admin/hero-slides/form.twig` renders a `.translate-btn` button plus a `.translate-msg` span on every non-preferred-language panel. Vanilla JS reads the preferred-language tab's fields, POSTs to `/admin/translate`, and writes the result back into the target tab's fields. `templates/admin/pages/form.twig` has none of this.

---

## Tests

`tests/Unit/Services/TranslatorTest.php` — 12 tests covering: `translate()` happy path, malformed response, invalid source/target language, source-equals-target rejection, correct langpair construction (including non-Czech sources), and `autoFill()` behaviour — filling empty target fields, skipping languages with nothing to fill, leaving a field blank when translation fails, not overwriting an existing partial value, and isolating one field's failure from its siblings. All use an injectable transport; no real HTTP calls required.

```bash
php vendor/bin/phpunit tests/Unit/Services/TranslatorTest.php --testdox
```
