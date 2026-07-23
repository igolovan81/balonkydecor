# SEO

BalonkyDecor is crawlable and indexable by search engines across all five languages: a dynamic sitemap and robots file, canonical/hreflang tags on every public page, editable per-page meta titles/descriptions, and JSON-LD structured data on product/gallery pages.

Design rationale and the full implementation plan live in `docs/superpowers/specs/2026-07-01-seo-design.md` and `docs/superpowers/plans/2026-07-01-seo-implementation.md`. This doc is the day-to-day reference.

---

## Crawlability: `robots.txt` and `sitemap.xml`

Both are dynamic Slim routes, not static files — they always reflect the live database, so a product/album published through the admin panel appears without a code deploy.

- **`GET /robots.txt`** — disallows `/admin/`, `/*/cart`, `/*/checkout`, `/*/order/`, `/*/payment/`; points at the sitemap.
- **`GET /sitemap.xml`** — one `<url>` entry per (page × language) for: home, `/shop`, every active product, `/services`, `/services/archive` (the gallery index), every gallery album, `/contact`, `/shipping-payment`. Each entry carries `<xhtml:link rel="alternate" hreflang="...">` for all 5 languages plus `x-default` (→ `cs`).

No caching layer — query cost is negligible at this catalog's size.

```
GET /sitemap.xml
  → SeoController::sitemap()
  → Sitemap::entries()          — builds the path list from ProductModel/GalleryModel
  → Seo::canonicalUrl() / alternateUrls()   — per path, per language
```

---

## Canonical URLs and hreflang

Every public page (via `BaseController::render()`) automatically gets:

```twig
<link rel="canonical" href="{{ canonical_url }}">
{% for alt in alternate_urls %}
<link rel="alternate" hreflang="{{ alt.lang }}" href="{{ alt.url }}">
{% endfor %}
```

`canonical_url`, `alternate_urls`, and `base_url` (`https://balonkydecor.cz`) are computed once per request from `current_path` (the URL with the `/{lang}` prefix stripped) — no per-template wiring needed. This is the same URL-shape logic `Sitemap` uses, so the two stay consistent by construction.

**Session-specific pages** — cart, checkout, checkout confirm, order status — additionally render `<meta name="robots" content="noindex,nofollow">` via their own `{% block head %}` override, since these are personalized and shouldn't be indexed even though they're crawlable.

---

## Structured data (JSON-LD)

| Scope | Type | Where |
|---|---|---|
| Every public page | `Organization` (name, url, telephone, email from `settings`) | `templates/layout/base.twig`, built by `Seo::organizationJsonLd()` |
| Product detail | `Product` + `Offer` (price, `priceCurrency: CZK`, availability) | `templates/public/shop/product.twig` |
| Product / gallery album | `BreadcrumbList` (Home → section index → detail) | same 2 templates, built inline per page |

`availability` is `https://schema.org/OutOfStock` when `stock_type = 'limited' AND stock_qty <= 0`, otherwise `https://schema.org/InStock`.

**Security note:** every JSON-LD block is emitted via `|raw` inside a `<script>` tag, so it must never use `JSON_UNESCAPED_SLASHES` — keeping `/` escaped as `\/` is what stops a value containing `</script>` (e.g. from admin-entered contact info or product text) from breaking out of the script context. `Seo::organizationJsonLd()` and every Twig `json_encode` call in this codebase rely on the filter's default (slash-escaping) behavior for this reason — don't add `JSON_UNESCAPED_SLASHES` anywhere in this code path.

Out of scope: `AggregateRating`/`Review` (no reviews feature exists), category-level schema (categories have no dedicated URL — see below).

---

## Editing SEO metadata in the admin panel

Products, gallery albums, and pages (home/services/contact) each have a per-language **SEO title** and **SEO description** field, alongside their existing name/description fields:

| Content type | Admin form | Public template |
|---|---|---|
| Product | `/admin/products/{id}/edit` | `<title>` falls back to product name if empty |
| Gallery album | `/admin/gallery/{id}/edit` | `<title>` falls back to album name if empty |
| Page (home / services / contact) | `/admin/pages/{slug}/edit` | `<title>` falls back to the section's default translated heading if empty |

SEO title is capped at 255 characters, SEO description at 500 — matching the `meta_title varchar(255)` / `meta_desc varchar(500)` columns.

**Categories do not have SEO fields.** `/shop?category=X` is a query-param filter on the single `/shop` route, not its own indexable page — there's nowhere for category-level meta tags to render, so the columns were deliberately left off `category_t`. If categories ever get real landing pages, adding `meta_title`/`meta_desc` there is a one-migration follow-up.

---

## HTTPS

`www/.htaccess` 301-redirects all HTTP traffic to HTTPS, placed before the existing Slim catch-all rule. This can't be exercised locally (`php -S` has no Apache/TLS layer) — verify after each deploy with `/verify` or:

```bash
curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" http://balonkydecor.cz/cs/
# expect: 301 https://balonkydecor.cz/cs/
```

---

## Registering with search engines

Building the sitemap doesn't submit it anywhere — each search engine needs the site registered and verified once, then pointed at `https://balonkydecor.cz/sitemap.xml`. `robots.txt` already advertises the sitemap URL, so most crawlers will find it on their own, but manual submission gets it indexed much faster and unlocks each engine's diagnostics (indexing errors, mobile usability, manual penalties).

Given the language mix (cs primary, sk/en/uk/ru), register with all four below — Seznam and Yandex matter here in a way they wouldn't for an English-only site.

### Verification method

Every engine below supports **HTML meta tag verification**, which is the easiest option for this codebase: the engine gives you a `<meta name="..." content="...">` tag, add it to `templates/layout/base.twig`'s `<head>` (next to the existing `organization_json_ld` script), deploy, click "Verify". Alternatives (DNS TXT record, uploaded HTML file to `www/`) work too if preferred, but the meta-tag route needs no server/DNS access beyond a normal deploy.

### Google Search Console

1. [search.google.com/search-console](https://search.google.com/search-console) → Add property → **URL prefix** → `https://balonkydecor.cz`.
2. Verify via the HTML tag method (see above), or via the existing Google Analytics/Tag Manager property if one is ever added.
3. **Sitemaps** → submit `sitemap.xml`.
4. Check **Page indexing** and **Core Web Vitals** periodically; the mobile-responsive work already merged should keep the latter healthy.

### Bing Webmaster Tools

1. [www.bing.com/webmasters](https://www.bing.com/webmasters) → Add site → `https://balonkydecor.cz`.
2. Bing offers a one-click **import from Google Search Console** (needs GSC set up first) — otherwise verify via the same meta-tag method.
3. Submit `sitemap.xml` under **Sitemaps**.
4. This also feeds Yahoo and (partially) DuckDuckGo, which don't have separate webmaster consoles.

### Yandex Webmaster

Relevant for the `ru` audience.

1. [webmaster.yandex.com](https://webmaster.yandex.com) → Add site → `https://balonkydecor.cz`.
2. Verify via meta tag.
3. Submit `sitemap.xml` under **Indexing → Sitemap files**.
4. Yandex is stricter about crawl-delay and duplicate content than Google — the existing canonical/hreflang setup should already satisfy it, but check **Diagnostics** after the first crawl.

### Seznam.cz

The dominant Czech search engine — high-value for the `cs` (default) audience specifically.

1. [webmaster.seznam.cz](https://webmaster.seznam.cz) → add and verify the site (meta tag or a Seznam-issued verification file).
2. Submit the sitemap URL in the webmaster tools.
3. Seznam's crawler (`SeznamBot`) already respects `robots.txt` and finds the sitemap link there even without manual submission, but registering unlocks their search analytics.

### After registering

- Give each console a few days to a couple of weeks to crawl before expecting indexed pages to show up.
- Re-check after any URL-structure change (there haven't been any from this SEO work — canonical URLs match the existing `/{lang}/...` routes).
- If `robots.txt`/`sitemap.xml` ever 404 or 500, every one of these consoles will flag it — that's usually the fastest way to notice a deploy went wrong.

---

## Architecture

### `src/Services/Seo.php`
Stateless. Owns the single source of truth for scheme/host, indexed languages, and default language:
```php
Seo::BASE_URL       // 'https://balonkydecor.cz'
Seo::LANGUAGES       // ['cs', 'sk', 'en', 'uk', 'ru']
Seo::DEFAULT_LANG    // 'cs'
Seo::canonicalUrl(string $lang, string $path): string
Seo::alternateUrls(string $path): array           // 5 languages + x-default
Seo::organizationJsonLd(string $siteName, string $phone, string $email): string
```

### `src/Services/Sitemap.php`
```php
Sitemap::paths(): array     // flat list of indexable paths, e.g. '/shop/SKU-1'
Sitemap::entries(): array   // one ['loc' => ..., 'alternates' => ...] row per path × language
```
Pulls from `ProductModel::allActive()` and `GalleryModel::albums()` — no new queries added to those models.

### `src/Controllers/SeoController.php`
Thin — `robots()` and `sitemap()` just format `Seo`/`Sitemap` output as text/XML. Registered in `routes.php` as static routes, before the `/{lang}/*` group (same FastRoute ordering rule that applies to `/admin/*`).

### DB columns
`meta_title varchar(255)` / `meta_desc varchar(500)`, both nullable, on `product_t`, `page_t` (since `V001`) and `gallery_album_t` (added in `V007__gallery_meta.sql`). Not present on `category_t` (see above).

---

## Tests

```bash
php vendor/bin/phpunit tests/Unit/Services/SeoTest.php tests/Unit/Services/SitemapTest.php --testdox
```

`SeoTest` is pure-logic (no DB). `SitemapTest` seeds fixture products/albums (including an inactive product, to verify it's excluded) against the real Docker MySQL, matching the existing Model-test pattern. `meta_title`/`meta_desc` round-tripping is covered by additions to `ProductModelTest`, `GalleryModelTest`, and `PageModelTest`.

No controller/route tests exist for `SeoController` — this codebase has no controller test harness at all (every existing test is Model/Service-level against a real DB). Verify `/robots.txt` and `/sitemap.xml` manually after changes:

```bash
php -S localhost:8080 -t www &
curl -s http://localhost:8080/robots.txt
curl -s http://localhost:8080/sitemap.xml | python3 -c "import sys,xml.dom.minidom as m; m.parseString(sys.stdin.read())" && echo "valid XML"
```

---

## Known limitations

- No caching for sitemap/robots — acceptable at this catalog size; revisit if the product/gallery catalog grows significantly.
- No structured data for reviews/ratings (feature doesn't exist yet).
- Category pages aren't indexable (see above) — would need a real `/shop/category/{slug}` route to change.
- Search engine registration/verification (Google, Bing, Yandex, Seznam) is a manual, one-time step outside this repo — see "Registering with search engines" above.
