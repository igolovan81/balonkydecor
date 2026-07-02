# Preferred-Language Auto-Translate Design

**Date:** 2026-07-02
**Scope:** Admin product + category forms — translate from the admin's preferred language instead of hardcoded Czech, auto-fill on save, highlight/default the preferred-language tab
**Status:** Approved

---

## Overview

Today, [[2026-06-25-category-autotranslate-design]] added a manual "Translate" button to the category form that always translates *from* Czech, and the first language tab (`cs`) is always the default/active one. Products have no translate UI at all.

Each admin user has a preferred language already tracked in `$_SESSION['admin_lang']` (persisted to `users.lang`, switchable via the existing language switcher, injected into every admin template as `admin_lang`).

This change:
1. Generalizes translation to run *from the admin's preferred language* rather than always Czech.
2. Adds automatic translation of empty fields on save (create + edit), in addition to keeping the manual per-tab button.
3. Extends the translate button to the product form (currently missing), covering all four translatable fields (name, description, meta title, meta description) instead of just two.
4. Makes the preferred-language tab the default-selected tab on page load, and gives it a persistent visual marker so it's identifiable even after switching tabs.

Applies only to products and categories. Other translatable entities (blog, pages, gallery) are out of scope.

---

## Components

### 1. `Translator` service — `src/Services/Translator.php`

Signature changes from `translate(array $texts, string $targetLang, ?callable $transport = null)` to:

```php
Translator::translate(array $texts, string $sourceLang, string $targetLang, ?callable $transport = null): array
```

- Both `$sourceLang` and `$targetLang` are validated against the existing supported set (`CS, SK, EN, UK, RU`, case-insensitive); invalid values throw `RuntimeException`.
- URL `langpair` becomes `{sourceCode}|{targetCode}` (was hardcoded `cs|{targetCode}`).
- If `$sourceLang` and `$targetLang` normalize to the same code, throws `RuntimeException('Source and target language are the same')` — callers must avoid calling it that way, this is a guard rail.

New method for the auto-fill-on-save flow:

```php
Translator::autoFill(array $translations, string $sourceLang, array $allLangs, array $fields): array
```

- `$translations` is the posted `t[lang][field]` array (e.g. `$body['t']`).
- For each language in `$allLangs` other than `$sourceLang`:
  - Determine which of `$fields` are empty (after `trim()`) in that language **and** non-empty in `$sourceLang`.
  - If none, skip this language (no API call).
  - Otherwise, batch those field values from the source language into a single `translate()` call for that language.
  - On success, fill in the translated values for just those fields (fields that already had a value, or that had no source value to translate from, are left untouched).
  - On any exception (network error, API error, invalid response), swallow it and leave that language's fields as posted (blank) — the rest of the save proceeds normally. No partial-language state: either all requested fields for a language get filled, or none do (since it's one batched call per language).
- Returns the augmented `$translations` array. Does not mutate the input array in place (pure function).

### 2. `POST /admin/translate` route (manual per-tab button) — `src/routes.php`

- Reads `$sourceLang = $request->getAttribute('admin_lang', 'cs')` — the server determines the source from the authenticated admin's session, the client never specifies it. This closes off a client from requesting translation between two arbitrary languages that aren't their own preferred one, keeping the endpoint's purpose narrow.
- Request/response JSON shape is unchanged: `{texts: [...], target: "SK"}` → `{texts: [...]}` or `{error: "..."}`.
- Passes `$sourceLang` as the new second argument to `Translator::translate()`.
- If `$targetLang` (uppercased) equals `$sourceLang` (uppercased), returns 400 `{error: "Invalid parameters."}` (same message/status as other invalid-parameter cases — this shouldn't happen from the UI since the button isn't rendered on the preferred-language tab, but the endpoint still guards it).

### 3. `ProductController` / `CategoryController`

In both `createSubmit` and `editSubmit`, before persisting translations:

```php
$prefLang = $request->getAttribute('admin_lang', 'cs');
$translations = \App\Services\Translator::autoFill(
    $body['t'] ?? [],
    $prefLang,
    self::LANGS,
    ['name', 'description', 'meta_title', 'meta_desc'] // CategoryController uses ['name', 'description']
);
ProductModel::setTranslations($id, $translations); // or CategoryModel::setTranslations
```

`self::LANGS` is the existing constant on each controller (`['cs', 'en', 'ru', 'uk', 'sk']` / `['cs', 'sk', 'en', 'uk', 'ru']`) — order doesn't matter for `autoFill`, it iterates all of them.

This applies uniformly to both create and edit: on edit, only fields still blank in a given language get filled — anything the admin already typed (in a previous edit or via the manual button) is left alone, consistent with the "save anyway, leave blank on failure" and "don't overwrite typed content" behavior.

### 4. Templates — `templates/admin/products/form.twig`, `templates/admin/categories/form.twig`

**Default tab/panel:** condition changes from `loop.first` to `lang == admin_lang`:
```twig
<button type="button" class="lang-tab {% if lang == admin_lang %}active{% endif %} {% if lang == admin_lang %}preferred{% endif %}" data-lang="{{ lang }}">
```
(both forms; category form keeps its existing `lang_labels` display-name lookup, product form keeps plain `lang|upper`)

**Persistent marker:** the `.preferred` class above is independent of `.active`, so it stays on the tab regardless of which tab is currently selected. Rendered as a small star before the label, e.g.:
```twig
{% if lang == admin_lang %}★ {% endif %}{{ lang|upper }}
```

**CSS** (`www/assets/css/admin.css`), additive to the existing `.lang-tab` / `.lang-tab.active` rules:
```css
.lang-tab.preferred { color: #b8006b; }
.lang-tab.preferred.active { color: #e91e8c; }
```
(preferred-but-inactive gets a darker/muted version of the active accent color, so it's visually distinct from both plain inactive tabs and the currently-active tab)

**Translate button:** shown on every tab except the preferred one — condition changes from `lang != 'cs'` to `lang != admin_lang` (category form already has this structure; product form gets it added, following the same markup/placement pattern — one button + one inline message span per non-preferred `lang-panel`).

**JS (`{% block scripts %}` in both forms):**
- A small constant is injected: `const PREFERRED_LANG = "{{ admin_lang }}";`
- Source field values are read from `t[${PREFERRED_LANG}][...]` instead of hardcoded `t[cs][...]`.
- Category form: reads `name` + `description` (2 fields, unchanged field set, just generalized source).
- Product form (new): reads `name`, `description`, `meta_title`, `meta_desc` (4 fields) from the preferred-language panel, sends all 4 in one `/admin/translate` call, fills all 4 in the target panel on success.
- The "fields empty, nothing to translate" guard generalizes from "Nejprve vyplňte český název." to a language-agnostic message, e.g. "Nejprve vyplňte texty ve výchozím jazyce." (JS runtime strings stay hardcoded Czech, matching the existing convention noted in the category form's JS comment — this is UI chrome for the admin tool, not public-facing i18n).

---

## Error Handling

| Scenario | Behaviour |
|----------|-----------|
| Auto-fill-on-save: translation API fails for a language | That language's fields stay as posted (blank); save proceeds; no error shown to admin |
| Auto-fill-on-save: preferred-language field itself is blank | Nothing to translate from; target field stays blank |
| Auto-fill-on-save: target field already has a value | Left untouched, never overwritten |
| Manual button: preferred-language fields all empty | Inline message, no API call (existing category behavior, generalized wording) |
| Manual button: API error | Inline error message, existing field values untouched (existing behavior, unchanged) |
| Manual button: target == source requested directly against the endpoint | 400 `{error: "Invalid parameters."}` |

---

## Data Flow

### Auto-fill on save (new)
```
Admin (preferred lang = EN) submits product form with EN fields filled, SK/UK/RU/CS blank
  → ProductController::createSubmit / editSubmit
  → $prefLang = 'en' (from admin_lang request attribute)
  → Translator::autoFill($body['t'], 'en', LANGS, [name, description, meta_title, meta_desc])
      → for each of SK, UK, RU, CS: batch non-empty-source/empty-target fields → Translator::translate()
      → SK/UK/RU/CS fields filled where translation succeeded; left blank where it failed
  → ProductModel::setTranslations($id, $translations)
```

### Manual per-tab button (generalized from existing category flow)
```
Admin (preferred lang = EN) clicks "Translate" on the SK tab
  → JS reads t[en][name], t[en][description], t[en][meta_title], t[en][meta_desc]
  → POST /admin/translate {texts: [...4 values...], target: "SK"}
  → AuthMiddleware passes (admin session) → AdminLangMiddleware sets admin_lang='en' on request
  → Closure reads sourceLang='en' from request, validates target != source
  → Translator::translate([...4 texts...], 'en', 'SK')
  → JSON 200 {texts: [...4 translated values...]}
  → JS fills SK tab's 4 fields
```

---

## Testing

- `TranslatorTest`: update existing tests for the new `(texts, sourceLang, targetLang, transport)` signature; add cases for non-`cs` source langpair construction, and for the same-source-and-target guard.
- New `Translator::autoFill()` unit tests: fills only empty target fields, skips languages with nothing to fill (no transport call), leaves a language blank when its translate call throws, batches multiple fields into one call per language.
- `ProductModelTest` / `CategoryModelTest`: no changes expected (translation persistence itself is unchanged — only what gets passed into `setTranslations` changes, at the controller level).
- No controller-level HTTP tests exist currently for admin product/category submit flows (per the existing test suite structure) — auto-fill is exercised via the `Translator::autoFill` unit tests plus manual verification through the running app.

---

## Out of Scope

- Auto-translate on other entity types (blog, pages, gallery) — future work, same pattern would apply.
- Translation caching or MyMemory quota tracking.
- Changing which fields are translatable (no new fields added to categories or products).
- Any change to `AdminLangMiddleware`, the language switcher, or how `admin_lang` is determined/persisted — this design only consumes the existing attribute.
