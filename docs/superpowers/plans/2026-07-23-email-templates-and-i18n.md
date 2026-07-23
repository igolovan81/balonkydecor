# Email Templates & Translation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the three hardcoded-English HTML-string emails (contact-form notification, order-paid notification, password-reset) with Twig templates whose labels come from the existing translation system — Czech for the two admin notifications, the visitor's current language for password-reset.

**Architecture:** Each controller already holds `$this->twig` (a `Slim\Views\Twig` instance) via `BaseController`. `Twig::fetch(template, data)` renders a template to a string without needing a `Response` — used to build each email body from a new `templates/emails/*.twig` file. Labels are resolved via `App\Services\I18n::t()` (Czech via a fresh `new I18n('cs', ...)` for admin emails, or the request's existing `I18n` attribute for password-reset) and passed into the template as a `t` array; Twig's auto-escaping replaces the current manual `htmlspecialchars()` calls.

**Tech Stack:** PHP 8, Slim 4, Twig 3 (`fetch()` string-rendering, built-in `number_format` filter) — no new dependencies.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-23-email-templates-and-i18n-design.md`
- New template directory: `templates/emails/` — files are standalone HTML fragments, do **not** extend `layout/base.twig`.
- Admin notifications (contact form, order paid) always render in Czech via `new I18n('cs', __DIR__ . '/../../lang')`. Password-reset uses `$request->getAttribute('i18n')` (the visitor's current language).
- Every new translation key goes into all five `lang/{cs,en,ru,uk,sk}.json` files — reuse an existing key instead of adding a new one wherever an exact match already exists (see each task's key table).
- No change to `Mailer::send()`'s signature or the dev-fallback-to-`tmp/mail.log` behavior.
- Controllers stay untested by convention (`.claude/rules/unit-testing.md`) — no new PHPUnit tests; verify manually via the running dev server + `tmp/mail.log`, and run the full suite after each task to confirm no regressions.
- Run tests with the bumped memory limit this environment needs: `php -d memory_limit=512M vendor/bin/phpunit --testdox`.
- Local dev server is expected running at `localhost:8080` (`php -S localhost:8080 -t www`) and MySQL via `docker compose up -d`.

---

### Task 1: Contact-form notification email

**Files:**
- Create: `templates/emails/contact-notification.twig`
- Modify: `src/Controllers/ContactController.php` (`send()` method + imports)
- Modify: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`

**Interfaces:**
- Consumes: `App\Services\I18n::__construct(string $lang, string $langDir)`, `I18n::t(string $key, array $params = []): string`; `Slim\Views\Twig::fetch(string $template, array $data = []): string` (available as `$this->twig` on any `BaseController` subclass); `App\Services\Mailer::send(string $to, string $subject, string $body, string $replyTo = ''): bool` (unchanged).
- Produces: nothing consumed by later tasks (each task is independent).

- [ ] **Step 1: Add the new translation key to all five language files**

All five files keep their keys alphabetically sorted for readability, but this is
cosmetic only — key order in a JSON object has no functional effect, and Step 2's
verification script sorts keys before comparing across files. In `lang/cs.json`,
insert the new key right after `"contact.title": "Kontakt",` and before
`"footer.follow_us"` (that's where it sorts alphabetically):

```json
  "email.contact.subject": "Kontaktní formulář: {name}",
```

Do the same insertion (alphabetically positioned) in the other four files with:

- `lang/en.json`: `"email.contact.subject": "Contact form: {name}",`
- `lang/ru.json`: `"email.contact.subject": "Контактная форма: {name}",`
- `lang/uk.json`: `"email.contact.subject": "Контактна форма: {name}",`
- `lang/sk.json`: `"email.contact.subject": "Kontaktný formulár: {name}",`

- [ ] **Step 2: Verify the JSON is still valid and all five files have matching keys**

Run:
```bash
for f in cs en ru uk sk; do php -r "json_decode(file_get_contents('lang/$f.json'), true) === null && exit(1); echo '$f OK\n';"; done
php -r '
$keys = null;
foreach (["cs","en","ru","uk","sk"] as $f) {
    $k = array_keys(json_decode(file_get_contents("lang/$f.json"), true));
    sort($k);
    if ($keys === null) { $keys = $k; echo "baseline: " . count($k) . " keys\n"; }
    elseif ($k !== $keys) { echo "$f MISMATCH\n"; exit(1); }
    else { echo "$f matches\n"; }
}
'
```
Expected: `cs OK` ... `sk OK`, then `baseline: N keys` followed by `en matches` / `ru matches` / `uk matches` / `sk matches`.

- [ ] **Step 3: Create the email template**

Create `templates/emails/contact-notification.twig`:

```twig
<p><strong>{{ t.name }}:</strong> {{ name }}</p>
<p><strong>{{ t.email }}:</strong> {{ email }}</p>
<p><strong>{{ t.message }}:</strong></p>
<p style="white-space: pre-line;">{{ message }}</p>
```

- [ ] **Step 4: Update `ContactController`**

Read `src/Controllers/ContactController.php`. Add the import:

```php
use App\Services\I18n;
```

to the existing `use` block (alongside `use App\Services\Mailer;`).

Replace this block inside `send()`:

```php
        $html = "<p><strong>Name:</strong> " . htmlspecialchars($name) . "</p>"
              . "<p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>"
              . "<p><strong>Message:</strong></p>"
              . "<p>" . nl2br(htmlspecialchars($message)) . "</p>";

        Mailer::send($adminTo, "Contact form: {$name}", $html, $email);
```

with:

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

- [ ] **Step 5: Syntax-check the changed PHP file**

Run: `php -l src/Controllers/ContactController.php`
Expected: `No syntax errors detected in src/Controllers/ContactController.php`

- [ ] **Step 6: Verify against the running dev server**

Ensure `docker compose up -d` and `php -S localhost:8080 -t www` are running (start them if not).

Run:
```bash
curl -s -X POST localhost:8080/en/contact -d "name=Verify+Task1&email=verify-task1@example.test&message=Hello%20from%20task%201%0ASecond%20line" -o /dev/null -w "contact POST (en): %{http_code}\n"
curl -s -X POST localhost:8080/ru/contact -d "name=Verify+Task1b&email=verify-task1b@example.test&message=Privet" -o /dev/null -w "contact POST (ru): %{http_code}\n"
tail -20 tmp/mail.log
```
Expected: both POSTs return `200`; the last two `tmp/mail.log` entries have `SUBJECT:Kontaktní formulář: Verify Task1` and `SUBJECT:Kontaktní formulář: Verify Task1b` (Czech subject regardless of `/en/` vs `/ru/` submission path), with body labels "Jméno:", "E-mail:", "Zpráva:" and the multi-line message preserved.

- [ ] **Step 7: Run the full test suite**

Run: `php -d memory_limit=512M vendor/bin/phpunit --testdox`
Expected: `OK (256 tests, ...)` — no regressions.

- [ ] **Step 8: Commit**

```bash
git add templates/emails/contact-notification.twig src/Controllers/ContactController.php lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json
git commit -m "feat: render contact-form notification email from a Twig template"
```

---

### Task 2: Order-paid notification email

**Files:**
- Create: `templates/emails/order-paid.twig`
- Modify: `src/Controllers/PaymentController.php` (`notifyOrderPaid()` method + imports)
- Modify: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`

**Interfaces:**
- Consumes: same `I18n`/`Twig::fetch()`/`Mailer::send()` interfaces as Task 1. Also `OrderModel::findByNumber()`'s existing return shape: order row with `order_number`, `customer_name`, `customer_email`, `customer_phone`, `pickup_date`, `notes`, `total_amount`, and `items` (each with `product_name_snapshot`, `subtype_name_snapshot`, `quantity`, `unit_price`) — unchanged from the order-paid-notification feature already merged.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Add the new translation keys to all five language files**

Insert these keys (alphabetically positioned, same pattern as Task 1) into all five files:

`lang/cs.json`:
```json
  "email.order_paid.customer": "Zákazník",
  "email.order_paid.subject": "Zaplacená objednávka {number}",
```

`lang/en.json`:
```json
  "email.order_paid.customer": "Customer",
  "email.order_paid.subject": "Paid order {number}",
```

`lang/ru.json`:
```json
  "email.order_paid.customer": "Клиент",
  "email.order_paid.subject": "Оплаченный заказ {number}",
```

`lang/uk.json`:
```json
  "email.order_paid.customer": "Клієнт",
  "email.order_paid.subject": "Оплачене замовлення {number}",
```

`lang/sk.json`:
```json
  "email.order_paid.customer": "Zákazník",
  "email.order_paid.subject": "Zaplatená objednávka {number}",
```

- [ ] **Step 2: Verify the JSON is still valid and all five files have matching keys**

Run the same verification script as Task 1 Step 2.
Expected: same "OK" / "matches" output pattern (key count now +2 from Task 1's baseline).

- [ ] **Step 3: Create the email template**

Create `templates/emails/order-paid.twig`:

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

- [ ] **Step 4: Update `PaymentController`**

Read `src/Controllers/PaymentController.php`. Add the import:

```php
use App\Services\I18n;
```

alongside the existing `use App\Services\Mailer;`.

Replace the body of `notifyOrderPaid()` from the `$rows = '';` line through the `Mailer::send(...)` call — i.e. replace:

```php
        $rows = '';
        foreach ($order['items'] as $item) {
            $subtype = $item['subtype_name_snapshot']
                ? ' — ' . htmlspecialchars($item['subtype_name_snapshot'])
                : '';
            $rows .= '<tr>'
                . '<td>' . htmlspecialchars($item['product_name_snapshot']) . $subtype . '</td>'
                . '<td>' . (int) $item['quantity'] . '</td>'
                . '<td>' . htmlspecialchars((string) $item['unit_price']) . ' Kč</td>'
                . '</tr>';
        }

        $html = '<p><strong>Order:</strong> ' . htmlspecialchars($order['order_number']) . '</p>'
              . '<p><strong>Customer:</strong> ' . htmlspecialchars($order['customer_name']) . '</p>'
              . '<p><strong>Email:</strong> ' . htmlspecialchars($order['customer_email']) . '</p>'
              . '<p><strong>Phone:</strong> ' . htmlspecialchars($order['customer_phone']) . '</p>'
              . ($order['pickup_date'] ? '<p><strong>Pickup date:</strong> ' . htmlspecialchars($order['pickup_date']) . '</p>' : '')
              . ($order['notes'] ? '<p><strong>Notes:</strong> ' . nl2br(htmlspecialchars($order['notes'])) . '</p>' : '')
              . '<table border="1" cellpadding="6" cellspacing="0"><thead><tr><th>Item</th><th>Qty</th><th>Unit price</th></tr></thead><tbody>'
              . $rows
              . '</tbody></table>'
              . '<p><strong>Total:</strong> ' . htmlspecialchars((string) $order['total_amount']) . ' Kč</p>';

        Mailer::send($contactEmail, "Paid order {$order['order_number']}", $html);
```

with:

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

- [ ] **Step 5: Syntax-check the changed PHP file**

Run: `php -l src/Controllers/PaymentController.php`
Expected: `No syntax errors detected in src/Controllers/PaymentController.php`

- [ ] **Step 6: Verify against the running dev server**

Confirm `gopay_go_id` is still empty (dev bypass) — reuse the check from the earlier session:
```bash
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e "SELECT value FROM settings WHERE \`key\`='gopay_go_id';"
```

Drive a full checkout + payment through curl with a fresh cookie jar:
```bash
JAR=$(mktemp)
SKU=$(docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -N -e "SELECT sku FROM products WHERE is_active=1 LIMIT 1;" | tr -d '\r')
curl -s -c "$JAR" -b "$JAR" -X POST "localhost:8080/ru/cart/add" -d "sku=${SKU}&qty=1" -o /dev/null -w "cart/add: %{http_code}\n"
curl -s -c "$JAR" -b "$JAR" -X POST "localhost:8080/ru/checkout" \
  -d "customer_name=Verify+Task2&customer_email=verify-task2@example.test&customer_phone=987654321&pickup_date=&notes=Task2+note" \
  -o /dev/null -w "checkout POST: %{http_code}\n"
curl -s -c "$JAR" -b "$JAR" -X POST "localhost:8080/ru/payment/gopay" -o /dev/null -w "pay: %{http_code}\n"
tail -15 tmp/mail.log
```
Expected: the checkout is submitted from the `/ru/` (Russian) site path, but the last `tmp/mail.log` entry still shows `SUBJECT:Zaplacená objednávka BD-...` (Czech subject) with body labels "Objednávka:", "Zákazník:", "E-mail:", "Telefon:", "Poznámka k objednávce:", table headers "Produkt"/"Počet"/"Cena/ks", and "Celkem:" for the total — all Czech, regardless of the `/ru/` checkout path.

- [ ] **Step 7: Run the full test suite**

Run: `php -d memory_limit=512M vendor/bin/phpunit --testdox`
Expected: `OK (256 tests, ...)` — no regressions.

- [ ] **Step 8: Commit**

```bash
git add templates/emails/order-paid.twig src/Controllers/PaymentController.php lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json
git commit -m "feat: render order-paid notification email from a Twig template"
```

---

### Task 3: Password-reset email

**Files:**
- Create: `templates/emails/password-reset.twig`
- Modify: `src/Controllers/AccountController.php` (`forgotSubmit()` method + imports)
- Modify: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`

**Interfaces:**
- Consumes: same `I18n`/`Twig::fetch()`/`Mailer::send()` interfaces as Tasks 1–2, plus the request's existing `i18n` attribute (`$request->getAttribute('i18n')`, an `I18n` instance already carrying the visitor's current language, set by `LangMiddleware`).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Add the new translation keys to all five language files**

Insert these keys (alphabetically positioned) into all five files:

`lang/cs.json`:
```json
  "email.password_reset.intro": "Pro nastavení nového hesla klikněte na odkaz níže:",
  "email.password_reset.subject": "Obnovení hesla",
```

`lang/en.json`:
```json
  "email.password_reset.intro": "Click the link below to set a new password:",
  "email.password_reset.subject": "Password reset",
```

`lang/ru.json`:
```json
  "email.password_reset.intro": "Перейдите по ссылке ниже, чтобы задать новый пароль:",
  "email.password_reset.subject": "Восстановление пароля",
```

`lang/uk.json`:
```json
  "email.password_reset.intro": "Перейдіть за посиланням нижче, щоб встановити новий пароль:",
  "email.password_reset.subject": "Відновлення пароля",
```

`lang/sk.json`:
```json
  "email.password_reset.intro": "Pre nastavenie nového hesla kliknite na odkaz nižšie:",
  "email.password_reset.subject": "Obnovenie hesla",
```

- [ ] **Step 2: Verify the JSON is still valid and all five files have matching keys**

Run the same verification script as Task 1 Step 2.
Expected: same "OK" / "matches" output pattern.

- [ ] **Step 3: Create the email template**

Create `templates/emails/password-reset.twig`:

```twig
<p>{{ t.intro }}</p>
<p><a href="{{ reset_url }}">{{ reset_url }}</a></p>
```

- [ ] **Step 4: Update `AccountController`**

Read `src/Controllers/AccountController.php`. Add the import:

```php
use App\Services\I18n;
```

alongside the existing `use App\Services\Mailer;` / `use App\Services\Seo;`.

Replace the `forgotSubmit()` method body:

```php
    public function forgotSubmit(Request $request, Response $response, array $args): Response
    {
        $lang  = $request->getAttribute('lang');
        $body  = (array) $request->getParsedBody();
        $email = trim($body['email'] ?? '');

        $customer = $email !== '' ? CustomerModel::findByEmail($email) : null;
        if ($customer) {
            $token = bin2hex(random_bytes(32));
            CustomerModel::setResetToken((int) $customer['id'], $token, date('Y-m-d H:i:s', time() + 3600));

            $resetUrl = Seo::canonicalUrl($lang, '/reset-password') . '?token=' . $token;
            $html     = '<p>' . htmlspecialchars($customer['email']) . '</p>'
                      . '<p><a href="' . htmlspecialchars($resetUrl) . '">' . htmlspecialchars($resetUrl) . '</a></p>';
            Mailer::send($customer['email'], 'Password reset', $html);
        }

        return $this->render($request, $response, 'public/forgot-password.twig', [
            'success' => true,
        ]);
    }
```

with:

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

Note this drops the old behavior of putting the customer's own email address as the
first line of the body (it added no information the recipient didn't already know —
they're reading it in their own inbox); the new template opens directly with the
translated instruction line instead.

- [ ] **Step 5: Syntax-check the changed PHP file**

Run: `php -l src/Controllers/AccountController.php`
Expected: `No syntax errors detected in src/Controllers/AccountController.php`

- [ ] **Step 6: Verify against the running dev server, across all 5 languages**

```bash
for lang in cs en ru uk sk; do
  EMAIL="reset-verify-${lang}-$(date +%s)@example.test"
  JAR=$(mktemp)
  curl -s -c "$JAR" -b "$JAR" -X POST "localhost:8080/${lang}/register" \
    -d "email=${EMAIL}&password=Password123&password_confirm=Password123" \
    -o /dev/null -w "register (${lang}): %{http_code}\n"
  curl -s -c "$JAR" -b "$JAR" -X POST "localhost:8080/${lang}/logout" -o /dev/null -w "logout (${lang}): %{http_code}\n"
  curl -s -X POST "localhost:8080/${lang}/forgot-password" -d "email=${EMAIL}" \
    -o /dev/null -w "forgot-password (${lang}): %{http_code}\n"
done
tail -50 tmp/mail.log
```
Expected: five `TO:reset-verify-<lang>-...` entries in `tmp/mail.log`, each with the
subject and intro line in that specific language (e.g. the `cs` one reads "Obnovení
hesla" / "Pro nastavení nového hesla klikněte na odkaz níže:", the `ru` one reads
"Восстановление пароля" / "Перейдите по ссылке ниже..."), and each containing a
`/reset-password?token=...` link.

Spot-check one token still works end-to-end:
```bash
TOKEN=$(grep -o 'token=[a-f0-9]*' tmp/mail.log | tail -1 | cut -d= -f2)
curl -s "localhost:8080/en/reset-password?token=${TOKEN}" -o /dev/null -w "reset form: %{http_code}\n"
```
Expected: `200` (the token still resolves via `CustomerModel::findByValidResetToken()`).

- [ ] **Step 7: Run the full test suite**

Run: `php -d memory_limit=512M vendor/bin/phpunit --testdox`
Expected: `OK (256 tests, ...)` — no regressions.

- [ ] **Step 8: Commit**

```bash
git add templates/emails/password-reset.twig src/Controllers/AccountController.php lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json
git commit -m "feat: render password-reset email from a Twig template, translated to the visitor's language"
```
