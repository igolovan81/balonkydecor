# Service/gallery album change auditing — design

Date: 2026-07-10

## Problem

`services` and `gallery_albums` (public label "Naše realizace" / completed projects)
don't record who created or last edited a row, or when. This is the same gap that
`categories`/`products` had before `V014__category_product_audit.sql` added
`created_by`/`updated_by`/timestamp tracking (see
`docs/superpowers/specs/2026-07-10-category-product-audit-design.md`). That prior
design explicitly scoped out gallery/services — this spec closes that gap using the
identical pattern.

## Data model — `database/migrations/V017__service_gallery_audit.sql`

- `services`: add `created_by INT NULL`, `updated_by INT NULL`, `updated_at DATETIME
  NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` (table already has
  `created_at`, untouched).
- `gallery_albums`: same three columns added (table already has `created_at`,
  untouched).
- `created_by`/`updated_by` are `FOREIGN KEY ... REFERENCES users(id) ON DELETE SET
  NULL` — deleting an admin user does not break history; attribution becomes NULL
  ("(deleted user)" in the UI, same as categories/products).
- `updated_at` is DB-managed via `ON UPDATE CURRENT_TIMESTAMP` — the app never sets it
  explicitly. `updated_by` must be set explicitly by the app.
- Existing rows get `created_by`/`updated_by` = NULL — history before this feature is
  unknown by definition.

## Models

`ServiceModel::create(array $data, int $userId): int` and
`ServiceModel::update(int $id, array $data, int $userId): void` set `created_by`
(create only) and `updated_by` (both). Same change to
`GalleryModel::createAlbum()`/`updateAlbum()`.

Admin-facing reads gain audit columns via two `LEFT JOIN users`:
- `ServiceModel::all()` / `findById()` → add `created_by_email`, `created_at`,
  `updated_by_email`, `updated_at`.
- `GalleryModel::allAlbums()` / `findAlbumById()` → same four columns.

Public-facing reads (`ServiceModel::allWithTranslation`, `GalleryModel::albums()`,
`GalleryModel::album()`) are unchanged — this is admin-only information.

## Admin controllers

- `ServiceController` already computes `$userId = (int) ($_SESSION['admin_user']['id']
  ?? 0)` in `createSubmit()`/`editSubmit()` for its existing `Notifier::notify()`
  calls — reuse that value as the new `create()`/`update()` argument, no new session
  read needed.
- `GalleryController::createSubmit()`/`editSubmit()` don't currently read the session
  user — add the same `$userId = (int) ($_SESSION['admin_user']['id'] ?? 0)` line and
  pass it to `createAlbum()`/`updateAlbum()`.

## Admin templates

- `admin/services/index.twig`, `admin/gallery/index.twig`: add an "Updated" column
  showing `updated_by_email ?? '—'` and `updated_at` (fall back to `created_at` if
  never updated), reusing the existing `.audit-meta` CSS class (already added to
  `www/assets/css/admin.css` by the categories/products feature — no new CSS needed).
- `admin/services/form.twig`, `admin/gallery/form.twig`: on edit only (`{% if service
  %}` / `{% if album %}`), show an audit line above the form fields: "Created by
  {created_by_email} on {created_at} · Last updated by {updated_by_email} on
  {updated_at}". Missing email renders as the existing
  `t('common.audit.unknown_user')` key (already present in all 5 admin lang files from
  the prior feature — reused, not re-added).

## Translations

New admin keys in all 5 `lang/admin/*.json` files, inserted at the same alphabetical
positions used for the categories/products keys: `services.col.updated`,
`services.audit.created`, `services.audit.updated`, `gallery.col.updated`,
`gallery.audit.created`, `gallery.audit.updated`. `common.audit.unknown_user` is
reused as-is.

## Testing

`ServiceModelTest`/`GalleryModelTest` (real MySQL): update existing
`create()`/`update()`/`createAlbum()`/`updateAlbum()` call sites for the new `$userId`
param; add cases asserting `created_by`/`updated_by`/`created_at`/`updated_at`
populate correctly using a fixture user row (`INSERT IGNORE` into `users` with a fixed
test email, per existing shared-DB fixture convention), and that `updated_at` changes
on `update()`/`updateAlbum()` while `created_at` does not.

## Out of scope

- No full change-history log (audit_log table) — only last-editor tracking, matching
  the categories/products precedent and the user's explicit choice this round.
- No audit tracking on `gallery_images` (individual photos/videos) or
  `service_t`/`gallery_album_t` translation rows — only the parent `services` and
  `gallery_albums` rows.
- No auditing for other remaining entities (`pages`, `orders`) — out of scope for this
  round.
