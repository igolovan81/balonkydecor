# Category Auto-Translate Design

**Date:** 2026-06-25  
**Scope:** Admin category form — per-language DeepL translation buttons  
**Status:** Approved

---

## Overview

When creating or editing a category, the admin can fill in the Czech (CZ) name and description, then click a per-language "Přeložit z češtiny" button on any other language tab to auto-populate that tab's fields via DeepL.

---

## Language Tab Order and Labels

| Display label | Internal code | DeepL target code |
|---------------|---------------|-------------------|
| CZ            | cs            | CS                |
| SK            | sk            | SK                |
| EN            | en            | EN                |
| UA            | uk            | UK                |
| RU            | ru            | RU                |

The tab formerly labelled `CS` is renamed to `CZ`. The tab formerly labelled `UK` is relabelled `UA`. Internal codes and DB values are unchanged.

---

## Components

### 1. `DeepL` service — `src/Services/DeepL.php`

```
DeepL::translate(array $texts, string $targetLang): array
```

- Reads `deepl_api_key` from the `settings` table via `Database::getConnection()`.
- If key is empty, throws `RuntimeException('DeepL API key not configured')`.
- POSTs to `https://api-free.deepl.com/v2/translate` with `Authorization: DeepL-Auth-Key <key>`.
- Sends all texts in one request; returns translated strings in the same order.
- Throws `RuntimeException` on HTTP error or unexpected response shape.
- Uses free-tier endpoint; switching to pro requires only changing the base URL.

### 2. AJAX endpoint — `POST /admin/translate`

Route registered in `routes.php` inside the `$app->group('/admin', ...)` block (protected by `AuthMiddleware`).

Handled inline as a closure (no separate controller class — the logic is trivial):

```
Request body (JSON): { "texts": ["name value", "description value"], "target": "SK" }
Response 200 (JSON): { "texts": ["translated name", "translated description"] }
Response 400 (JSON): { "error": "..." }   — missing/invalid params
Response 500 (JSON): { "error": "..." }   — DeepL failure
```

Validates `target` is one of `[CS, SK, EN, UK, RU]` before calling the service.

### 3. Category form UI

**Tab order:** CZ → SK → EN → UA → RU (controlled by the `langs` array passed from the controller, currently driven by `config/settings.php` `languages` key).

**Tab label mapping** in the template: `uk` → `UA`, all others uppercased. Implemented as a Twig hash lookup:
```twig
{% set lang_labels = {cs: 'CZ', sk: 'SK', en: 'EN', uk: 'UA', ru: 'RU'} %}
{{ lang_labels[lang] ?? lang|upper }}
```

**Translate button** — shown on every tab except CZ:
```
[ Přeložit z češtiny ]
```
Placed below the description textarea within each non-CZ `lang-panel`. Disabled while a request is in flight (replaced with "Překládám…").

**JS behaviour:**
1. On click, read `input[name="t[cs][name]"]` and `textarea[name="t[cs][description]"]`.
2. If both are empty, show inline message "Nejprve vyplňte český název." and abort.
3. POST JSON to `/admin/translate`.
4. On success, populate the current tab's name and description fields.
5. On error, show inline message "Překlad se nezdařil: <error>" without clearing existing values.

### 4. Settings table + admin UI

Add `deepl_api_key` to the `settings` table (empty string default). Expose it in `/admin/settings` as a password-type input labelled "DeepL API klíč".

Add to `database/migrations/` as `V005__deepl_api_key_setting.sql`:
```sql
INSERT IGNORE INTO settings (`key`, `value`) VALUES ('deepl_api_key', '');
```

---

## Error Handling

| Scenario | Behaviour |
|----------|-----------|
| CZ fields empty | Inline message on button click; no API call made |
| `deepl_api_key` not set | 500 JSON error → "Překlad se nezdařil: DeepL API key not configured" |
| DeepL API error | 500 JSON error → message from DeepL or generic fallback |
| Network timeout | PHP `file_get_contents` / `curl` timeout → 500 JSON error |
| Invalid target lang | 400 JSON error |

Existing field values are never cleared on error.

---

## Data Flow

```
User clicks "Přeložit z češtiny" on SK tab
  → JS reads cs[name] + cs[description]
  → POST /admin/translate {texts: ["Balónky", "Popis..."], target: "SK"}
  → AuthMiddleware passes (admin session)
  → Closure validates params
  → DeepL::translate(["Balónky", "Popis..."], "SK")
  → DeepL API returns ["Balóny", "Popis..."]
  → JSON 200 {texts: ["Balóny", "Popis..."]}
  → JS fills SK name + description fields
```

---

## Out of Scope

- Auto-translate on other entity types (products, blog, pages, gallery) — future work
- DeepL usage/quota tracking
- Caching translated strings
- Auto-translate on save (translation is always explicit, user-triggered)
