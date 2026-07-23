# Order Status Change Customer Notification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Email the customer, in their preferred language, whenever an admin manually changes an order's status from `/admin/orders/{number}`.

**Architecture:** A new `customers.notification_lang` column (mirroring `users.lang`) stores each customer's preferred email language, set at registration and editable on the account page. `Admin/OrderController::updateStatus()` resolves that language (or Czech for guest orders), renders a new `templates/emails/order-status-changed.twig` via a `fetchEmail()` helper added to `AdminBaseController` (mirroring the one already on `BaseController`), and emails `order.customer_email`.

**Tech Stack:** PHP 8, Slim 4, Twig 3, PHPUnit 11 (real Docker MySQL for model tests) — no new dependencies.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-23-order-status-change-customer-notification-design.md`
- Only admin-driven status changes trigger this (via `Admin/OrderController::updateStatus()`) — never the automatic `pending → paid` transition from checkout/GoPay.
- Guest orders (no `customer_id`) default to Czech — there's no other language signal for them.
- Reuse existing translation keys (`order.title`, `order.status.*`) instead of duplicating them.
- Dedup guard: only update + notify when the new status actually differs from the order's current status (prevents a no-op re-save from emailing).
- Model tests use real Docker MySQL, `uniqid()`-suffixed fixtures, exact-value assertions — per `.claude/rules/unit-testing.md`. Controllers stay untested by the same convention; verify those manually.
- Run `php -d memory_limit=512M vendor/bin/phpunit --testdox` before each commit (this environment needs the raised memory limit for the full suite).
- Local dev server (`php -S localhost:8080 -t www`) and MySQL (`docker compose up -d`) are assumed running. Local migrations apply via `curl "localhost:8080/migrate.php?token=<token>"` — the token is `config/settings.php`'s `migrate_token` value.

---

### Task 1: `notification_lang` column + `CustomerModel` support

**Files:**
- Create: `database/migrations/V027__customer_notification_lang.sql`
- Modify: `src/Models/CustomerModel.php` (`create()`, `updateProfile()`)
- Test: `tests/Unit/Models/CustomerModelTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `CustomerModel::create(string $email, string $passwordHash, string $notificationLang = 'cs'): int` and `CustomerModel::updateProfile(int $id, string $name, string $phone, string $notificationLang): void` — Task 2 (`AccountController`) and Task 3 (`Admin/OrderController`, indirectly via `CustomerModel::findById()`) depend on the `notification_lang` column being present and returned by `findById()`/`findByEmail()` (both already `SELECT *`, no signature change needed there).

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/Models/CustomerModelTest.php`, add two new test methods (after `test_findById_returns_null_for_unknown_id`):

```php
    public function test_create_defaults_notification_lang_to_cs(): void
    {
        $email = 'lang-default-' . uniqid() . '@example.com';
        $id    = CustomerModel::create($email, self::$hash);

        $customer = CustomerModel::findById($id);
        $this->assertSame('cs', $customer['notification_lang']);
    }

    public function test_create_accepts_explicit_notification_lang(): void
    {
        $email = 'lang-explicit-' . uniqid() . '@example.com';
        $id    = CustomerModel::create($email, self::$hash, 'ru');

        $customer = CustomerModel::findById($id);
        $this->assertSame('ru', $customer['notification_lang']);
    }
```

Replace the existing `test_updateProfile_updates_name_and_phone` test with:

```php
    public function test_updateProfile_updates_name_phone_and_notification_lang(): void
    {
        CustomerModel::updateProfile(self::$customerId, 'Test Name', '+420111222333', 'sk');

        $customer = CustomerModel::findById(self::$customerId);
        $this->assertSame('Test Name', $customer['name']);
        $this->assertSame('+420111222333', $customer['phone']);
        $this->assertSame('sk', $customer['notification_lang']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php -d memory_limit=512M vendor/bin/phpunit tests/Unit/Models/CustomerModelTest.php --testdox`
Expected: `test_create_defaults_notification_lang_to_cs` and
`test_create_accepts_explicit_notification_lang` FAIL with an SQL error (unknown
column `notification_lang`), and `test_updateProfile_updates_name_phone_and_notification_lang`
FAILS with `ArgumentCountError: Too few arguments to function CustomerModel::updateProfile()`.

- [ ] **Step 3: Create the migration**

Create `database/migrations/V027__customer_notification_lang.sql`:

```sql
ALTER TABLE `customers`
  ADD COLUMN `notification_lang` VARCHAR(5) NOT NULL DEFAULT 'cs' AFTER `phone`;
```

- [ ] **Step 4: Apply the migration locally**

Run:
```bash
TOKEN=$(php -r "echo (require 'config/settings.php')['migrate_token'];")
curl -s "localhost:8080/migrate.php?token=${TOKEN}" | python3 -m json.tool
```
Expected: `{"applied": ["V027__customer_notification_lang"], "count": 1}`.

- [ ] **Step 5: Implement `CustomerModel` changes**

In `src/Models/CustomerModel.php`, replace:

```php
    public static function create(string $email, string $passwordHash): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO customers (email, password_hash) VALUES (?, ?)');
        $stmt->execute([$email, $passwordHash]);
        return (int) $pdo->lastInsertId();
    }
```

with:

```php
    public static function create(string $email, string $passwordHash, string $notificationLang = 'cs'): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO customers (email, password_hash, notification_lang) VALUES (?, ?, ?)');
        $stmt->execute([$email, $passwordHash, $notificationLang]);
        return (int) $pdo->lastInsertId();
    }
```

Replace:

```php
    public static function updateProfile(int $id, string $name, string $phone): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE customers SET name = ?, phone = ? WHERE id = ?');
        $stmt->execute([$name, $phone, $id]);
    }
```

with:

```php
    public static function updateProfile(int $id, string $name, string $phone, string $notificationLang): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE customers SET name = ?, phone = ?, notification_lang = ? WHERE id = ?');
        $stmt->execute([$name, $phone, $notificationLang, $id]);
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php -d memory_limit=512M vendor/bin/phpunit tests/Unit/Models/CustomerModelTest.php --testdox`
Expected: all tests in this file PASS.

- [ ] **Step 7: Run the full test suite**

Run: `php -d memory_limit=512M vendor/bin/phpunit --testdox`
Expected: full suite green (this will currently fail to compile/run until Task 2 also
updates every other caller of `updateProfile()` — there are none besides
`AccountController::update()`, which Task 2 fixes; if Task 1 is committed alone, grep
first to confirm no other caller exists):
```bash
grep -rn "CustomerModel::updateProfile" src/
```
Expected: only `src/Controllers/AccountController.php` — Task 2 updates that call site
next, so it's safe to land Task 1 first even though the signature changed, since PHP
only errors at the call site when it's actually invoked (not at parse time), and no
test exercises `AccountController::update()` directly (controllers are untested by
convention).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/V027__customer_notification_lang.sql src/Models/CustomerModel.php tests/Unit/Models/CustomerModelTest.php
git commit -m "feat: add customer notification_lang preference"
```

---

### Task 2: Registration default + editable account preference

**Files:**
- Modify: `src/Controllers/AccountController.php` (`registerSubmit()`, `update()`)
- Modify: `templates/public/account/customer-info.twig`
- Modify: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`

**Interfaces:**
- Consumes: `CustomerModel::create(string $email, string $passwordHash, string $notificationLang = 'cs'): int`, `CustomerModel::updateProfile(int $id, string $name, string $phone, string $notificationLang): void` (Task 1), `Seo::LANGUAGES` (existing public constant `['cs','sk','en','uk','ru']` in `src/Services/Seo.php`).
- Produces: nothing consumed by Task 3.

- [ ] **Step 1: Add the translation key to all five language files**

Insert into all five files (position doesn't matter functionally — Step 2 sorts before
comparing — but keep it near the other `account.*` keys for readability, e.g. right
after `account.name`):

- `lang/cs.json`: `"account.notification_lang": "Preferovaný jazyk e-mailových upozornění",`
- `lang/en.json`: `"account.notification_lang": "Preferred language for email notifications",`
- `lang/ru.json`: `"account.notification_lang": "Предпочитаемый язык уведомлений по эл. почте",`
- `lang/uk.json`: `"account.notification_lang": "Бажана мова сповіщень електронною поштою",`
- `lang/sk.json`: `"account.notification_lang": "Preferovaný jazyk e-mailových upozornení",`

- [ ] **Step 2: Verify JSON validity and matching key sets**

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
Expected: all `OK`, then all `matches`.

- [ ] **Step 3: Update `registerSubmit()`**

In `src/Controllers/AccountController.php`, change:

```php
        $customerId = CustomerModel::create($email, password_hash($password, PASSWORD_BCRYPT));
```

to:

```php
        $customerId = CustomerModel::create($email, password_hash($password, PASSWORD_BCRYPT), $lang);
```

(`$lang` is already in scope from the top of `registerSubmit()` — the route's `lang`
attribute, always one of the 5 supported codes.)

- [ ] **Step 4: Update `update()`**

In `src/Controllers/AccountController.php`, add the import:

```php
use App\Services\Seo;
```

(already present — confirm, don't duplicate). Replace the body of `update()` from the
`$body = (array) $request->getParsedBody();` line through the final
`CustomerModel::updateProfile(...)` call:

```php
        $body            = (array) $request->getParsedBody();
        $name            = trim($body['name'] ?? '');
        $phone           = trim($body['phone'] ?? '');
        $email           = trim($body['email'] ?? '');
        $currentPassword = $body['current_password'] ?? '';
        $notificationLang = $body['notification_lang'] ?? '';
        if (!in_array($notificationLang, Seo::LANGUAGES, true)) {
            $notificationLang = $customer['notification_lang'] ?? 'cs';
        }

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->render($request, $response, 'public/account/customer-info.twig', [
                'account' => array_merge($customer, ['name' => $name, 'phone' => $phone, 'email' => $email, 'notification_lang' => $notificationLang]),
                'error'   => 'account.error_invalid',
            ]);
        }

        if ($email !== $customer['email']) {
            if ($currentPassword === '' || !password_verify($currentPassword, $customer['password_hash'])) {
                return $this->render($request, $response, 'public/account/customer-info.twig', [
                    'account' => array_merge($customer, ['name' => $name, 'phone' => $phone, 'email' => $email, 'notification_lang' => $notificationLang]),
                    'error'   => 'account.error_current_password',
                ]);
            }

            $existing = CustomerModel::findByEmail($email);
            if ($existing && (int) $existing['id'] !== (int) $customer['id']) {
                return $this->render($request, $response, 'public/account/customer-info.twig', [
                    'account' => array_merge($customer, ['name' => $name, 'phone' => $phone, 'email' => $email, 'notification_lang' => $notificationLang]),
                    'error'   => 'account.error_email_taken',
                ]);
            }

            CustomerModel::updateEmail((int) $customer['id'], $email);
            $_SESSION['customer']['email'] = $email;
        }

        CustomerModel::updateProfile((int) $customer['id'], $name, $phone, $notificationLang);
        $this->flash('success', 'account.update_success');
        return $response->withHeader('Location', "/{$lang}/account")->withStatus(302);
```

- [ ] **Step 5: Update the template**

In `templates/public/account/customer-info.twig`, insert a new field between the phone
field and the current-password field:

```twig
            <div class="form-group">
                <label for="notification_lang">{{ t('account.notification_lang') }}</label>
                <select id="notification_lang" name="notification_lang">
                    {% for code, label in {'cs': 'CZ', 'sk': 'SK', 'en': 'EN', 'uk': 'UA', 'ru': 'RU'} %}
                    <option value="{{ code }}" {% if (account.notification_lang ?? 'cs') == code %}selected{% endif %}>{{ label }}</option>
                    {% endfor %}
                </select>
            </div>
```

- [ ] **Step 6: Syntax-check the changed PHP file**

Run: `php -l src/Controllers/AccountController.php`
Expected: `No syntax errors detected in src/Controllers/AccountController.php`

- [ ] **Step 7: Verify against the running dev server**

```bash
EMAIL="notiflang-$(date +%s)@example.test"
JAR=$(mktemp)
curl -s -c "$JAR" -b "$JAR" -X POST "localhost:8080/ru/register" \
  -d "email=${EMAIL}&password=Password123&password_confirm=Password123" \
  -o /dev/null -w "register (ru): %{http_code}\n"
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -N \
  -e "SELECT notification_lang FROM customers WHERE email='${EMAIL}';"
```
Expected: register returns `302`; the SQL query prints `ru`.

Then verify the account-page edit path:
```bash
curl -s -c "$JAR" -b "$JAR" -X POST "localhost:8080/ru/account" \
  -d "name=Lang+Test&phone=&email=${EMAIL}&notification_lang=sk" \
  -o /dev/null -w "account update: %{http_code}\n"
curl -s -c "$JAR" -b "$JAR" "localhost:8080/ru/account" -o /tmp/account_page.html -w "account page: %{http_code}\n"
grep -A1 'value="sk"' /tmp/account_page.html
```
Expected: account update returns `302`; the account page's `sk` `<option>` shows
`selected`.

- [ ] **Step 8: Run the full test suite**

Run: `php -d memory_limit=512M vendor/bin/phpunit --testdox`
Expected: `OK` — full suite green (this is also where Task 1's dangling-signature
concern from its Step 7 resolves, since `AccountController::update()` now passes 4
args).

- [ ] **Step 9: Commit**

```bash
git add src/Controllers/AccountController.php templates/public/account/customer-info.twig lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json
git commit -m "feat: let customers set a preferred language for email notifications"
```

---

### Task 3: Admin status-change notification email

**Files:**
- Create: `templates/emails/order-status-changed.twig`
- Modify: `src/Controllers/Admin/AdminBaseController.php` (add `fetchEmail()`/`ensureI18nExtension()`)
- Modify: `src/Controllers/Admin/OrderController.php` (`updateStatus()` + new `notifyStatusChanged()`)
- Modify: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`

**Interfaces:**
- Consumes: `CustomerModel::findById(int $id): ?array` (now returns `notification_lang`, per Task 1), `OrderModel::findByNumber(string $number): ?array` (existing, returns the order row including `customer_id`, `customer_email`, `order_number`, `status`), `App\Services\I18n::__construct`/`t()`, `App\Services\Seo::canonicalUrl(string $lang, string $path): string`, `App\Services\Mailer::send()`.
- Produces: `AdminBaseController::fetchEmail(Request, string, array): string` — not consumed elsewhere in this plan, but available to any future admin-side email.

- [ ] **Step 1: Add the new translation keys to all five language files**

- `lang/cs.json`:
```json
  "email.order_status_changed.intro": "Stav vaší objednávky byl aktualizován:",
  "email.order_status_changed.status": "Stav",
  "email.order_status_changed.subject": "Změna stavu objednávky {number}",
```
- `lang/en.json`:
```json
  "email.order_status_changed.intro": "Your order status has been updated:",
  "email.order_status_changed.status": "Status",
  "email.order_status_changed.subject": "Order {number} status update",
```
- `lang/ru.json`:
```json
  "email.order_status_changed.intro": "Статус вашего заказа обновлён:",
  "email.order_status_changed.status": "Статус",
  "email.order_status_changed.subject": "Изменение статуса заказа {number}",
```
- `lang/uk.json`:
```json
  "email.order_status_changed.intro": "Статус вашого замовлення оновлено:",
  "email.order_status_changed.status": "Статус",
  "email.order_status_changed.subject": "Зміна статусу замовлення {number}",
```
- `lang/sk.json`:
```json
  "email.order_status_changed.intro": "Stav vašej objednávky bol aktualizovaný:",
  "email.order_status_changed.status": "Stav",
  "email.order_status_changed.subject": "Zmena stavu objednávky {number}",
```

- [ ] **Step 2: Verify JSON validity and matching key sets**

Run the same verification script as Task 2 Step 2.
Expected: same "OK"/"matches" pattern.

- [ ] **Step 3: Create the email template**

Create `templates/emails/order-status-changed.twig`:

```twig
<p>{{ t.intro }}</p>
<p><strong>{{ t.order }}:</strong> {{ order.order_number }}</p>
<p><strong>{{ t.status }}:</strong> {{ status_label }}</p>
<p><a href="{{ order_url }}">{{ order_url }}</a></p>
```

- [ ] **Step 4: Add `fetchEmail()`/`ensureI18nExtension()` to `AdminBaseController`**

Read `src/Controllers/Admin/AdminBaseController.php`. Replace:

```php
    protected function renderAdmin(Request $request, Response $response, string $template, array $data = []): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $flash = $this->getFlash();
        $i18n  = $request->getAttribute('admin_i18n');
        $env   = $this->twig->getEnvironment();
        if ($i18n && !$env->hasExtension(\App\Twig\I18nExtension::class)) {
            $env->addExtension(new \App\Twig\I18nExtension($i18n));
        }
        $userId      = (int) ($_SESSION['admin_user']['id'] ?? 0);
```

with:

```php
    protected function renderAdmin(Request $request, Response $response, string $template, array $data = []): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $flash = $this->getFlash();
        $this->ensureI18nExtension($request);
        $userId      = (int) ($_SESSION['admin_user']['id'] ?? 0);
```

Then add these two new methods right after `renderAdmin()` (before `flash()`):

```php
    protected function fetchEmail(Request $request, string $template, array $data = []): string
    {
        $this->ensureI18nExtension($request);
        return $this->twig->fetch($template, $data);
    }

    private function ensureI18nExtension(Request $request): void
    {
        $env = $this->twig->getEnvironment();
        if (!$env->hasExtension(\App\Twig\I18nExtension::class)) {
            $i18n = $request->getAttribute('admin_i18n');
            if ($i18n) {
                $env->addExtension(new \App\Twig\I18nExtension($i18n));
            }
        }
    }
```

- [ ] **Step 5: Update `Admin/OrderController`**

Read `src/Controllers/Admin/OrderController.php`. Add imports:

```php
use App\Models\CustomerModel;
use App\Models\OrderModel;
use App\Services\I18n;
use App\Services\Mailer;
use App\Services\Seo;
```

Replace `updateStatus()`:

```php
    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $status = $body['status'] ?? '';
        if (in_array($status, self::STATUSES, true)) {
            OrderModel::updateStatus($args['number'], $status);
            $this->flash('success', 'orders.flash.status_changed');
        }
        return $this->redirect($response, '/admin/orders/' . $args['number']);
    }
```

with:

```php
    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $status = $body['status'] ?? '';
        if (in_array($status, self::STATUSES, true)) {
            $order = OrderModel::findByNumber($args['number']);
            if ($order && $order['status'] !== $status) {
                OrderModel::updateStatus($args['number'], $status);
                $this->notifyStatusChanged($request, $order, $status);
            }
            $this->flash('success', 'orders.flash.status_changed');
        }
        return $this->redirect($response, '/admin/orders/' . $args['number']);
    }

    private function notifyStatusChanged(Request $request, array $order, string $newStatus): void
    {
        $notificationLang = 'cs';
        if (!empty($order['customer_id'])) {
            $customer = CustomerModel::findById((int) $order['customer_id']);
            if ($customer && !empty($customer['notification_lang'])) {
                $notificationLang = $customer['notification_lang'];
            }
        }

        $i18n = new I18n($notificationLang, __DIR__ . '/../../../lang');
        $html = $this->fetchEmail($request, 'emails/order-status-changed.twig', [
            't' => [
                'intro'  => $i18n->t('email.order_status_changed.intro'),
                'order'  => $i18n->t('order.title'),
                'status' => $i18n->t('email.order_status_changed.status'),
            ],
            'order'        => $order,
            'status_label' => $i18n->t('order.status.' . $newStatus),
            'order_url'    => Seo::canonicalUrl($notificationLang, '/order/' . $order['order_number']),
        ]);
        $subject = $i18n->t('email.order_status_changed.subject', ['number' => $order['order_number']]);

        Mailer::send($order['customer_email'], $subject, $html);
    }
```

- [ ] **Step 6: Syntax-check both changed PHP files**

Run:
```bash
php -l src/Controllers/Admin/AdminBaseController.php
php -l src/Controllers/Admin/OrderController.php
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 7: Verify against the running dev server**

There's no known admin password in the local dev DB for a live login test. Temporarily
set one on the existing local admin user (local DB only — never do this against prod):

```bash
HASH=$(php -r "echo password_hash('TempTest123', PASSWORD_BCRYPT);")
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor \
  -e "UPDATE users SET password_hash='${HASH}' WHERE email='topbalonky2030@gmail.com';"
```

Place an order as a customer with a known `notification_lang`, then change its status
as admin:

```bash
CUSTJAR=$(mktemp)
EMAIL="statuscust-$(date +%s)@example.test"
curl -s -c "$CUSTJAR" -b "$CUSTJAR" -X POST "localhost:8080/sk/register" \
  -d "email=${EMAIL}&password=Password123&password_confirm=Password123" -o /dev/null -w "register: %{http_code}\n"
SKU=$(docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -N -e "SELECT sku FROM products WHERE is_active=1 LIMIT 1;" | tr -d '\r')
curl -s -c "$CUSTJAR" -b "$CUSTJAR" -X POST "localhost:8080/sk/cart/add" -d "sku=${SKU}&qty=1" -o /dev/null -w "cart/add: %{http_code}\n"
curl -s -c "$CUSTJAR" -b "$CUSTJAR" -X POST "localhost:8080/sk/checkout" \
  -d "customer_name=Status+Test&customer_email=${EMAIL}&customer_phone=111222333&pickup_date=&notes=" \
  -o /dev/null -w "checkout: %{http_code}\n"
ORDER=$(docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -N \
  -e "SELECT order_number FROM orders WHERE customer_email='${EMAIL}' ORDER BY id DESC LIMIT 1;" | tr -d '\r')
echo "ORDER=$ORDER"

ADMINJAR=$(mktemp)
curl -s -c "$ADMINJAR" -b "$ADMINJAR" -X POST "localhost:8080/admin/login" \
  -d "email=topbalonky2030@gmail.com&password=TempTest123" -o /dev/null -w "admin login: %{http_code}\n"
curl -s -c "$ADMINJAR" -b "$ADMINJAR" -X POST "localhost:8080/admin/orders/${ORDER}/status" \
  -d "status=ready" -o /dev/null -w "status update: %{http_code}\n"
tail -15 tmp/mail.log
```
Expected: the last `tmp/mail.log` entry is addressed to `${EMAIL}`, subject
`"Zmena stavu objednávky ${ORDER}"` (Slovak), body reads "Stav vašej objednávky bol
aktualizovaný:" / "Objednávka:" / "Stav: Pripravené na vyzdvihnutie" (or whatever the
Slovak `order.status.ready` value is) with a working `/sk/order/${ORDER}` link.

Then verify the dedup guard — re-save the same status:
```bash
curl -s -c "$ADMINJAR" -b "$ADMINJAR" -X POST "localhost:8080/admin/orders/${ORDER}/status" \
  -d "status=ready" -o /dev/null -w "status re-save: %{http_code}\n"
tail -5 tmp/mail.log
```
Expected: no new log entry (same tail content as before this re-save).

Then verify guest orders default to Czech — repeat the checkout without registering
(fresh cookie jar, skip the `/register` call), change its status, confirm the log entry
uses Czech text.

Finally, revert the temporary local admin password change isn't necessary (it's
local-dev-only and harmless to leave), but note it in the commit message context if
asked — no action needed here.

- [ ] **Step 8: Run the full test suite**

Run: `php -d memory_limit=512M vendor/bin/phpunit --testdox`
Expected: `OK` — full suite green.

- [ ] **Step 9: Commit**

```bash
git add templates/emails/order-status-changed.twig src/Controllers/Admin/AdminBaseController.php src/Controllers/Admin/OrderController.php lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json
git commit -m "feat: email customer when admin changes their order status"
```
