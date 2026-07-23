# Email Templates & Translation — Design

## Purpose

Replace the three hand-built HTML-string emails in the codebase (contact-form
notification, order-paid notification, password-reset) with Twig templates whose
labels are pulled from the existing translation system, instead of hardcoded English.

## Constraints

- No new services, settings, or dependencies — reuses `App\Services\I18n`,
  `App\Services\Mailer`, and the `Slim\Views\Twig` instance every `BaseController`
  subclass already has via `$this->twig`.
- Password-reset stays customer-facing and uses the visitor's language at request time
  (the `I18n` instance `LangMiddleware` already attaches to the request).
- The two admin notifications (contact form, order paid) always render in Czech,
  regardless of which language the customer was browsing in — confirmed with the
  business owner: `contact_email` is a single shared inbox, not tied to a specific
  admin user's language preference.
- Follows the existing "every visible string goes through `t()`, key added to all five
  `lang/*.json` files" rule (`.claude/rules/frontend.md`) — extended here to email
  bodies, which are user/admin-visible text same as page content.
- Reuse existing translation keys wherever an exact match already exists (see
  Translations section) rather than duplicating strings under a new `email.*` group —
  keeps the five JSON files DRY.

## Architecture & data flow

### Rendering mechanism

Each controller already extends `BaseController`, which holds `protected Twig $twig`
(constructor-injected). `Slim\Views\Twig::fetch(string $template, array $data): string`
renders a template to a string without needing an HTTP `Response` — exactly what an
email body needs. No new abstraction: controllers call `$this->twig->fetch(...)`
directly, matching how `$this->render()` already uses the same `$twig` object.

New template directory `templates/emails/` (the Twig root is already `templates/`, per
`Twig::create(__DIR__ . '/../templates', ...)` in `src/app.php`). Each file is a
standalone HTML fragment — it does **not** extend `layout/base.twig` (that's a full
page shell with nav/SEO markup, irrelevant to an email body).

Templates receive a `t` array of pre-resolved label strings (built in the controller
via `$i18n->t('key')`) plus the raw data (order, customer name, message, etc.). Twig's
default auto-escaping covers every `{{ }}` output, so the manual `htmlspecialchars()`
calls in the current `ContactController`/`PaymentController` code go away entirely —
this is a safety improvement, not just a style change.

### Per-email language source

- **`ContactController::send()`** and **`PaymentController::notifyOrderPaid()`**: each
  instantiates `new I18n('cs', __DIR__ . '/../../lang')` — always Czech, independent of
  the current request's language.
- **`AccountController::forgotSubmit()`**: reuses `$request->getAttribute('i18n')` —
  the same instance already carrying the visitor's current site language, exactly like
  `BaseController::render()` does.

### Templates

`templates/emails/contact-notification.twig`:
```twig
<p><strong>{{ t.name }}:</strong> {{ name }}</p>
<p><strong>{{ t.email }}:</strong> {{ email }}</p>
<p><strong>{{ t.message }}:</strong></p>
<p style="white-space: pre-line;">{{ message }}</p>
```

`templates/emails/order-paid.twig`:
```twig
<p><strong>{{ t.order }}:</strong> {{ order.order_number }}</p>
<p><strong>{{ t.customer }}:</strong> {{ order.customer_name }}</p>
<p><strong>{{ t.email }}:</strong> {{ order.customer_email }}</p>
<p><strong>{{ t.phone }}:</strong> {{ order.customer_phone }}</p>
{% if order.pickup_date %}<p><strong>{{ t.pickup_date }}:</strong> {{ order.pickup_date }}</p>{% endif %}
{% if order.notes %}
<p><strong>{{ t.notes }}:</strong></p>
<p style="white-space: pre-line;">{{ order.notes }}</p>
{% endif %}
<table border="1" cellpadding="6" cellspacing="0">
    <thead>
        <tr><th>{{ t.item }}</th><th>{{ t.qty }}</th><th>{{ t.unit_price }}</th></tr>
    </thead>
    <tbody>
    {% for item in order.items %}
        <tr>
            <td>{{ item.product_name_snapshot }}{% if item.subtype_name_snapshot %} — {{ item.subtype_name_snapshot }}{% endif %}</td>
            <td>{{ item.quantity }}</td>
            <td>{{ item.unit_price|number_format(2, '.', ' ') }} Kč</td>
        </tr>
    {% endfor %}
    </tbody>
</table>
<p><strong>{{ t.total }}:</strong> {{ order.total_amount|number_format(2, '.', ' ') }} Kč</p>
```
(`number_format` is Twig's built-in filter — already used the same way in
`templates/public/checkout/index.twig`.)

`templates/emails/password-reset.twig`:
```twig
<p>{{ t.intro }}</p>
<p><a href="{{ reset_url }}">{{ reset_url }}</a></p>
```

### Controller changes

**`src/Controllers/ContactController.php`** — add `use App\Services\I18n;`. Replace the
manual HTML/`htmlspecialchars()` block in `send()`:
```php
$i18n = new I18n('cs', __DIR__ . '/../../lang');
$html = $this->twig->fetch('emails/contact-notification.twig', [
    't' => [
        'name'    => $i18n->t('contact.name'),
        'email'   => $i18n->t('contact.email'),
        'message' => $i18n->t('contact.message'),
    ],
    'name'    => $name,
    'email'   => $email,
    'message' => $message,
]);
$subject = $i18n->t('email.contact.subject', ['name' => $name]);

Mailer::send($adminTo, $subject, $html, $email);
```

**`src/Controllers/PaymentController.php`** — add `use App\Services\I18n;`. Replace the
body of `notifyOrderPaid()` (after the existing `$contactEmail` empty-check) with:
```php
$i18n = new I18n('cs', __DIR__ . '/../../lang');
$html = $this->twig->fetch('emails/order-paid.twig', [
    't' => [
        'order'       => $i18n->t('order.title'),
        'customer'    => $i18n->t('email.order_paid.customer'),
        'email'       => $i18n->t('account.email'),
        'phone'       => $i18n->t('checkout.phone'),
        'pickup_date' => $i18n->t('checkout.pickup_date'),
        'notes'       => $i18n->t('checkout.notes'),
        'item'        => $i18n->t('order.product'),
        'qty'         => $i18n->t('order.qty'),
        'unit_price'  => $i18n->t('order.unit_price'),
        'total'       => $i18n->t('order.total'),
    ],
    'order' => $order,
]);
$subject = $i18n->t('email.order_paid.subject', ['number' => $order['order_number']]);

Mailer::send($contactEmail, $subject, $html);
```
(Drops the manual `$rows`-building loop and `htmlspecialchars()` calls — the Twig
template's `{% for %}` + auto-escaping replaces them.)

**`src/Controllers/AccountController.php`** — add `use App\Services\I18n;`. In
`forgotSubmit()`, grab the request's `I18n` and replace the manual HTML:
```php
public function forgotSubmit(Request $request, Response $response, array $args): Response
{
    $lang = $request->getAttribute('lang');
    /** @var I18n $i18n */
    $i18n  = $request->getAttribute('i18n');
    $body  = (array) $request->getParsedBody();
    $email = trim($body['email'] ?? '');

    $customer = $email !== '' ? CustomerModel::findByEmail($email) : null;
    if ($customer) {
        $token = bin2hex(random_bytes(32));
        CustomerModel::setResetToken((int) $customer['id'], $token, date('Y-m-d H:i:s', time() + 3600));

        $resetUrl = Seo::canonicalUrl($lang, '/reset-password') . '?token=' . $token;
        $html     = $this->twig->fetch('emails/password-reset.twig', [
            't'         => ['intro' => $i18n->t('email.password_reset.intro')],
            'reset_url' => $resetUrl,
        ]);
        Mailer::send($customer['email'], $i18n->t('email.password_reset.subject'), $html);
    }

    return $this->render($request, $response, 'public/forgot-password.twig', [
        'success' => true,
    ]);
}
```

## Translations

Reused existing keys (no change needed — already present in all five
`lang/{cs,en,ru,uk,sk}.json`): `contact.name`, `contact.email`, `contact.message`,
`order.title`, `account.email`, `checkout.phone`, `checkout.pickup_date`,
`checkout.notes`, `order.product`, `order.qty`, `order.unit_price`, `order.total`.

New keys added to all five files:
| Key | cs | en | ru | uk | sk |
|---|---|---|---|---|---|
| `email.contact.subject` (`{name}`) | Kontaktní formulář: {name} | Contact form: {name} | Контактная форма: {name} | Контактна форма: {name} | Kontaktný formulár: {name} |
| `email.order_paid.subject` (`{number}`) | Zaplacená objednávka {number} | Paid order {number} | Оплаченный заказ {number} | Оплачене замовлення {number} | Zaplatená objednávka {number} |
| `email.order_paid.customer` | Zákazník | Customer | Клиент | Клієнт | Zákazník |
| `email.password_reset.subject` | Obnovení hesla | Password reset | Восстановление пароля | Відновлення пароля | Obnovenie hesla |
| `email.password_reset.intro` | Pro nastavení nového hesla klikněte na odkaz níže: | Click the link below to set a new password: | Перейдите по ссылке ниже, чтобы задать новый пароль: | Перейдіть за посиланням нижче, щоб встановити новий пароль: | Pre nastavenie nového hesla kliknite na odkaz nižšie: |

## Error handling

No change to existing error-handling behavior in any of the three flows — this is a
rendering-mechanism swap only. `PaymentController::notifyOrderPaid()`'s existing
early-returns (missing order, missing `contact_email`) are unchanged; they just happen
before the new `$i18n`/`fetch()` calls instead of before the old string-building.

## Testing

No new model methods, so no new PHPUnit coverage — consistent with
`.claude/rules/unit-testing.md`'s "controllers are untested" convention, same as the
order-paid notification work already merged today.

Manual verification via `/start` + browser + `tmp/mail.log`:
1. Submit the contact form → `tmp/mail.log` shows Czech labels ("Jméno", "E-mail",
   "Zpráva") regardless of which site language (`/en/contact`, `/ru/contact`, ...) the
   form was submitted from.
2. Complete a dev-bypass checkout payment → `tmp/mail.log` shows the order-paid email
   with Czech labels and a correctly formatted items table/total, again regardless of
   checkout language.
3. Request a password reset from each of the 5 site languages (`/cs/forgot-password`,
   `/en/...`, `/ru/...`, `/uk/...`, `/sk/...`) → `tmp/mail.log` shows the subject and
   intro text in that specific language each time, and the reset link still works
   (`/reset-password?token=...` resolves via `CustomerModel::findByValidResetToken()`).
4. Run `php vendor/bin/phpunit` (with `-d memory_limit=512M`, per this environment) —
   confirm the full suite (256 tests) still passes.

## Out of scope

- An admin-editable "notification email language" setting — always Czech for the two
  admin notifications, per this round's decision.
- Any change to `Mailer::send()`'s signature or SMTP/dev-fallback behavior.
- Plain-text (non-HTML) email alternative parts.
- Styling/branding the emails beyond the existing plain-inline-HTML look.
