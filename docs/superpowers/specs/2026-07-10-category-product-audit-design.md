# Category/product change auditing — design

Date: 2026-07-10

## Problem

`categories` and `products` don't record who created or last edited a row, or when.
Admin edits are otherwise anonymous — no way to answer "who changed this SKU's price"
or "when was this category created".

## Data model — `database/migrations/V014__category_product_audit.sql`

- `categories`: add `created_by INT NULL`, `created_at DATETIME NOT NULL DEFAULT
  CURRENT_TIMESTAMP` (table had no `created_at` before), `updated_by INT NULL`,
  `updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`.
- `products`: add `created_by INT NULL`, `updated_by INT NULL`, `updated_at DATETIME
  NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` (existing `created_at`
  untouched).
- `created_by`/`updated_by` are `FOREIGN KEY ... REFERENCES users(id) ON DELETE SET
  NULL` — deleting an admin user does not break history; attribution just becomes
  NULL ("(deleted user)" in the UI).
- `updated_at` is DB-managed via `ON UPDATE CURRENT_TIMESTAMP` — the app never sets it
  explicitly. `updated_by` must be set explicitly by the app (MySQL can't know who).
- Existing rows get `created_by`/`updated_by` = NULL and (for categories)
  `created_at` = migration run time — acceptable; history before this feature is
  unknown by definition.

## Models

`CategoryModel::create(array $data, int $userId): int` and
`CategoryModel::update(int $id, array $data, int $userId): void` set `created_by` (create
only) and `updated_by` (both). Same change to `ProductModel::create()`/`update()`.

Admin-facing reads gain audit columns via two `LEFT JOIN users`:
- `CategoryModel::all()` / `findById()` → add `created_by_email`, `created_at`,
  `updated_by_email`, `updated_at`.
- `ProductModel::all()` / `findById()` → same four columns.

Public-facing reads (`allWithTranslation`, `allActive`, `findBySku`) are unchanged —
this is admin-only information.

## Admin controllers

`CategoryController` and `ProductController` pass
`(int) ($_SESSION['admin_user']['id'] ?? 0)` as the `$userId` argument to
`create()`/`update()`.

## Admin templates

- `admin/categories/index.twig`, `admin/products/index.twig`: add an "Updated" column
  showing `updated_by_email ?? '—'` and `updated_at` (fall back to `created_at` if
  never updated).
- `admin/categories/form.twig`, `admin/products/form.twig`: on edit only (not create),
  show an audit line above the form fields: "Created by {created_by_email} on
  {created_at} · Last updated by {updated_by_email} on {updated_at}". Missing
  email (NULL, i.e. deleted user or pre-feature row) renders as
  `t('common.audit.unknown_user')`.

## Translations

New admin keys in all 5 `lang/admin/*.json` files: `categories.col.updated`,
`categories.audit.created`, `categories.audit.updated`, `products.col.updated`,
`products.audit.created`, `products.audit.updated`, `common.audit.unknown_user`.

## Testing

`CategoryModelTest`/`ProductModelTest` (real MySQL): update existing
`create()`/`update()` call sites for the new `$userId` param; add cases asserting
`created_by`/`updated_by`/`created_at`/`updated_at` populate correctly using a fixture
user row (`INSERT IGNORE` into `users` with a fixed test email, per existing shared-DB
fixture convention), and that `updated_at` changes on `update()` while `created_at`
does not.

## Out of scope

- No full change-history log (audit_log table) — only last-editor tracking, per user
  decision.
- No UI to browse deleted users' past attributions beyond showing "(deleted user)".
- No auditing for other entities (gallery, services, pages) — categories/products only.
