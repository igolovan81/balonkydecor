# Order-Paid Admin Notification & Checkout Prefill — Design

## Purpose

Two small, independent additions to the checkout/payment flow:

1. Notify the shop owner by email (at the `contact_email` setting) whenever a
   customer's order actually gets paid.
2. Prefill the checkout form's name/email/phone fields when the customer is logged in,
   so they don't retype what's already on their account.

## Constraints

- No new database tables or columns — everything needed already exists
  (`orders`, `order_items`, `customers.name`/`customers.phone`, `settings.contact_email`).
- Follows the existing `ContactController::send()` precedent for admin notification
  emails: plain inline HTML built in the controller, sent via `Mailer::send()`, no Twig
  template, no `t()` translation (this is an internal transactional email, not
  user-facing page content).
- `Mailer::send()`'s existing dev fallback (`tmp/mail.log` when `smtp_from` is empty)
  applies automatically — no special-casing needed for local dev/testing.
- Controllers stay untested per project convention (`.claude/rules/unit-testing.md` —
  "Controllers are currently untested"); no model methods are being added, so there's
  no new unit-testable surface. Verified manually instead (see Testing).

## Part 1: Order-paid notification email

### Trigger scope

Fires only from the three customer-facing payment-completion paths in
`PaymentController`:
- `initiate()` — the dev/no-GoPay-credentials bypass branch (marks the order paid
  immediately).
- `paymentReturn()` — GoPay's browser return redirect, when `getStatus()` reports
  `PAID`.
- `notify()` — GoPay's server-to-server IPN webhook, when `getStatus()` reports `PAID`.

Does **not** fire from `Admin/OrderController::updateStatus()` (admin manually setting
an order's status, e.g. for a cash sale) — that path is intentionally silent.

### Duplicate-send guard

GoPay's browser return and its IPN webhook can both independently observe `PAID` for
the same payment and both currently call `OrderModel::updateStatus(..., 'paid', ...)`.
Each of the three trigger sites already loads the order (`OrderModel::findByNumber()`
in `initiate()`, `OrderModel::findByGopayId()` in `paymentReturn()`/`notify()`) before
calling `updateStatus()`. Add a check immediately before that call:

```php
if ($order['status'] !== 'paid') {
    OrderModel::updateStatus(...);
    $this->notifyOrderPaid($order['order_number']);
}
```

If the order is already `'paid'` (the second of two racing callbacks), skip both the
redundant DB write and the email. `paymentReturn()` and `notify()` still proceed with
their existing redirect/200-response behavior either way.

### Email composition

New private method on `PaymentController`:

```php
private function notifyOrderPaid(string $orderNumber): void
```

- Calls `OrderModel::findByNumber($orderNumber)` to get the full order row *with*
  `items` (same method `CheckoutController::confirm()` already uses) — this is a
  deliberate re-fetch after `updateStatus()` rather than reusing the pre-update
  `$order` array, so the email reflects the just-written `paid` status and is
  self-contained (no risk of stale data if this method is ever called from elsewhere).
- Reads `contact_email` from `settings` via `Database::getConnection()`, same inline
  query pattern as `ContactController::send()`. If empty, return without sending
  (mirrors how the rest of the codebase treats unset optional settings — no error, no
  exception).
- Builds an HTML body: order number, customer name/email/phone, pickup date (if set),
  notes (if set), an items table (product name, subtype if present, qty, unit price),
  and the total. All interpolated values pass through `htmlspecialchars()` (the order
  data ultimately originates from customer-submitted checkout input).
- Subject: `"Paid order {$order['order_number']}"`.
- Sends via `Mailer::send($contactEmail, $subject, $html)` — no `$replyTo` (unlike the
  contact form, there's no natural single email to reply to for an order notification).

### Files touched

- `src/Controllers/PaymentController.php` — add the guard at the three call sites, add
  `notifyOrderPaid()`, add `use App\Models\Database;` and `use App\Services\Mailer;`
  imports.

## Part 2: Checkout prefill for logged-in customers

`CheckoutController::index()` currently always renders with `'values' => []`. Change:

```php
$values = [];
if (!empty($_SESSION['customer'])) {
    $customer = CustomerModel::findById((int) $_SESSION['customer']['id']);
    if ($customer) {
        $values = [
            'customer_name'  => $customer['name']  ?? '',
            'customer_email' => $customer['email'] ?? '',
            'customer_phone' => $customer['phone'] ?? '',
        ];
    }
}
```

- `$_SESSION['customer']` only holds `id` + `email` (set at login/register), so the
  full profile (`name`, `phone`) requires the DB lookup via `CustomerModel::findById()`
  — same pattern `AccountController::requireLogin()` already uses.
- `pickup_date` and `notes` are never prefilled — they aren't part of the customer
  profile and don't carry over between orders.
- The template (`templates/public/checkout/index.twig`) already reads
  `values.customer_name` / `values.customer_email` / `values.customer_phone` for both
  the GET and error-redisplay paths — no template change needed. Fields remain plain
  editable inputs; prefill only changes the initial value.
- Guest checkout (no `$_SESSION['customer']`) is unaffected — `values` stays `[]`
  exactly as today.
- `CustomerModel::findById()` may return a customer whose `name`/`phone` are `NULL`
  (nullable columns, e.g. never filled in on the account page) — the `?? ''` fallback
  handles that the same way the form already handles a missing value.

### Files touched

- `src/Controllers/CheckoutController.php` — `index()` method only; add
  `use App\Models\CustomerModel;`.

## Error handling

- `notifyOrderPaid()`: missing `contact_email` setting → silently skip (no error
  surfaced to the customer's redirect flow either way — this must never block the
  payment redirect).
- `notifyOrderPaid()`: `OrderModel::findByNumber()` returning null (shouldn't happen,
  since the caller just confirmed the order exists) → return early, no email.
- Checkout prefill: `CustomerModel::findById()` returning null (stale/deleted session)
  → falls through to empty `values`, same as guest checkout. No redirect or error;
  matches the existing lenient pattern (`AccountController::requireLogin()` unsets the
  session in that case for account pages, but checkout doesn't gate on login at all, so
  it just degrades to guest-style blank fields).

## Testing

No new model methods, so no new PHPUnit coverage (consistent with
`.claude/rules/unit-testing.md`'s "controllers are currently untested" convention —
this logic lives entirely in `PaymentController`/`CheckoutController`).

Manual verification via `/start` + browser, mirroring how `AccountController`'s
forgot-password flow is already verified:
1. **Dev bypass path:** with `gopay_go_id` empty, add an item to cart, check out as a
   logged-in customer, confirm the checkout form is prefilled with the account's
   name/email/phone, complete the order, and confirm one entry appears in
   `tmp/mail.log` addressed to `contact_email` with correct order details.
2. **Duplicate guard:** manually re-POST `/{lang}/payment/gopay` for an
   already-`paid` order number (or re-trigger `paymentReturn`/`notify` for the same
   `gopay_payment_id`) and confirm no second `tmp/mail.log` entry is written.
3. **Guest checkout:** log out, add an item to cart, confirm the checkout form renders
   with blank fields as before.
4. **Admin manual status change:** in `/admin/orders/{number}`, change a `pending`
   order's status to `paid` and confirm no email is sent.

## Out of scope

- Sending a receipt/confirmation email *to the customer* — this is an admin-facing
  notification only, per the request ("email to the Contact email").
- Any change to `GoPay`, `OrderModel`, `CustomerModel`, or `Mailer` — all reused as-is.
- Retrying failed sends, queuing, or logging beyond what `Mailer::send()` already does.
