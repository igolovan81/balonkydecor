# Account: Customer Info / Orders / Change Password — Design

## Purpose

Extend the basic customer account (register/login/logout, see
`2026-07-22-customer-account-design.md`) with three account-management pages,
modeled loosely on a competitor's "My Account" sidebar (screenshot reference: Customer
info / Orders / Change password): edit profile details, view order history, and
change password while logged in (separate from the existing email-token reset flow).

## Constraints

- The prior design explicitly left "linking orders/checkout to a logged-in customer"
  out of scope. This design brings that in, but **only going forward**: orders get a
  nullable `customer_id` set at checkout time if the buyer is logged in. Orders placed
  as a guest, or before this feature existed, are not retroactively matched by email —
  matching by email would let anyone who registers with someone else's (unverified)
  email see that person's past orders.
- `customers.name` is a single field, not split first/last — matches how
  `orders.customer_name` already works elsewhere in this codebase, rather than
  mirroring the reference screenshot's two-field layout.
- Changing the account email requires re-entering the current password (extra
  confirmation) — prevents a hijacked session from silently taking over the account.
  Name/phone save without this extra step.
- Order history has no pagination in this pass — a single small business's customer
  won't accumulate hundreds of orders; add pagination later if that assumption breaks.
- No new CSS: reuses the existing `.shop-layout`/`.shop-sidebar`/`.cat-filter-list`/
  `.cat-filter` two-column sidebar pattern already used on `/shop` (200px sidebar +
  content, already responsive, collapses to one column ≤768px) and the existing
  `.cart-table`/`.order-status--{status}` styling from the order-status page.
- The old simple account page's standalone "Log out" button is removed — logout
  already lives in the header nav (added in the prior design), so it would be a
  duplicate control now that this page has real form content.

## Data model

New migration `database/migrations/V026__customers_profile_and_order_link.sql`:

```sql
ALTER TABLE `customers`
  ADD COLUMN `name`  VARCHAR(255) NULL AFTER `email`,
  ADD COLUMN `phone` VARCHAR(50)  NULL AFTER `name`;

ALTER TABLE `orders`
  ADD COLUMN `customer_id` INT NULL AFTER `id`,
  ADD CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
  ADD INDEX `idx_orders_customer` (`customer_id`);
```

`src/Models/CustomerModel.php` gains:
- `updateProfile(int $id, string $name, string $phone): void` — updates `name`/`phone`
  only.
- `updateEmail(int $id, string $email): void` — updates `email` only; called only
  after the controller has verified the current password and email uniqueness.

(No new method for password change — reuses the existing
`updatePasswordAndClearToken(int $id, string $passwordHash): void` from the prior
design; setting already-null reset-token fields to null again is harmless.)

`src/Models/OrderModel.php` gains:
- `forCustomer(int $customerId): array` — `SELECT order_number, status, total_amount,
  created_at FROM orders WHERE customer_id = ? ORDER BY created_at DESC`.
- `create()` gains a fourth parameter `?int $customerId = null` (appended, so existing
  callers/tests are unaffected), inserted into the new `orders.customer_id` column.

## Architecture & data flow

- **`src/Controllers/AccountController.php`** (existing, extends `BaseController`)
  gains:
  - `index()` (GET `/{lang}/account`) — now renders the Customer Info edit form
    (`account/customer-info.twig`) instead of the old read-only summary. Still
    redirects to `/login` if `$_SESSION['customer']` is empty (unchanged behavior).
  - `update()` (POST `/{lang}/account`) — reads `name`, `phone`, `email`,
    `current_password`. Logic:
    1. Load the current customer via `CustomerModel::findById()`.
    2. If `email` is unchanged from the current value: call
       `CustomerModel::updateProfile($id, $name, $phone)`, flash
       `account.update_success`, redirect to `/{lang}/account`.
    3. If `email` changed: verify `current_password` with `password_verify()` against
       the stored hash. Empty or wrong password → re-render the form with
       `account.error_current_password`, submitted `name`/`phone`/`email` preserved,
       **no DB writes** (atomic — a failed email change doesn't silently save
       name/phone either, to avoid a confusing partial save).
    4. If the password check passes: check `CustomerModel::findByEmail($email)` — if
       it returns a row with a different id, re-render with
       `account.error_email_taken`, no writes.
    5. Otherwise: `CustomerModel::updateEmail($id, $email)`,
       `CustomerModel::updateProfile($id, $name, $phone)`, update
       `$_SESSION['customer']['email']` to keep the session in sync, flash
       `account.update_success`, redirect.
  - `ordersList()` (GET `/{lang}/account/orders`) — redirects to `/login` if not
    logged in (same guard as `index()`); otherwise renders `account/orders.twig` with
    `OrderModel::forCustomer((int) $_SESSION['customer']['id'])`.
  - `passwordForm()` (GET `/{lang}/account/password`) — same login guard; renders
    `account/password.twig`.
  - `passwordSubmit()` (POST `/{lang}/account/password`) — reads `current_password`,
    `password`, `password_confirm`. Verifies `current_password` against the stored
    hash (wrong/empty → `account.error_current_password`); validates
    `strlen($password) >= 8 && $password === $passwordConfirm` (fails →
    `account.error_password`); on success calls
    `CustomerModel::updatePasswordAndClearToken()`, flashes
    `account.password_success`, redirects to `/{lang}/account`.
- **Routes** (`src/routes.php`, alongside the existing account routes): add
  `GET /{lang}/account/orders`, `GET/POST /{lang}/account/password`. The existing
  `GET /{lang}/account` route now points at the new `index()` behavior; add
  `POST /{lang}/account` → `update()`.
- **`CheckoutController::submit()`** — the `OrderModel::create()` call gains a fifth
  argument: `$_SESSION['customer']['id'] ?? null` (session is already started by this
  point via `Cart::isEmpty()`'s internal `session_start()`).

## UI components

- `templates/public/account/` (new folder, mirrors the existing `checkout/`
  subfolder): `customer-info.twig`, `orders.twig`, `password.twig`, and a shared
  `_sidebar.twig` partial (three links — Customer Info / Orders / Change Password —
  using the existing `.cat-filter`/`.active` pattern, active state via
  `current_path`), included via `{% include %}` in all three pages. The old
  `templates/public/account.twig` is deleted.
- Each page: `<div class="container shop-layout">` wrapping `<aside
  class="shop-sidebar">{% include 'public/account/_sidebar.twig' %}</aside>` +
  a content column reusing `.contact-form`/`.form-group`/`.btn.btn-primary` (same
  form styling already used by register/login/reset).
- Page heading pattern (matches the reference screenshot's "My account - Customer
  info" style): `{{ t('account.title') }} — {{ t('account.nav_customer_info') }}` (and
  the equivalent for Orders / Change Password).
- **Customer Info form**: name (required), phone (optional), email, and a
  `current_password` field always shown but **not** marked `required` in the markup
  (it's only actually required server-side when the email changes) — one static form,
  no JS to conditionally show/hide it, consistent with the project's "no build step /
  minimal JS" convention. The field's label/placeholder notes it's "only needed if
  you change your email" (`account.current_password`).
- **Orders page**: table (reusing `.cart-table`) with columns Order Number
  (`checkout.order_number`), Date, Status (`order-status--{status}` badge, reusing
  `order.status.*` keys), Total (`order.total`); each row links to the existing
  `/{lang}/order/{number}` status page (unchanged, still reachable by anyone with the
  number — existing behavior, not touched here). Empty state:
  `account.orders_empty`.
- **Change Password form**: current password, new password, confirm — same
  `.contact-form` styling as the reset-password page.
- All three pages keep `noindex,nofollow`, matching every other account/cart/checkout
  page.

## Error handling

- `index()`/`ordersList()`/`passwordForm()` with no session → redirect to `/login`
  (existing pattern from the prior design).
- `update()`: see the 5-step atomic logic above — any validation failure re-renders
  with an inline error and preserves non-sensitive submitted values; never writes
  partial changes.
- `passwordSubmit()`: wrong current password → `account.error_current_password`; weak
  password or mismatch → `account.error_password`. Neither touches the stored hash.
- Name is required (non-empty after trim); phone is optional. Email must pass
  `FILTER_VALIDATE_EMAIL`.

## Translations

New keys added to all five `lang/{cs,en,ru,uk,sk}.json` files:
- `account.nav_customer_info`, `account.nav_orders`, `account.nav_password`
- `account.name`, `account.phone`, `account.current_password`
- `account.update_submit`, `account.update_success`
- `account.error_current_password`
- `account.password_submit`, `account.password_success`
- `account.orders_title`, `account.orders_empty`, `account.orders_date`

Reused from the existing key set (no changes needed): `account.email`,
`account.password`, `account.password_confirm`, `account.new_password`,
`account.title`, `account.error_email_taken`, `account.error_password`,
`checkout.order_number`, `order.status.*`, `order.total`.

## Testing

- `tests/Unit/Models/CustomerModelTest.php` gains cases for `updateProfile()` and
  `updateEmail()` (real Docker MySQL, existing `uniqid()` fixture conventions).
- `tests/Unit/Models/OrderModelTest.php` gains a case for `forCustomer()` (create an
  order with a `customer_id`, assert it comes back; create one without, assert it
  doesn't leak into another customer's list) and confirms `create()`'s new optional
  5th parameter defaults to `null` without breaking the existing test.
- TDD throughout: write these tests first, watch them fail, then implement.
- `AccountController`/`CheckoutController` changes stay untested per project
  convention (controllers are currently untested); verify via `/start` + browser —
  edit profile, change email (with and without correct password), place an order
  while logged in and confirm it shows on `/account/orders`, change password and
  confirm re-login works with the new password.

## Out of scope

- Retroactive linking of pre-existing guest orders by email.
- Pagination on the orders list.
- Addresses, recurring payments, downloadable products, reviews, GDPR tools, gift
  cards — everything else in the reference screenshot's sidebar that wasn't
  explicitly requested.
- Prefilling checkout's name/email/phone from a logged-in customer's profile.
- Company/VAT fields.
