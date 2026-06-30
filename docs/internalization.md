# Internationalisation (i18n)

BalonkyDecor supports five languages: **cs** (Czech, default), **sk** (Slovak), **en** (English), **uk** (Ukrainian), **ru** (Russian).

The same `I18n` service and `t()` Twig function are used on both the public site and the admin portal, but they load from separate translation file directories and are selected by different mechanisms.

---

## Architecture

### Core service — `src/Services/I18n.php`

```php
$i18n = new I18n('en', '/path/to/lang/dir');
$i18n->t('nav.home');                     // "Home"
$i18n->t('checkout.order_number', ['n' => 'ORD-001']); // "Order ORD-001"
```

- Constructor loads `{langDir}/{lang}.json` into memory.
- `t(string $key, array $params = [])` — returns the translated string, or the key itself if missing. Interpolates `{param}` placeholders.
- Falls back to the key name on missing keys (never throws).

### Twig integration — `src/Twig/I18nExtension.php`

Registers a global `t()` function in Twig backed by an `I18n` instance. Both `BaseController` (public) and `AdminBaseController` (admin) register the extension on each render call, passing in the request-scoped `I18n` object.

---

## Public site

### Language selection

URLs use a `/{lang}/` prefix: `/cs/shop`, `/en/blog`, `/uk/checkout`.

`LangMiddleware` (applied to all routes) extracts the first path segment and validates it against the supported list. Unknown or missing segments default to `cs`. The resolved `lang` string and an `I18n` instance are attached to the PSR-7 request as attributes.

```
GET /en/shop
  → LangMiddleware: lang=en, i18n=I18n('en', 'lang/')
  → Controller: $request->getAttribute('lang')    // 'en'
  → Controller: $request->getAttribute('i18n')    // I18n instance
  → BaseController::render() registers I18nExtension
  → Template: {{ t('shop.add_to_cart') }}         // 'Add to cart'
```

The root `/` redirects to `/{default_lang}/`.

### Translation files

Location: `lang/{lang}.json` — one file per language, 68 keys each.

All five files must have **identical key sets**. Keys are sorted alphabetically.

Key groups:

| Group | Purpose |
|---|---|
| `blog.*` | Blog list and post pages |
| `cart.*` | Shopping cart |
| `checkout.*` | Checkout and order confirmation |
| `contact.*` | Contact form |
| `gallery.*` | Gallery albums |
| `home.*` | Homepage hero and CTA |
| `nav.*` | Navigation links |
| `order.*` | Order status page and item table |
| `services.*` | Services page |
| `shop.*` | Product list |
| `site.*` | Site-wide (site name) |

### Adding a public translation key

1. Add the key to **all five** `lang/*.json` files with translated values.
2. Use `{{ t('group.key') }}` in the Twig template.
3. For dynamic values: `{{ t('checkout.order_number', {n: order.order_number}) }}` — placeholder syntax is `{param}` in the JSON value.

---

## Admin portal

### Language selection

Admin language is **per-user** and **session-scoped** — no URL prefix.

`AdminLangMiddleware` (applied to the `/admin/*` group) reads `$_SESSION['admin_lang']` (default: `cs`) and attaches an `I18n` instance as the `admin_i18n` request attribute. The active language is also passed to every admin template as `admin_lang`.

**Switching language:** The sidebar shows a `CZ · SK · EN · UA · RU` switcher. Each link calls `GET /admin/set-lang?l={lang}`, which validates the value, writes it to the DB (`users.lang` column) and to `$_SESSION['admin_lang']`, then redirects back. On next login the session lang is restored from the DB.

```
Admin clicks "EN" in sidebar
  → GET /admin/set-lang?l=en
  → AdminLangController: writes users.lang=en, $_SESSION['admin_lang']=en
  → Redirect back
  → Next request: AdminLangMiddleware loads I18n('en', 'lang/admin/')
  → Template: {{ t('nav.products') }}   // 'Products'
```

### Translation files

Location: `lang/admin/{lang}.json` — one file per language, 192 keys each, sorted alphabetically.

Key groups:

| Group | Keys | Covers |
|---|---|---|
| `blog.*` | 24 | Blog list and form |
| `categories.*` | 22 | Categories list and form |
| `dashboard.*` | 12 | Dashboard stats and recent orders table |
| `gallery.*` | 23 | Gallery albums list and form |
| `nav.*` | 10 | Sidebar navigation and logout |
| `orders.*` | 27 | Orders list and detail page |
| `pages.*` | 10 | Static pages list and editor |
| `products.*` | 27 | Products list and form |
| `settings.*` | 18 | Settings form |
| `users.*` | 19 | Users list and create form |

### What is NOT translated in admin

- PHP controller flash messages (e.g. "Produkt byl uložen.") — hardcoded Czech.
- JS `confirm()` dialogs — hardcoded Czech.
- JS runtime strings (loading states, error messages in auto-translate feature) — hardcoded Czech.
- Order status enum values rendered in badges/selects — raw DB values, not UI labels.

### Adding an admin translation key

1. Pick the relevant group prefix (e.g. `products.*`).
2. Add the key to **all five** `lang/admin/*.json` files, maintaining alphabetical order.
3. Use `{{ t('products.my_key') }}` in the admin Twig template.

---

## Adding a new language

1. Copy `lang/cs.json` → `lang/{code}.json` and translate all values.
2. Copy `lang/admin/cs.json` → `lang/admin/{code}.json` and translate all values.
3. Add the language code to `$supported` in `config/settings.php`.
4. Add the code to `AdminLangMiddleware::SUPPORTED`.
5. Add the code to the lang switcher map in `templates/layout/admin-base.twig` (`{cs: 'CZ', ...}`).
6. Add a `/{code}/*` route group in `src/routes.php` if needed (currently handled by the `/{lang}/` wildcard).

---

## Checking key consistency

Verify all files in a directory have the same key count:

```bash
# Public
for f in lang/cs.json lang/en.json lang/ru.json lang/sk.json lang/uk.json; do
  echo -n "$f: "; php -r "echo count(json_decode(file_get_contents('$f'), true)) . ' keys\n';"
done

# Admin
for f in lang/admin/*.json; do
  echo -n "$f: "; php -r "echo count(json_decode(file_get_contents('$f'), true)) . ' keys\n';"
done
```

Re-sort keys after manual edits:

```bash
php -r "
foreach (glob('lang/admin/*.json') as \$f) {
    \$d = json_decode(file_get_contents(\$f), true);
    ksort(\$d);
    \$e = [];
    foreach (\$d as \$k => \$v)
        \$e[] = '  ' . json_encode(\$k, JSON_UNESCAPED_UNICODE) . ': ' . json_encode(\$v, JSON_UNESCAPED_UNICODE);
    file_put_contents(\$f, \"{\n\" . implode(\",\n\", \$e) . \"\n}\n\");
}
"
```
