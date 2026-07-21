---
description: SEO conventions — title/meta_desc template blocks with DB overrides, canonical/hreflang via Seo service, sitemap registration for new routes, 404 rules, JSON-LD escaping.
globs: ["templates/public/**/*.twig", "templates/layout/base.twig", "src/routes.php", "src/Services/Seo.php", "src/Services/Sitemap.php", "src/Controllers/SeoController.php"]
alwaysApply: false
---

# SEO Implementation Conventions

Central service: `src/Services/Seo.php` (`BASE_URL`, `LANGUAGES`, `DEFAULT_LANG = cs`).
`BaseController::render()` injects `canonical_url`, `alternate_urls`, and
`organization_json_ld` into every public template — page templates never build URLs
for canonicals/hreflang by hand.

## Every public page

- `layout/base.twig` already emits: `<title>`, meta description, canonical link,
  one `hreflang` link per language plus `x-default` (→ `cs`), and Organization JSON-LD.
- Page templates override the two blocks:
  ```twig
  {% block title %}{{ entity.meta_title ?? t('x.title') }} — {{ t('site.name') }}{% endblock %}
  {% block meta_desc %}{{ entity.meta_desc ?? '' }}{% endblock %}
  ```
  Title pattern is always `Specific — Site Name`.
- Admin-editable overrides live in the translation tables (`meta_title VARCHAR(255)`,
  `meta_desc VARCHAR(500)` — e.g. `product_t`, `page_t`, `gallery_album_t`). When
  adding a new content type, add these columns via migration and surface them in its
  admin form.

## New public routes

When adding a public page/entity type, update **all** of:
1. `src/routes.php` under `/{lang}/...`
2. `Sitemap::paths()` — static paths are listed explicitly; per-entity paths are
   appended from the model (`allActive`/`albums`), which automatically feeds
   `/sitemap.xml` for all five languages with hreflang alternates
3. The page template's `title`/`meta_desc` blocks

`robots.txt` and `sitemap.xml` are served by `SeoController` (lang-less routes).

## Rules that must not regress

- Missing entities return HTTP **404** (`$response->withStatus(404)`), never a 200
  with an empty page — search engines must not index empty variants.
- One canonical URL per language: paths are `/{lang}/{path}`, no trailing-slash
  duplicates, unknown languages fall back to `cs` via `LangMiddleware`.
- JSON-LD is emitted with `|raw`; therefore it must stay `json_encode`d **without**
  `JSON_UNESCAPED_SLASHES` so a malicious settings value cannot contain `</script>`
  (see the comment in `Seo::organizationJsonLd()`).
- All content images need `alt` text; uploaded images are resized (1600px/400px thumb)
  by `ImageUploader` — don't serve originals.
- User-visible URLs use slugs/SKUs (`/shop/{sku}`, `/services/archive/{slug}`), never
  numeric IDs.
