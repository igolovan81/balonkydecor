# Emails

Snapshot of email addresses present in the local dev database (2026-07-23).

## Admin/editor accounts (`users` table)

Real accounts:

| id | email | role | lang |
|----|-------|------|------|
| 1 | topbalonky2030@gmail.com | admin | uk |
| 3 | igolovan81@gmail.com | editor | cs |

Leftover test fixtures (`uniqid()`-style rows created by the PHPUnit suite against the
shared dev DB — see `.claude/rules/unit-testing.md`; safe to ignore):

- category-audit-test@example.com / category-audit-editor2@example.com
- product-audit-test@example.com / product-audit-editor2@example.com
- notif-actor@example.com / notif-recipient@example.com
- notifier-actor@example.com / notifier-recipient@example.com
- service-audit-test@example.com / service-audit-editor2@example.com
- gallery-audit-test@example.com / gallery-audit-editor2@example.com
- specs-verify-test@example.com
- hero-slide-test@example.com / hero-slide-editor2@example.com

## Site email settings (`settings` table)

| key | value | notes |
|-----|-------|-------|
| contact_email | info@balonkydecor.cz | public contact address shown on the site |
| smtp_from | *(empty)* | `Mailer` dev fallback logs to `tmp/mail.log` when unset |
| smtp_host | *(empty)* | |
| smtp_user | *(empty)* | |
| smtp_pass | *(empty)* | |
| smtp_port | 587 | |

Admin-editable via `/admin/settings`; whitelisted keys live in
`SettingsController::KEYS`.

## How emails are sent

Controllers render an HTML body via `$this->fetchEmail($request, 'emails/x.twig', $data)`
(a thin `Twig::fetch()` wrapper on `BaseController`) then hand it to `Mailer::send($to,
$subject, $html, $replyTo = '')`. `Mailer` reads `smtp_from`/`site_name` from `settings`;
with no SMTP host/user/pass configured (current dev state, see above) it logs the
message to `tmp/mail.log` instead of sending — see `.claude/rules/backend.md`.

Templates live in `templates/emails/` and take a `t` array of pre-resolved translation
strings (built by the calling controller from the recipient's `I18n` instance) rather
than calling `t()` directly, since the recipient's language can differ from the
current request's.

## Email templates & trigger use cases

| Template | Sent when | To | Controller |
|----------|-----------|----|----|
| `password-reset.twig` | Customer submits "forgot password" with an email matching a `CustomerModel` record | the customer | `AccountController::forgotSubmit()` |
| `contact-notification.twig` | Visitor submits the public contact form | site's `contact_email` setting (reply-to = visitor's email) | `ContactController::send()` |
| `order-status-changed.twig` | Admin changes an order's status in `/admin/orders/{number}` to a different value | the order's `customer_email` | `Admin\OrderController::updateStatus()` → `notifyStatusChanged()` |
| `order-paid.twig` | An order transitions to `paid` status — via GoPay dev bypass (`initiate()`), the `payment/return` redirect, or the `payment/notify` IPN webhook | site's `contact_email` setting (reply-to = customer's email) — notifies the shop, not the customer | `PaymentController::notifyOrderPaid()`, called from all three payment flow entry points |

Details per template:

- **`password-reset.twig`** — body is just the intro line (`email.password_reset.intro`)
  plus a raw reset link; token is a `random_bytes(32)` hex string valid 1 hour
  (`CustomerModel::setResetToken`).
- **`contact-notification.twig`** — forwards the visitor's name/email/message verbatim;
  labels come from `lang/cs.json` (`contact.*` keys) regardless of the visitor's
  browsing language, since it's read by a Czech-speaking shop owner.
- **`order-status-changed.twig`** — includes the new status label and a link back to
  the public order-status page; language is the customer's saved
  `notification_lang` if set, else `cs`.
- **`order-paid.twig`** — the only template with a line-item table (product/subtype/qty/
  unit price) and total; includes customer contact info and pickup date/notes so the
  shop can fulfill the order without opening the admin panel.

New email use cases should follow the same shape: add a `templates/emails/x.twig`
partial, resolve translation strings into a `t` array from the recipient's own
`I18n` instance (not the current request's), and call `Mailer::send()` — never build
raw `mail()`/SMTP calls in a controller.
