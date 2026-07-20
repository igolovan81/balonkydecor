# Homepage hero image carousel

## Problem

The homepage currently opens with a plain text-only hero (`templates/public/home.twig`
`<section class="hero">` — heading, subtitle, one CTA button, no image). The business
wants a promotional image carousel instead, similar to the reference screenshot: a
full-width slide with an image on one side and title/subtitle/CTA button on the
other, prev/next arrows, dot pagination, cycling through multiple slides. Slides must
be manageable from the admin panel without a code change.

## Data model

New migration `database/migrations/V024__hero_slides.sql`:

```sql
CREATE TABLE hero_slides (
  id          INT NOT NULL AUTO_INCREMENT,
  image       VARCHAR(255) DEFAULT NULL,
  cta_url     VARCHAR(255) NOT NULL DEFAULT '/shop',
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by  INT DEFAULT NULL,
  updated_by  INT DEFAULT NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hero_slide_t (
  id         INT NOT NULL AUTO_INCREMENT,
  slide_id   INT NOT NULL,
  lang_code  VARCHAR(5) NOT NULL,
  title      VARCHAR(255) NOT NULL,
  subtitle   VARCHAR(500) DEFAULT NULL,
  cta_label  VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY slide_lang (slide_id, lang_code),
  FOREIGN KEY (slide_id) REFERENCES hero_slides(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Mirrors the existing `categories`/`category_t` base+translation pattern; `created_by`/
`updated_by`/`updated_at` mirror the audit columns already on `products`/`categories`.
`is_active` mirrors `products.is_active` — lets an admin pause a seasonal slide
without losing its content.

`cta_url` is a single free-form relative path (e.g. `/shop`, `/services`), not
translated — the public template prepends the `{{ lang }}` prefix at render time,
same as every other internal link in this codebase.

**Seed data** (same migration, idempotent `INSERT IGNORE`): one default slide with
`image = NULL`, `cta_url = '/shop'`, and translations copied from the current
`home.hero_title` / `home.hero_subtitle` / `home.cta` strings for all 5 languages
(cs/en/ru/uk/sk — exact current values pulled from `lang/*.json`). `image` is left
NULL because `www/assets/uploads/` is gitignored — no real uploaded file ships with
the repo or migration, so a NULL/missing image must always degrade to a placeholder
(see Public rendering) rather than a broken `<img>`. This guarantees the homepage
carousel is never empty out of the box; the admin can replace the seed slide's image
and copy afterward.

## Public rendering

- `App\Models\HeroSlideModel::active(string $lang): array` — returns active slides
  (`is_active = 1`) ordered by `sort_order, id`, INNER JOIN `hero_slide_t` for the
  requested `lang_code` (same join style as `ProductModel::findBySku()`'s
  `product_subtypes` + `product_subtype_t`, which also has no non-translated
  fallback field).
- `HomeController::index()` passes `hero_slides = HeroSlideModel::active($lang)` to
  `home.twig`.
- `home.twig`: replace the current `<section class="hero">` block with a carousel
  section. Each slide renders: image (`<img src="/assets/uploads/hero/{{ slide.image }}">`
  when `slide.image` is set, else a `.hero-slide-placeholder` div using the same warm
  gradient/pattern convention as `.product-img-placeholder`) on one side, and
  title/subtitle/CTA (`<a href="/{{ lang }}{{ slide.cta_url }}" class="btn
  btn-primary">{{ slide.cta_label }}</a>`) on the other — split two-column layout
  matching the reference screenshot. Prev/next `<button>` arrows and one `<button>`
  dot per slide, all real interactive elements (not `<div>`), with
  `aria-label`/`aria-current` for accessibility.
- New vanilla JS file `www/assets/js/hero-carousel.js` (no build step, same convention
  as `nav.js`), included with `?v={{ asset_v(...) }}`: toggles an `.active` class to
  show one slide at a time, wires arrow/dot clicks, and auto-advances every 6 seconds
  via `setInterval`, pausing while the carousel has `:hover` or `:focus-within` (same
  accessibility rule already applied to `.nav-item-dropdown`). Only runs if more than
  one slide is present — with a single slide, arrows/dots/autoplay are omitted
  entirely (no JS needed, nothing to cycle to).
- New CSS in `www/assets/css/style.css`: `.hero-carousel`, `.hero-slide`,
  `.hero-slide-placeholder`, `.hero-carousel-arrow` (`--prev`/`--next` modifiers),
  `.hero-carousel-dots` — flat kebab-case, tokens from `:root`, responsive at the
  768px breakpoint (stack image below copy) per `.claude/rules/css-styling.md`.
- New public translation keys (all 5 `lang/*.json`): `home.hero_prev`,
  `home.hero_next`, `home.hero_goto` (placeholder `{n}`, e.g. "Go to slide {n}").
- Cleanup: `home.hero_subtitle` and `home.cta` are removed from all 5 `lang/*.json`
  files — they become unused once the plain-text hero section is replaced.
  `home.hero_title` is kept: `home.twig`'s `{% block title %}` uses it as the page
  `<title>` fallback (`{{ page.meta_title ?? t('home.hero_title') }}`), independent of
  the hero section's own markup.

## Admin CRUD

New `App\Controllers\Admin\HeroSlideController`, mirroring `CategoryController`
(translations) + `ProductController` (image upload):

- Routes (inside the existing `$app->group('/admin', ...)` block in `src/routes.php`):
  - `GET  /admin/hero-slides` → `index`
  - `GET  /admin/hero-slides/new` → `createForm`
  - `POST /admin/hero-slides/new` → `createSubmit`
  - `GET  /admin/hero-slides/{id:[0-9]+}/edit` → `editForm`
  - `POST /admin/hero-slides/{id:[0-9]+}/edit` → `editSubmit`
  - `POST /admin/hero-slides/{id:[0-9]+}/delete` → `delete`
- `App\Models\HeroSlideModel` admin methods: `all()`, `findById()`, `create()`,
  `update()`, `delete()`, `getTranslations()`, `setTranslations()` — same shape as
  `CategoryModel`.
- Image upload: `ImageUploader::upload($tmp, UPLOAD_DIR)` into
  `www/assets/uploads/hero/`, same pattern as `ProductController::handleImageUpload()`.
  On edit, a new upload replaces the old file (old file + its `thumb_` companion
  deleted via `@unlink`, matching `ProductController::delete()`); leaving the file
  input empty on edit keeps the existing image.
- Form fields: image file input (optional on edit), `cta_url` text input,
  `sort_order` number input, `is_active` checkbox, and per-language `title` /
  `subtitle` / `cta_label` inputs wired to the existing `POST /admin/translate`
  auto-translate endpoint via `Translator::autoFill()` (`TRANSLATABLE_FIELDS = ['title',
  'subtitle', 'cta_label']`), exactly like `CategoryController`/`ProductController`.
- Templates: `templates/admin/hero-slides/index.twig` (table: thumbnail, cs title,
  sort order, active toggle, edit/delete — same shape as
  `templates/admin/categories/index.twig`) and `.../form.twig` (same layout as
  `templates/admin/categories/form.twig` plus the image field from
  `templates/admin/products/form.twig`).
- New admin nav link "Hero slides" added to `templates/layout/admin-base.twig`
  (placed after "Categories", before "Orders" — it's homepage merchandising content,
  grouped with the other catalog-adjacent screens), new `nav.hero_slides` key in
  `lang/admin/*.json`.
- `Notifier::notify('hero_slide', $id, $label, 'created'|'updated'|'deleted', ...)` on
  each mutation, same as every other admin entity.

## Testing

- `tests/Unit/Models/HeroSlideModelTest.php` against real Docker MySQL (no mocks, per
  `.claude/rules/unit-testing.md`): create/find/update/delete, translation
  get/set-upsert, `active()` returns only `is_active = 1` rows ordered by
  `sort_order, id` and excludes deleted/inactive slides, `active()` for an unseeded
  language falls back to nothing extra (there is no non-translated fallback — a slide
  with no translation row for the requested lang is simply excluded, matching the
  `product_subtypes` precedent).
- Admin controller wiring, Twig templates, and the carousel's CSS/JS behavior (slide
  transitions, arrow/dot clicks, autoplay pause on hover/focus, placeholder rendering
  when `image` is NULL) are verified manually via `/start` + browser, per this
  project's convention that controllers/templates aren't unit-tested.
