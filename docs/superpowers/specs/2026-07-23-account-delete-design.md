# Account: Delete Account — Design

## Purpose

Add a self-service "delete my account" action to the existing customer account area
(see `2026-07-22-customer-account-design.md` and
`2026-07-22-account-customer-info-orders-password-design.md`), so the e2e Playwright
suite can exercise a full create-account → login → logout → delete-account workflow.
Currently there is no route, model method, or UI for this at all.

## Constraints

- **Hard delete**, not soft delete/anonymization. `orders.customer_id` already has
  `ON DELETE SET NULL` (V026), and `orders` stores `customer_name`/`customer_email`/
  `customer_phone` as snapshot columns independent of the `customers` table — so a
  customer's past orders survive intact, just unlinked from the (now-gone) account.
  No new migration needed.
- Requires re-entering the current password, same bar as the email-change step in
  `update()` — this is irreversible, so it gets at least that much confirmation.
  Additionally uses a JS `confirm()` prompt on submit, matching the existing
  admin destructive-action pattern (see `templates/admin/products/index.twig` etc.).
- Lives as a new section at the bottom of the existing `customer-info.twig` page —
  no new page/route for the form itself, only for the POST target. Keeps the account
  section list (`_sidebar.twig`) unchanged.
- No email notification of deletion — consistent with the rest of the account area,
  which doesn't email on profile/password changes either.
- Out of scope: admin-side customer deletion, bulk/GDPR export tooling, a "type your
  email to confirm" style double-confirmation (password + `confirm()` is enough for
  this app's risk level).

## Data model

No migration. `src/Models/CustomerModel.php` gains:

- `delete(int $id): void` — `DELETE FROM customers WHERE id = ?`.

## Architecture & data flow

- **`src/Controllers/AccountController.php`** gains `deleteAccount()`
  (POST `/{lang}/account/delete`):
  1. `requireLogin()` — no session → redirect to `/{lang}/login` (existing pattern).
  2. Read `current_password` from the body. Verify with `password_verify()` against
     the loaded customer's `password_hash`. Empty/wrong → re-render
     `customer-info.twig` passing `delete_error: 'account.error_delete_password'`
     (separate template variable from the profile form's `error`, so a failed delete
     attempt doesn't touch the profile form's own error state; the existing profile
     form's submitted values aren't touched either — this is a separate form).
  3. On success: `CustomerModel::delete((int) $customer['id'])`, then
     `unset($_SESSION['customer'])`, `flash('success', 'account.delete_success')`,
     redirect to `/{lang}/`.
- **Routes** (`src/routes.php`, alongside the other `/{lang}/account/*` routes):
  `POST /{lang}/account/delete`.

## UI components

- `templates/public/account/customer-info.twig` gains a new block below the existing
  profile form (still inside the same content column, same page — no new template
  file):
  ```twig
  <hr>
  <h2>{{ t('account.delete_account') }}</h2>
  <p>{{ t('account.delete_warning') }}</p>
  {% if delete_error %}
  <p class="form-error">{{ t(delete_error) }}</p>
  {% endif %}
  <form action="/{{ lang }}/account/delete" method="POST" class="contact-form"
        onsubmit="return confirm('{{ t('account.delete_confirm') }}')">
      <div class="form-group">
          <label for="delete_current_password">{{ t('account.current_password') }}</label>
          <input type="password" id="delete_current_password" name="current_password" required>
      </div>
      <button type="submit" class="btn btn-danger">{{ t('account.delete_account') }}</button>
  </form>
  ```
  (`class="btn btn-danger"`, same two-class pattern as `class="btn btn-primary btn-lg"`
  elsewhere.)
- Reuses `.contact-form`/`.form-group`/`.form-error` — no existing danger/destructive
  button style in `style.css`, so add `.btn-danger` (matching the existing sibling
  classes `.btn-primary`/`.btn-lg`, which use a single hyphen rather than the
  `--modifier` BEM convention — following the established precedent for this specific
  component over the general naming rule). It's a one-off color (used only for this
  single button), so per the CSS rules it stays a literal rather than a new `:root`
  token — a solid red background (`#c0392b`) with `--text-inverse` text, darkening on
  hover/focus the same way `.btn-primary`/`--accent-dark` does.
- Page keeps `noindex,nofollow` (unchanged, already set).

## Error handling

- Not logged in → redirect to `/login` (existing `requireLogin()` guard, shared with
  every other account action).
- Wrong/empty current password → `account.error_delete_password`, no DB write, session
  untouched, customer stays logged in.
- No "are you sure your orders will be kept" messaging beyond `account.delete_warning`
  — the warning text should mention that order history is retained for business
  records but the account itself is permanently removed.

## Translations

New keys added to all five `lang/{cs,en,ru,uk,sk}.json` files:
- `account.delete_account` (heading + button text)
- `account.delete_warning` (explanatory paragraph)
- `account.delete_confirm` (JS `confirm()` prompt text)
- `account.error_delete_password`
- `account.delete_success` (flash message shown on the homepage after redirect)

Reused: `account.current_password`.

## Testing

- TDD: `tests/Unit/Models/CustomerModelTest.php` gains
  `test_delete_removes_customer` — create a customer with a `uniqid()` email, call
  `delete()`, assert `findById()` now returns `null`. Write this test first, watch it
  fail, then implement `CustomerModel::delete()`.
- `AccountController::deleteAccount()` stays untested at the unit level per project
  convention (controllers are currently untested).
- New Playwright spec `tests/e2e/account.spec.ts` (**local-only**, not tagged
  `@smoke` — same reasoning as `checkout.spec.ts`: it creates and destroys real data,
  must never run against production): register a unique-email account → assert
  landed on `/account` → log out → log back in with the same credentials → delete
  the account (fill current password, accept the `confirm()` dialog via Playwright's
  `page.on('dialog', ...)`) → assert redirected away from `/account` and that
  logging in again with the same credentials now fails.

## Out of scope

- Soft delete / anonymization / GDPR export.
- Admin-initiated customer deletion.
- Email confirmation of account deletion.
- Retroactively touching existing orders beyond the FK's existing `ON DELETE SET
  NULL` behavior.
