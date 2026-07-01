# SEO / Search Indexing Design

**Date:** 2026-07-01
**Scope:** Crawlability (robots.txt, sitemap.xml), canonical/hreflang tags, meta title/description wiring, structured data (JSON-LD), HTTPS canonicalization
**Status:** Approved

---

## Overview

The public site currently has no `robots.txt`, no `sitemap.xml`, no canonical or hreflang tags, and inconsistent use of the `meta_title`/`meta_desc` columns that already exist in the DB schema for products, blog posts, and pages. This design makes the 5-language public catalog (cs/sk/en/uk/ru) properly crawlable and indexable by Google, wires up on-page metadata consistently across all content types, and adds structured data for richer search results.

Decisions locked in during brainstorming:
- Full package: crawlability + on-page metadata + structured data (not split into separate follow-ups).
- Canonical scheme/host is `https://balonkydecor.cz` (no `www`). HTTPS is available on WEDOS; the site currently serves plain HTTP and needs a redirect.
- All 5 languages (cs, sk, en, uk, ru) are indexed. `cs` is `x-default`.
- Sitemap and robots.txt are dynamic Slim routes, not static/deploy-time-generated files — content added via the admin panel (the normal workflow) must appear without a code deploy.

---

## 1. Crawlability: `robots.txt` + `sitemap.xml`

New controller `src/Controllers/SeoController.php`, extends `BaseController`. Two new routes registered in `routes.php`, **before** the `/{lang}/*` group (same ordering rule that already applies to `/admin/*`):

```php
$app->get('/robots.txt',  SeoController::class . ':robots');
$app->get('/sitemap.xml', SeoController::class . ':sitemap');
```

### `GET /robots.txt`
Returns `Content-Type: text/plain`:
```
User-agent: *
Disallow: /admin/
Disallow: /*/cart
Disallow: /*/checkout
Disallow: /*/order/
Disallow: /*/payment/

Sitemap: https://balonkydecor.cz/sitemap.xml
```

### `GET /sitemap.xml`
Returns `Content-Type: application/xml`. For each of the 5 languages, emits one `<url>` entry per indexable page:

- `/{lang}/` (home)
- `/{lang}/shop`
- `/{lang}/shop/{slug}` — one per row from `ProductModel::allActive(lang)`
- `/{lang}/services`
- `/{lang}/gallery`
- `/{lang}/gallery/{slug}` — one per row from `GalleryModel::albums(lang)`
- `/{lang}/blog`
- `/{lang}/blog/{slug}` — one per row from `BlogModel::published(lang, page: 1, perPage: large-enough-limit)`
- `/{lang}/contact`

Each `<url>` includes `<xhtml:link rel="alternate" hreflang="{lang}" href="...">` children for all 5 language variants of that same logical page, plus one `hreflang="x-default"` pointing at the `cs` URL. Cart/checkout/order/payment/confirm pages are excluded (session-specific, already `Disallow`'d).

No caching layer. Query cost (a handful of `SELECT`s per language) is negligible for this catalog's size and expected crawl frequency.

---

## 2. Canonical + hreflang tags on every page

`BaseController::render()` already computes `current_path` (URL with the `/{lang}` prefix stripped). Extend it to also compute and inject:

- `canonical_url` = `https://balonkydecor.cz/{lang}{current_path}`
- `alternate_urls` = array of `{lang, url}` for all 5 configured languages, plus `{lang: 'x-default', url: <cs url>}`

`templates/layout/base.twig` renders these in `<head>`:
```twig
<link rel="canonical" href="{{ canonical_url }}">
{% for alt in alternate_urls %}
<link rel="alternate" hreflang="{{ alt.lang }}" href="{{ alt.url }}">
{% endfor %}
```

This is the same alternate-URL derivation logic used by the sitemap route (same inputs: base host, language list, current path) — both independently compute from the same source of truth (configured `languages` list + current path), not a shared code path, since one runs per-request against a single path and the other iterates the whole catalog.

**Session-specific pages** (`cart`, `checkout`, `checkout/confirm`, `order/status`, `payment/*`) additionally render:
```twig
<meta name="robots" content="noindex,nofollow">
```
added directly in those templates' `<head>`-contributing blocks.

---

## 3. Wiring up `meta_title` / `meta_desc` consistently

**Current state (verified against the actual Model code, not just the schema):** `product_t`, `blog_post_t`, `page_t` have `meta_title varchar(255)` / `meta_desc varchar(500)` columns, but the columns are only read by the single-item *public* lookups — `ProductModel::findBySku()`, `BlogModel::findBySlug()`, `PageModel::find()`. The *admin* round-trip (`getTranslations()` / `setTranslations()` on `ProductModel`, `BlogModel`, and `PageModel::upsert()`) never selects or writes `meta_title`/`meta_desc` for **any** content type — so despite the columns existing since `V001`, no admin form has ever been able to set them; they are and always have been `NULL` in every environment. `gallery_album_t` doesn't have the columns at all yet. On top of that, `<title>` blocks hardcode `{{ product.name }} — {{ site.name }}` etc., ignoring `meta_title` even where it is readable.

**Categories are excluded from this section.** `category_t` deliberately does *not* get `meta_title`/`meta_desc` columns: categories have no dedicated indexable URL (`/shop?category=X` is a query-param filter on the single `/shop` route — see "Out of Scope" below), so the fields would render nowhere. Adding them now would repeat the exact dead-column mistake just found in `PageModel`, plus clutter the category admin form with inputs that silently do nothing. If categories ever get real landing pages, adding these two columns at that point is a trivial follow-up migration.

**Changes:**

1. **New migration `database/migrations/V007__gallery_meta.sql`:**
   ```sql
   ALTER TABLE gallery_album_t ADD COLUMN meta_title VARCHAR(255) DEFAULT NULL,
                               ADD COLUMN meta_desc  VARCHAR(500) DEFAULT NULL;
   ```

2. **Template `<title>` blocks** for product/blog/page/gallery-album templates change to:
   ```twig
   {% block title %}{{ x.meta_title ?? x.name }} — {{ t('site.name') }}{% endblock %}
   ```
   (falls back to existing name/title field when `meta_title` is empty, same pattern `meta_desc` already uses).

3. **`ProductModel`, `BlogModel`, `GalleryModel`:** extend `getTranslations()` / `setTranslations()` (`getAlbumTranslations()` / `setAlbumTranslations()` for Gallery) to read/write `meta_title`/`meta_desc`. This is a genuinely new capability for all three — their admin round-trip has never touched these columns (see "Current state" above); only the separate single-item public lookups (`findBySku`, `findBySlug`) could read them, and only because those queries happened to include the columns.

4. **`PageModel` is a further special case on top of point 3.** Unlike the other models, it doesn't use a batch `setTranslations()` — it has a positional `upsert(slug, lang, title, body)` called once per language, and its `meta_title`/`meta_desc` columns are currently dead (selected by `find()` but never written, since `upsert()` doesn't accept them and `allTranslations()` doesn't select them). Fix:
   - `PageModel::upsert()` signature becomes `upsert(string $slug, string $lang, string $title, string $body, ?string $metaTitle, ?string $metaDesc)`, and its `INSERT ... ON DUPLICATE KEY UPDATE` includes both columns.
   - `PageModel::allTranslations()` adds `pt.meta_title, pt.meta_desc` to its `SELECT`.
   - `PageController::editSubmit()` passes `$t['meta_title'] ?? null, $t['meta_desc'] ?? null` through to `upsert()`.

5. **Admin forms** (`templates/admin/products/form.twig`, `blog/form.twig`, `gallery/form.twig` (album), `pages/form.twig`): add two inputs per language block, inside the existing `t[{{ lang }}][...]` loop:
   ```twig
   <input type="text" name="t[{{ lang }}][meta_title]" value="{{ translations[lang].meta_title ?? '' }}" maxlength="255">
   <textarea name="t[{{ lang }}][meta_desc]" maxlength="500">{{ translations[lang].meta_desc ?? '' }}</textarea>
   ```
   For Product/Blog/Gallery, no controller changes are needed beyond point 3: those admin controllers already pass `$body['t'] ?? []` straight through to `setTranslations()` without touching individual keys, so new form fields flow through automatically once the Models accept them. Page is the exception per point 4. `categories/form.twig` is untouched.

---

## 4. Structured data (JSON-LD)

- **Sitewide, in `base.twig`:** `Organization` JSON-LD block:
  ```json
  {
    "@context": "https://schema.org",
    "@type": "Organization",
    "name": "<t('site.name')>",
    "url": "https://balonkydecor.cz",
    "telephone": "<settings.contact_phone>",
    "email": "<settings.contact_email>"
  }
  ```
  `contact_phone`/`contact_email` fetched with one query against the `settings` table (`SELECT key, value FROM settings WHERE key IN ('contact_phone','contact_email')`), same table `ContactController` already reads directly via PDO.

- **Product pages (`shop/product.twig`):** `Product` schema:
  ```json
  {
    "@context": "https://schema.org",
    "@type": "Product",
    "name": "<product.name>",
    "image": "<primary product image URL>",
    "description": "<product.description, stripped>",
    "sku": "<product.sku>",
    "offers": {
      "@type": "Offer",
      "price": "<product.price>",
      "priceCurrency": "CZK",
      "availability": "https://schema.org/InStock | https://schema.org/OutOfStock"
    }
  }
  ```
  `availability` is `OutOfStock` when `stock_type = 'limited' AND stock_qty <= 0`, `InStock` otherwise.

- **Breadcrumbs** (`shop/product.twig`, `blog/post.twig`, `gallery/album.twig`): inline `BreadcrumbList` JSON-LD, 3 items each (Home → section index → detail page). Implemented per-template (not a shared macro/include) since each is a trivial 3-item list and the three templates don't otherwise share structure.

**Explicitly out of scope:** `AggregateRating`/`Review` schema (no reviews feature exists), per-category schema (categories are a `/shop?category=` filter, not an indexable page in their own right).

---

## 5. HTTPS canonicalization

`www/.htaccess` gets a redirect rule inserted **before** the existing Slim catch-all rule:
```apache
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

This prevents `http://` and `https://` versions of every page being indexed as separate duplicate-content URLs once sitemap/canonical/hreflang all start emitting `https://` URLs. `www` vs non-`www` is left as-is (non-`www`, matching existing `/deploy` and `/verify` conventions) — no redirect needed there since the site has never used `www`.

---

## Testing

- New `tests/Unit/Controllers/SeoControllerTest.php` (or closest existing pattern for controller tests) — verify `sitemap.xml` includes an entry for a known active product/blog post/gallery album per language, excludes inactive/unpublished ones, and includes correct hreflang alternates. Verify `robots.txt` disallows the expected paths and references the sitemap URL.
- Existing `ProductModelTest`/`BlogModelTest`/`PageModelTest`/`GalleryModelTest` patterns extended to cover `meta_title`/`meta_desc` round-tripping through `setTranslations()`/`getTranslations()` (`upsert()`/`allTranslations()` for Page, `setAlbumTranslations()`/`getAlbumTranslations()` for Gallery).
- Manual verification post-deploy: `curl https://balonkydecor.cz/sitemap.xml`, `curl https://balonkydecor.cz/robots.txt`, view-source a product page for JSON-LD, and run it through Google's Rich Results Test.

---

## Out of Scope

- `AggregateRating`/`Review` structured data (no reviews feature exists).
- Per-category indexable pages and `category_t` meta columns (categories remain a query-param filter on `/shop` — see §3).
- Sitemap/robots caching layer — not justified at this catalog size/traffic.
- `www` → non-`www` redirect — site has never used `www`.
- Migrating existing analytics/Search Console setup (out of this repo's scope) — user handles Search Console verification/submission separately once the sitemap is live.
