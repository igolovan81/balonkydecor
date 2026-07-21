---
description: Database conventions — V0NN__ migration workflow (never edit applied ones), *_t/lang_code translation tables, idempotent seeds, prepared statements, WEDOS privilege caveats.
globs: ["database/migrations/*.sql", "src/Models/**/*.php", "www/migrate.php"]
alwaysApply: false
---

# Database Implementation Conventions

MySQL 8 via PDO. Local dev DB runs in Docker (`docker compose up -d`); prod is WEDOS
shared hosting with limited privileges.

## Migrations

- Every schema or seed-data change is a new file in `database/migrations/` named
  `V0NN__snake_case_description.sql` (next number in sequence, two underscores).
- **Never edit or delete an applied migration** — fix mistakes with a follow-up
  migration (see `V004__fix_services_page_content.sql`).
- Seed/data migrations must be **idempotent**: `INSERT IGNORE` for settings/rows,
  `ON DUPLICATE KEY UPDATE` for upserts.
- Migrations are applied by `www/migrate.php` (token-protected, tracked in
  `schema_migrations`); `/deploy` runs it automatically when new migration files are
  in the last commit. On prod it uses the `db_admin` credentials from
  `config/settings.prod.php` for DDL.
- **WEDOS caveat:** if migrate returns `CREATE command denied`, the tracker is out of
  sync — the migration must be run manually in phpMyAdmin and then recorded with
  `INSERT INTO schema_migrations (version) VALUES ('V0NN__name');`.

## Schema conventions

- Translated entities use a base table plus a `*_t` table (`products` + `product_t`)
  with columns `(entity)_id` + `lang_code` (**always `lang_code`, never `lang`** —
  except `users.lang`, which is the admin UI preference, not a translation).
- Reads join the translation for the requested language and fall back:
  `COALESCE(t.name, a.slug)`. Writes upsert with `ON DUPLICATE KEY UPDATE`.
- `products.category_id` is NOT NULL — default to category 1 when none selected.
- Key/value pairs go in `settings`; new keys need a seed migration **and** an entry in
  `SettingsController::KEYS` to be admin-editable.
- `database/migrations/` is the source of truth for the current schema — don't
  hardcode a table count in docs, it drifts with every new feature (26 tables and
  counting as of `V024__hero_slides.sql`); keep `database/schema.sql` snapshots out
  of git history decisions.

## Access patterns

- All access goes through `Database::getConnection()` — a PDO singleton with
  `ERRMODE_EXCEPTION` and `FETCH_ASSOC`. No second connection, no ORM.
- **Prepared statements with bound parameters, always.** Interpolation into SQL is
  allowed only for identifiers/constants defined in code, never for request data.
- Model classes are static; one model per aggregate (product + its translations +
  images live in `ProductModel`).
- Multi-statement writes that must not partially apply (e.g. order + order_items)
  belong in one method using a transaction.
