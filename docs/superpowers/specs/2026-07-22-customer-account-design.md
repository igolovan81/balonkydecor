# Customer Register / Login / Logout — Design

## Purpose

Give public-site visitors a basic account: register, log in, log out, and reset a
forgotten password. This is account creation only — checkout stays guest-only and
orders are not linked to accounts in this pass.

## Constraints

- No customer auth exists today — only session-backed `Cart`/`Wishlist`/`Compare` and
  a separate admin auth system (`users` table, `$_SESSION['admin_user']`,
  `AuthMiddleware`, `AuthController`). Customer auth is a new, parallel system: its own
  table, its own session key, no interaction with admin auth.
- Registration collects email + password only (no name/phone) — matches the minimal
  scope; a name field can be added via a follow-up migration if checkout integration
  happens later.
- No email verification step — account is active immediately on registration.
- No CSRF token — the codebase has no CSRF pattern anywhere (the contact form has
  none); not introducing a new one here.
- Password reset is included: token-based via the existing `Mailer` service (dev
  fallback logs to `tmp/mail.log`, same as the contact form).

## Data model

New migration `database/migrations/V025__customers.sql`:

```sql
CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  reset_token VARCHAR(64) NULL,
  reset_token_expires DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

`src/Models/CustomerModel.php` (new, static class over `Database::getConnection()`,
mirrors `AdminUserModel`):
- `findByEmail(string $email): ?array`
- `findById(int $id): ?array`
- `create(string $email, string $passwordHash): int` — returns new customer id
- `setResetToken(int $id, string $token, string $expiresAt): void`
- `findByValidResetToken(string $token): ?array` — `WHERE reset_token = ? AND
  reset_token_expires > NOW()`
- `updatePasswordAndClearToken(int $id, string $passwordHash): void` — sets
  `password_hash`, `reset_token = NULL`, `reset_token_expires = NULL`

## Architecture & data flow

- **`src/Controllers/AccountController.php`** (new, extends `BaseController`):
  - `registerForm()` / `registerSubmit()` — validates email format, password ≥ 8
    chars (matches admin setup's rule), password === password_confirm, email not
    already taken (`CustomerModel::findByEmail`). On success: `password_hash()`
    (BCRYPT, same as admin), `CustomerModel::create()`, log the customer in
    (`$_SESSION['customer'] = ['id' => ..., 'email' => ...]`), redirect to
    `/{lang}/account`. On failure: re-render `register.twig` with an inline error and
    the submitted email preserved (never the password).
  - `loginForm()` / `loginSubmit()` — `CustomerModel::findByEmail()` +
    `password_verify()`. Success: set `$_SESSION['customer']`, redirect to
    `/{lang}/account`. Failure: one generic error message (`account.error_login`) —
    never reveals whether the email exists.
  - `logout()` — unsets `$_SESSION['customer']` (not `session_destroy()`, since the
    session is shared with cart/wishlist/compare — destroying it would wipe those
    too), redirects to `/{lang}/login`.
  - `index()` — the account page; if `$_SESSION['customer']` is empty, redirect to
    `/{lang}/login` (inline check, same style as `DashboardController` relying on
    `AuthMiddleware` — here it's a single route so a dedicated middleware would be
    premature). Otherwise renders `account.twig` with the customer's email and
    `created_at`.
  - `forgotForm()` / `forgotSubmit()` — looks up the email; if found, generates
    `bin2hex(random_bytes(32))`, stores it with a 1-hour expiry via
    `CustomerModel::setResetToken()`, and emails a reset link
    (`/{lang}/reset-password?token=...`) via `Mailer::send()`. Always renders the same
    success message regardless of whether the email was found, to avoid account
    enumeration.
  - `resetForm()` — reads `?token=`, 404s (via rendering an inline error, not an HTTP
    404 — this is user-facing, not an SEO/entity concern) if
    `CustomerModel::findByValidResetToken()` returns null. Otherwise renders the new
    password form with the token as a hidden field.
  - `resetSubmit()` — re-validates the token, checks password ≥ 8 chars and
    password === password_confirm, calls
    `CustomerModel::updatePasswordAndClearToken()`, flashes success
    (`account.reset_success`), redirects to `/{lang}/login`.
- **Routes** (`src/routes.php`, under the `/{lang}/...` public block — fully
  lang-prefixed, so no FastRoute static/variable ordering conflict with the admin
  routes):
  ```
  GET/POST /{lang}/register
  GET/POST /{lang}/login
  GET      /{lang}/logout
  GET      /{lang}/account
  GET/POST /{lang}/forgot-password
  GET/POST /{lang}/reset-password
  ```
- **`BaseController::render()`** gains a `customer` entry (`$_SESSION['customer'] ??
  null`) merged into every template's data, so `layout/base.twig` can branch on
  logged-in state without every controller passing it explicitly.

## UI components

- Five new templates in `templates/public/`, all extending `layout/base.twig`,
  following the existing `contact.twig` form conventions (`.form-group`,
  `.btn.btn-primary`, inline `.form-error`/`.form-success` paragraphs, re-render with
  preserved non-sensitive values on validation failure):
  - `register.twig` — email, password, confirm password
  - `login.twig` — email, password, link to `/forgot-password`
  - `account.twig` — shows email + "member since" date, a logout button/link
  - `forgot-password.twig` — email field, generic success message after submit
  - `reset-password.twig` — new password + confirm, hidden token field
- `layout/base.twig` header: small account area next to the existing
  cart/wishlist/compare links.
  - Logged out: `nav.login` and `nav.register` links.
  - Logged in: `nav.account` link and `nav.logout` link.
- `noindex,nofollow` on all five pages (same treatment as cart/checkout/wishlist) —
  none are added to `Sitemap::paths()`.

## Error handling

- Registration: duplicate email → `account.error_email_taken`; weak password/mismatch
  → `account.error_password`. Both re-render the form, password fields left blank.
- Login: any failure → single generic `account.error_login`.
- `/account` with no session → redirect (not a 404 — it's an auth gate, not a missing
  entity).
- Reset-password with a missing/expired/invalid token → inline error
  (`account.error_reset_token`) with a link back to `/forgot-password`; token is
  single-use (cleared on successful reset) and expires after 1 hour.
- Forgot-password never discloses whether the email is registered.

## Translations

New keys added to all five `lang/{cs,en,ru,uk,sk}.json` files:
- `nav.login`, `nav.register`, `nav.account`, `nav.logout`
- `account.register_title`, `account.login_title`, `account.forgot_title`,
  `account.reset_title`, `account.title` (account page heading)
- `account.email`, `account.password`, `account.password_confirm`,
  `account.new_password`
- `account.register_submit`, `account.login_submit`, `account.forgot_submit`,
  `account.reset_submit`, `account.logout`
- `account.error_email_taken`, `account.error_password`, `account.error_login`,
  `account.error_reset_token`
- `account.forgot_link`, `account.forgot_success`, `account.reset_success`
- `account.member_since`

## Testing

- `tests/Unit/Models/CustomerModelTest.php` against real Docker MySQL, following
  existing conventions: `uniqid()`-suffixed emails for uniqueness, exact-value
  assertions. Covers `create`+`findByEmail`, `findById`, reset-token set/find/expiry
  (`findByValidResetToken` returns null once expired or after
  `updatePasswordAndClearToken` clears it).
- TDD: write these tests first, watch them fail, then implement `CustomerModel`.
- `AccountController` stays untested per project convention (controllers are
  currently untested); verify via `/start` + browser — register, log out, log back in,
  forgot-password (check `tmp/mail.log`), reset, log in with the new password.

## Out of scope

- Linking orders/checkout to a logged-in customer.
- Email verification on registration.
- Name/phone/profile fields beyond email.
- "Remember me" / persistent login beyond the PHP session.
- Admin visibility into the customers table (no admin UI for this table in this pass).
