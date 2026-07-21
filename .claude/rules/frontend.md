---
description: Frontend conventions — Twig layout inheritance, t() translations across all 5 language files, lang-prefixed links, no-build vanilla JS, accessibility requirements.
globs: ["templates/**/*.twig", "www/assets/js/**/*.js", "lang/**/*.json"]
alwaysApply: false
---

# Frontend Implementation Conventions

Applies to `templates/`, `www/assets/js/`, and public-facing markup.
Styling has its own rule: `.claude/rules/css-styling.md`.

## Templates (Twig 3)

- Public pages extend `layout/base.twig`; admin pages extend `layout/admin-base.twig`.
  Never inline a full HTML document in a page template.
- Every public page template starts with the SEO blocks (see `.claude/rules/seo.md`):
  ```twig
  {% block title %}{{ page.meta_title ?? t('x.title') }} — {{ t('site.name') }}{% endblock %}
  {% block meta_desc %}{{ page.meta_desc ?? '' }}{% endblock %}
  ```
- Page-specific `<head>` additions go in `{% block head %}`.
- Rely on Twig auto-escaping. `|raw` is allowed only for values that are already
  JSON-encoded or sanitized server-side (e.g. `organization_json_ld`) — never for
  user/admin-entered text.

## Text & links

- **No hardcoded user-facing strings.** Every visible string goes through `t('key')`,
  with the key added to **all five** files: `lang/{cs,en,ru,uk,sk}.json` (public) or
  `lang/admin/*.json` (admin). All five files must keep identical key sets.
- All public links are language-prefixed: `href="/{{ lang }}/shop"`. Never emit a
  public link without the `{{ lang }}` prefix (exceptions: `/assets/*`, `/admin/*`,
  `/payment/notify`, `sitemap.xml`, `robots.txt`).
- Uploaded media renders from `/assets/uploads/products/` or `/assets/uploads/gallery/`;
  image thumbnails are the same filename with a `thumb_` prefix.

## Assets & JavaScript

- No build step, no npm, no frameworks. JavaScript is small vanilla files in
  `www/assets/js/` — public: `nav.js` (mobile menu), `hero-carousel.js`,
  `product-gallery.js`; admin-only: `admin-notifications.js`,
  `admin-sortable-table.js`. Include with `?v={{ asset_v('...') }}` for cache
  busting — same for CSS. Skipping this means a stale cached copy can run against
  updated markup after a deploy (this bit `hero-carousel.js` in practice); `nav.js`
  and `product-gallery.js` currently lack it and should get it next time they're
  touched.
- Prefer solving interactivity with HTML/CSS first (`:hover` + `:focus-within`
  dropdowns, `<details>`, etc.); add JS only when markup can't express it.

## Accessibility

- Interactive elements are real `<a>`/`<button>` (with `type="button"` unless
  submitting), never clickable `<div>`/`<span>`.
- Toggles carry `aria-expanded`/`aria-label` (see `.nav-toggle`); dropdown triggers
  carry `aria-haspopup`.
- Images get meaningful `alt` text (entity name), decorative ones `alt=""`.
- Cart-style tables need `data-label` attributes on `<td>` for the stacked
  phone layout.
