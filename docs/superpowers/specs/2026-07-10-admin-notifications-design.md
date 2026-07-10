# Admin change notifications — design

Date: 2026-07-10

## Problem

Admins and editors have no visibility into changes other people make to categories,
products, or services. There's no way to notice, without manually checking, that a
colleague just deleted a category or repriced a product.

## Data model — `database/migrations/V016__notifications.sql`

Fan-out on write: one row per recipient, created at the moment of the action (not a
shared event row + read-tracker join). With a handful of admin/editor users this keeps
every query a flat `WHERE recipient_id = ?`, and "keep all forever" is cheap at this
scale.

```sql
CREATE TABLE notifications (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  recipient_id  INT NOT NULL,
  actor_id      INT NULL,
  actor_label   VARCHAR(255) NOT NULL,
  entity_type   ENUM('category','product','service') NOT NULL,
  entity_id     INT NOT NULL,
  entity_label  VARCHAR(255) NOT NULL,
  action        ENUM('created','updated','deleted') NOT NULL,
  is_read       TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_recipient_unread (recipient_id, is_read),
  INDEX idx_recipient_created (recipient_id, created_at)
);
```

`actor_label` and `entity_label` are snapshotted text, not live joins, so a
notification still reads correctly (actor name, entity name) after the acting user or
the entity itself is deleted. `entity_id` is kept for building a link when the entity
still exists; delete notifications never link anywhere regardless of whether the id is
still valid.

## Models

**`NotificationModel`** (static, `Database::getConnection()`, matches existing model
conventions):

- `create(string $entityType, int $entityId, string $entityLabel, string $action, int $actorId, string $actorLabel): void`
  — `SELECT id FROM users WHERE id != ?` (excludes the actor) and bulk-inserts one row
  per remaining user.
- `unreadCount(int $userId): int`
- `recentAndMarkRead(int $userId, int $limit = 20): array` — fetches the most recent
  `$limit` rows for the user, then sets `is_read = 1` for all currently-unread rows of
  that user in the same call (used by the dropdown-open endpoint).
- `forUser(int $userId, int $page, int $perPage): array` — paginated, newest first, for
  the history page. Does not affect `is_read`.

## Service

**`Notifier`** (`src/Services/Notifier.php`) — thin, pure wrapper around
`NotificationModel::create()`:

```php
Notifier::notify(
    string $entityType, int $entityId, string $entityLabel,
    string $action, int $actorId, string $actorLabel
): void
```

Actor id/email are extracted from `$_SESSION['admin_user']` by the calling controller
and passed in explicitly — mirroring the existing
`$userId = (int) ($_SESSION['admin_user']['id'] ?? 0); Model::create($data, $userId);`
pattern — so `Notifier` itself never touches superglobals and stays unit-testable.

## Controller wiring

One `Notifier::notify(...)` call added at the end of `createSubmit`, `editSubmit`, and
`delete` in `CategoryController`, `ProductController`, `ServiceController` (after the
model write, before the flash/redirect). Actor label is the acting user's email
(`$_SESSION['admin_user']['email']`).

Entity label per type:
- **Category / Service**: the `cs` translation `name` from the translations just
  written (create/update), falling back to `slug` (category) or `'#' . $id` (service,
  which has no slug). Delete: fetch translation before the row is removed.
- **Product**: SKU — stable, unique, and avoids a translation lookup. Delete: read
  `sku` from `ProductModel::findById()` before deleting.

Entity link (only for `created`/`updated`, never `deleted`):
`/admin/{categories|products|services}/{id}/edit`.

## Endpoints

Added inside the existing `$app->group('/admin', ...)` block, new `NotificationController`:

| Method | Path | Purpose |
|---|---|---|
| GET | `/admin/notifications` | Paginated history page, all notifications for the current user (read or not), newest first. |
| GET | `/admin/notifications/unread-count` | JSON `{"count": N}` — polled by JS every 30s to refresh the badge. |
| POST | `/admin/notifications/open` | JSON `{"items": [...]}` of recent notifications; marks them all read as a side effect (`NotificationModel::recentAndMarkRead`). Called when the bell dropdown is opened. |

## Frontend

- `admin-base.twig`: bell icon + unread badge in the header/sidebar (matches existing
  layout conventions), rendered on every admin page via data injected in
  `AdminBaseController::renderAdmin()` (`unread_notifications_count`).
- `www/assets/js/admin-notifications.js` (new, vanilla, no build step, included with
  `?v={{ asset_v('...') }}`):
  - Polls `GET /admin/notifications/unread-count` every 30s, updates the badge
    text/visibility.
  - On bell click: toggles the dropdown; on open, `POST`s to
    `/admin/notifications/open`, renders the returned items into the dropdown, and
    zeroes the badge immediately (no need to wait for the next poll).
- `templates/admin/notifications/index.twig` (new): full history table, same look as
  `admin/categories/index.twig` — columns for actor, action/entity description,
  timestamp, and a link when one applies. Paginated like `OrderController::adminList`.
- New sidebar nav link to `/admin/notifications` in `admin-base.twig`.
- `admin.css`: bell/badge styles and dropdown panel styles, following existing
  flat kebab-case class conventions and design tokens.

## Translations

New keys in all 5 `lang/admin/*.json` files:

- Message templates (9 = 3 entity types × 3 actions), using the existing
  `t(key, params)` placeholder support, e.g.:
  `"notifications.msg.product_updated": "{actor} updated product “{label}”"`
  `"notifications.msg.category_deleted": "{actor} deleted category “{label}”"`
  (and so on for `category_created`, `category_updated`, `product_created`,
  `product_deleted`, `service_created`, `service_updated`, `service_deleted`).
- UI strings: `notifications.bell.aria`, `notifications.empty`,
  `notifications.view_all`, `notifications.page.title`.

## Testing

- `NotificationModelTest` (real Docker MySQL, `uniqid()`-suffixed fixture user emails
  per shared-DB convention): `create()` fans out to all users except the actor;
  `unreadCount()`; `recentAndMarkRead()` returns items and flips `is_read`;
  `forUser()` pagination/ordering; deleted actor/recipient handled via `ON DELETE
  SET NULL` / `ON DELETE CASCADE`.
- `NotifierTest`: correct label/action/params passed through to
  `NotificationModel::create()` for each entity type (can assert via a real DB read
  since `Notifier` has no mockable seams — matches project's no-mocks convention).

## Out of scope

- Email notifications (in-app only, per user decision).
- Notifications for gallery, pages, orders, or settings changes — categories,
  products, services only, per the original request.
- Per-item read state or click-to-read — the dropdown-open action marks everything
  read at once.
- Notification preferences/opt-out per user.
