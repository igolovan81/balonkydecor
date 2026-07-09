# Services management — design

Date: 2026-07-09

## Problem

The public `/services` page is stored as a raw HTML blob in `page_t.body` and edited in
the admin Pages form as HTML source — error-prone, not structured, and its
`services-grid`/`service-card` classes have no CSS, so the public page renders as plain
text. Admins should manage services like products/categories: structured CRUD with
translations.

## Data model — `database/migrations/V013__services.sql`

- `services`: `id` INT PK AUTO_INCREMENT, `price_from` INT NULL (Kč), `sort_order` INT
  NOT NULL DEFAULT 0, `created_at` TIMESTAMP
- `service_t`: `service_id` FK → services ON DELETE CASCADE, `lang_code` VARCHAR(5),
  `name` VARCHAR(255) NOT NULL, `description` TEXT, `features` TEXT (one bullet per
  line), PRIMARY KEY (`service_id`, `lang_code`)
- Seed: the current production `/services` content, parsed from all 5 languages into
  idempotent `INSERT IGNORE` rows (5 services: birthdays, weddings, kids' parties,
  corporate, baby shower).

## Model — `ServiceModel`

Mirrors `CategoryModel`: `all()`, `allWithTranslation(lang)` (public; requested lang
with `cs` fallback), `findById()`, `create()`, `update()`, `delete()`,
`getTranslations()`, `setTranslations()` (ON DUPLICATE KEY upsert). `features` is
stored as newline-separated text; the template splits it.

## Admin — `Admin\ServiceController` + templates

Mirrors `CategoryController`: index (list with sort order), createForm/createSubmit,
editForm/editSubmit, delete (POST). `Translator::autoFill` fills missing languages from
the admin's language. Form: 5-language tabs with name, description, features textarea
("one per line" hint); shared fields price_from and sort_order. Routes inside the
`/admin` group: `GET/POST /admin/services[/new|/{id}/edit|/{id}/delete]`. Sidebar link
added to `admin-base.twig`. Admin translation keys (`services.*`) added to all 5
`lang/admin/*.json` files.

## Public `/services`

`PageController::services` passes `services` (from `ServiceModel::allWithTranslation`)
plus the existing `page` (still used for h1 title and SEO meta). `services.twig`
renders a card grid; price renders as `{{ t('services.from') }} {{ price|number_format(0, ',', ' ') }} Kč`,
key added to all 5 public lang files. New CSS (`.services-grid`, `.service-card`)
follows `.claude/rules/css-styling.md`: white cards, 2 columns desktop / 1 on ≤768px,
bronze check bullets, price accented.

`page_t.body` for the services page is no longer rendered; the Pages admin form shows a
hint for the `services` slug that the body is superseded by the Services section. Title
and meta fields remain in use.

## Testing

`ServiceModelTest` (real MySQL): create/find/update/delete, translations upsert +
`allWithTranslation` language fallback, ordering by `sort_order`. TDD throughout.

## Out of scope

- No images per service (user decided).
- No is_active flag — delete removes a service (YAGNI).
- `/services` sitemap/routes unchanged.
