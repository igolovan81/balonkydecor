# Account: Customer Info / Orders / Change Password Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the existing basic customer account (register/login/logout/reset — already live) with a "My Account" sidebar offering three pages: edit Customer Info (name/phone/email), view Orders placed while logged in, and Change Password.

**Architecture:** Two schema additions (`customers.name`/`phone`, `orders.customer_id`), two `CustomerModel` methods and one `OrderModel` method plus a 4th optional param on `OrderModel::create()`, five new/changed `AccountController` methods sharing a new private `requireLogin()` helper, three new templates under `templates/public/account/` sharing a sidebar partial, and reuse of the existing `.shop-layout` two-column CSS (no new CSS).

**Tech Stack:** Slim 4, Twig 3, PDO/MySQL 8, PHPUnit 11 (Docker MySQL for model tests). No new dependencies.

## Global Constraints

- Orders link to a customer **only going forward** — via a nullable `orders.customer_id` set at checkout time when the buyer is logged in. No retroactive matching of past guest orders by email (spec: matching by unverified email would let anyone see someone else's order history by registering with their address).
- `customers.name` is a single field, not split first/last — matches how `orders.customer_name` already works elsewhere.
- Changing the account email requires re-entering the current password; name/phone save without that extra step. A failed email-change validation must not silently save name/phone either (atomic per submission).
- No pagination on the orders list in this pass.
- No new CSS — reuse `.shop-layout`/`.shop-sidebar`/`.cat-filter-list`/`.cat-filter`/`.active` (from `/shop`) and `.cart-table`/`.order-status--{status}` (from the order-status page) and `.contact-form`/`.form-group`/`.btn.btn-primary` (from the existing account/register/login pages).
- Every visible string goes through `t('key')`, present in all five `lang/{cs,en,ru,uk,sk}.json` files with matching key sets.
- All public links are language-prefixed: `/{lang}/...`.
- Prepared statements with bound parameters only, no string interpolation of request data into SQL.
- New migration file `V0NN__snake_case_description.sql`, never edit an applied migration. Column-add and constraint-add go in **separate** `ALTER TABLE` statements even for the same table — matches the existing convention in `V014__category_product_audit.sql`.
- `noindex,nofollow` stays on all account pages (existing convention, unchanged).
- Controllers stay untested per project convention — verified by hand via `/start` + curl, not a PHPUnit HTTP harness. Models are TDD'd against the real Docker MySQL DB.
- Run `php vendor/bin/phpunit` (full suite) and confirm 0 failures before each commit.

---

### Task 1: Schema migration

**Files:**
- Create: `database/migrations/V026__customers_profile_and_order_link.sql`

**Interfaces:**
- Produces: `customers.name` (VARCHAR 255, NULL), `customers.phone` (VARCHAR 50,
  NULL), `orders.customer_id` (INT, NULL, FK → `customers.id` ON DELETE SET NULL,
  indexed) — consumed by `CustomerModel`/`OrderModel` in Task 2.

- [ ] **Step 1: Write the migration file**

```sql
ALTER TABLE `customers`
  ADD COLUMN `name`  VARCHAR(255) NULL AFTER `email`,
  ADD COLUMN `phone` VARCHAR(50)  NULL AFTER `name`;

ALTER TABLE `orders`
  ADD COLUMN `customer_id` INT NULL AFTER `id`;

ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
  ADD INDEX `idx_orders_customer` (`customer_id`);
```

Save this as the full contents of `database/migrations/V026__customers_profile_and_order_link.sql`.

- [ ] **Step 2: Confirm the local server and DB are up**

Run: `docker compose exec -T db mysqladmin ping -ubalonky -pbalonky --silent 2>/dev/null && echo "MySQL ready"`
Run: `curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/cs/`
Expected: `MySQL ready` and `200`. If the server isn't running, start it: `php -S localhost:8080 -t www >/tmp/php-server.log 2>&1 &`

- [ ] **Step 3: Apply the migration locally**

```bash
MIGRATE_TOKEN=$(php -r "echo (require 'config/settings.php')['migrate_token'];")
curl -s "http://localhost:8080/migrate.php?token=${MIGRATE_TOKEN}" | python3 -m json.tool
```
Expected: `"applied": ["V026__customers_profile_and_order_link"]`, `"count": 1`.

- [ ] **Step 4: Verify the columns exist**

```bash
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e "DESCRIBE customers;"
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e "DESCRIBE orders;"
```
Expected: `customers` shows `name`/`phone`; `orders` shows `customer_id` with a `MUL` key.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/V026__customers_profile_and_order_link.sql
git commit -m "feat: add customers profile fields and orders.customer_id link"
```

---

### Task 2: Model changes with tests (TDD)

**Files:**
- Modify: `tests/Unit/Models/CustomerModelTest.php`
- Modify: `src/Models/CustomerModel.php`
- Modify: `tests/Unit/Models/OrderModelTest.php`
- Modify: `src/Models/OrderModel.php`

**Interfaces:**
- Consumes: `database/migrations/V026...` columns (Task 1).
- Produces (consumed by `AccountController`/`CheckoutController` in Tasks 3–4):
  - `CustomerModel::updateProfile(int $id, string $name, string $phone): void`
  - `CustomerModel::updateEmail(int $id, string $email): void`
  - `OrderModel::forCustomer(int $customerId): array`
  - `OrderModel::create(array $customer, array $cartItems, string $total, ?int $customerId = null): string`
    (4th param, defaults to `null` — existing 3-arg callers unaffected)

- [ ] **Step 1: Add failing tests to `CustomerModelTest.php`**

In `tests/Unit/Models/CustomerModelTest.php`, add these two test methods right
before the final closing `}` of the class (after
`test_updatePasswordAndClearToken_updates_hash_and_clears_token`):

```php
    public function test_updateProfile_updates_name_and_phone(): void
    {
        CustomerModel::updateProfile(self::$customerId, 'Test Name', '+420111222333');

        $customer = CustomerModel::findById(self::$customerId);
        $this->assertSame('Test Name', $customer['name']);
        $this->assertSame('+420111222333', $customer['phone']);
    }

    public function test_updateEmail_updates_email(): void
    {
        $newEmail = 'updated-' . uniqid() . '@example.com';
        CustomerModel::updateEmail(self::$customerId, $newEmail);

        $customer = CustomerModel::findById(self::$customerId);
        $this->assertSame($newEmail, $customer['email']);
    }
```

- [ ] **Step 2: Add failing tests to `OrderModelTest.php`**

In `tests/Unit/Models/OrderModelTest.php`, change the imports at the top from:

```php
use App\Models\OrderModel;
use PHPUnit\Framework\TestCase;
```

to:

```php
use App\Models\CustomerModel;
use App\Models\OrderModel;
use PHPUnit\Framework\TestCase;
```

Then add these two test methods right before the final closing `}` of the class
(after `test_create_persists_subtype_id_and_name_snapshot`):

```php
    public function test_forCustomer_only_returns_orders_linked_to_that_customer(): void
    {
        $emailA = 'order-customer-a-' . uniqid() . '@example.com';
        $emailB = 'order-customer-b-' . uniqid() . '@example.com';
        $hash   = password_hash('testpassword', PASSWORD_BCRYPT);
        $customerAId = CustomerModel::create($emailA, $hash);
        $customerBId = CustomerModel::create($emailB, $hash);

        $orderNumber = OrderModel::create(
            [
                'customer_name'  => 'Linked Buyer',
                'customer_email' => $emailA,
                'customer_phone' => '+420000000001',
                'pickup_date'    => '2026-12-31',
                'notes'          => '',
            ],
            [
                'SKU-LINKED' => ['qty' => 1, 'name' => 'Linked Balloon', 'price' => '10.00', 'subtotal' => '10.00'],
            ],
            '10.00',
            $customerAId
        );

        $ordersA = OrderModel::forCustomer($customerAId);
        $this->assertCount(1, $ordersA);
        $this->assertSame($orderNumber, $ordersA[0]['order_number']);

        $this->assertSame([], OrderModel::forCustomer($customerBId));
    }

    public function test_create_without_customer_id_leaves_order_unlinked(): void
    {
        $order = OrderModel::findByNumber(self::$orderNumber);
        $this->assertNull($order['customer_id']);
    }
```

- [ ] **Step 3: Run both test files to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Models/CustomerModelTest.php tests/Unit/Models/OrderModelTest.php`
Expected: FAIL — `Call to undefined method App\Models\CustomerModel::updateProfile()`
(and similar for the other new methods/param).

- [ ] **Step 4: Implement the `CustomerModel` additions**

In `src/Models/CustomerModel.php`, change:

```php
    public static function updatePasswordAndClearToken(int $id, string $passwordHash): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE customers SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?');
        $stmt->execute([$passwordHash, $id]);
    }
}
```

to:

```php
    public static function updatePasswordAndClearToken(int $id, string $passwordHash): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE customers SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?');
        $stmt->execute([$passwordHash, $id]);
    }

    public static function updateProfile(int $id, string $name, string $phone): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE customers SET name = ?, phone = ? WHERE id = ?');
        $stmt->execute([$name, $phone, $id]);
    }

    public static function updateEmail(int $id, string $email): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE customers SET email = ? WHERE id = ?');
        $stmt->execute([$email, $id]);
    }
}
```

- [ ] **Step 5: Implement the `OrderModel` additions**

In `src/Models/OrderModel.php`, change:

```php
    public static function create(array $customer, array $cartItems, string $total): string
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('
            INSERT INTO orders
                (order_number, status, customer_name, customer_email,
                 customer_phone, pickup_date, total_amount, notes)
            VALUES (?, \'pending\', ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            'PENDING',
            $customer['customer_name'],
            $customer['customer_email'],
            $customer['customer_phone'],
            $customer['pickup_date'] ?: null,
            $total,
            $customer['notes'] ?? '',
        ]);
```

to:

```php
    public static function create(array $customer, array $cartItems, string $total, ?int $customerId = null): string
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('
            INSERT INTO orders
                (customer_id, order_number, status, customer_name, customer_email,
                 customer_phone, pickup_date, total_amount, notes)
            VALUES (?, ?, \'pending\', ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $customerId,
            'PENDING',
            $customer['customer_name'],
            $customer['customer_email'],
            $customer['customer_phone'],
            $customer['pickup_date'] ?: null,
            $total,
            $customer['notes'] ?? '',
        ]);
```

Then change:

```php
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();
        return ['orders' => $stmt->fetchAll(), 'total' => $total, 'pages' => max(1, (int) ceil($total / $perPage))];
    }
}
```

to:

```php
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();
        return ['orders' => $stmt->fetchAll(), 'total' => $total, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public static function forCustomer(int $customerId): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT order_number, status, total_amount, created_at
             FROM orders WHERE customer_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$customerId]);
        return $stmt->fetchAll();
    }
}
```

- [ ] **Step 6: Run both test files to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Models/CustomerModelTest.php tests/Unit/Models/OrderModelTest.php --testdox`
Expected: all green — 10 tests in `CustomerModelTest`, 7 in `OrderModelTest`.

- [ ] **Step 7: Run the full suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all green, no regressions (the `create()` signature change is
backward-compatible — confirm the pre-existing `OrderModelTest` cases that call
`create()` with 3 args still pass).

- [ ] **Step 8: Commit**

```bash
git add tests/Unit/Models/CustomerModelTest.php src/Models/CustomerModel.php \
  tests/Unit/Models/OrderModelTest.php src/Models/OrderModel.php
git commit -m "feat: add CustomerModel profile updates and OrderModel customer linking"
```

---

### Task 3: Customer Info page

**Files:**
- Modify: `src/Controllers/AccountController.php`
- Modify: `src/routes.php`
- Create: `templates/public/account/_sidebar.twig`
- Create: `templates/public/account/customer-info.twig`
- Delete: `templates/public/account.twig`
- Modify: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`
  (adds the **full** translation key set for this whole feature — Tasks 4 and 5
  reuse these keys without further lang-file edits, so the sidebar partial added in
  this task never shows a raw untranslated key)

**Interfaces:**
- Consumes: `CustomerModel::findById`, `CustomerModel::findByEmail`,
  `CustomerModel::updateProfile`, `CustomerModel::updateEmail` (Task 2).
- Produces: `AccountController::requireLogin(Request $request): ?array` — a private
  helper consumed by `ordersList()` (Task 4) and `passwordForm()`/`passwordSubmit()`
  (Task 5).

- [ ] **Step 1: Add the full translation key set to all five language files**

Add these keys to each `lang/*.json` file's existing `account.*` block (keep
alphabetical order within the block, matching the existing style). Full block per
language, showing the complete new key list — insert each key alphabetically among
the existing `account.*` keys already in the file (don't duplicate any that already
exist, like `account.email`, `account.title`, `account.error_password`,
`account.error_email_taken`, `account.new_password`, `account.password_confirm`).

**`lang/cs.json`** — add:
```json
  "account.current_password": "Aktuální heslo (jen při změně e-mailu)",
  "account.error_current_password": "Nesprávné aktuální heslo.",
  "account.error_invalid": "Vyplňte prosím jméno a platný e-mail.",
  "account.name": "Jméno",
  "account.nav_customer_info": "Osobní údaje",
  "account.nav_orders": "Objednávky",
  "account.nav_password": "Změna hesla",
  "account.orders_date": "Datum",
  "account.orders_empty": "Zatím nemáte žádné objednávky.",
  "account.orders_status": "Stav",
  "account.password_submit": "Změnit heslo",
  "account.password_success": "Heslo bylo úspěšně změněno.",
  "account.phone": "Telefon",
  "account.update_submit": "Uložit změny",
  "account.update_success": "Údaje byly úspěšně uloženy.",
```

**`lang/en.json`** — add:
```json
  "account.current_password": "Current password (only needed to change email)",
  "account.error_current_password": "Incorrect current password.",
  "account.error_invalid": "Please enter a name and a valid email.",
  "account.name": "Name",
  "account.nav_customer_info": "Customer Info",
  "account.nav_orders": "Orders",
  "account.nav_password": "Change Password",
  "account.orders_date": "Date",
  "account.orders_empty": "You have no orders yet.",
  "account.orders_status": "Status",
  "account.password_submit": "Change password",
  "account.password_success": "Your password has been changed.",
  "account.phone": "Phone",
  "account.update_submit": "Save changes",
  "account.update_success": "Your details have been saved.",
```

**`lang/sk.json`** — add:
```json
  "account.current_password": "Aktuálne heslo (len pri zmene e-mailu)",
  "account.error_current_password": "Nesprávne aktuálne heslo.",
  "account.error_invalid": "Zadajte prosím meno a platný e-mail.",
  "account.name": "Meno",
  "account.nav_customer_info": "Osobné údaje",
  "account.nav_orders": "Objednávky",
  "account.nav_password": "Zmena hesla",
  "account.orders_date": "Dátum",
  "account.orders_empty": "Zatiaľ nemáte žiadne objednávky.",
  "account.orders_status": "Stav",
  "account.password_submit": "Zmeniť heslo",
  "account.password_success": "Heslo bolo úspešne zmenené.",
  "account.phone": "Telefón",
  "account.update_submit": "Uložiť zmeny",
  "account.update_success": "Údaje boli úspešne uložené.",
```

**`lang/ru.json`** — add:
```json
  "account.current_password": "Текущий пароль (только при смене e-mail)",
  "account.error_current_password": "Неверный текущий пароль.",
  "account.error_invalid": "Пожалуйста, укажите имя и действительный адрес электронной почты.",
  "account.name": "Имя",
  "account.nav_customer_info": "Личные данные",
  "account.nav_orders": "Заказы",
  "account.nav_password": "Смена пароля",
  "account.orders_date": "Дата",
  "account.orders_empty": "У вас пока нет заказов.",
  "account.orders_status": "Статус",
  "account.password_submit": "Изменить пароль",
  "account.password_success": "Пароль был успешно изменён.",
  "account.phone": "Телефон",
  "account.update_submit": "Сохранить изменения",
  "account.update_success": "Данные успешно сохранены.",
```

**`lang/uk.json`** — add:
```json
  "account.current_password": "Поточний пароль (лише при зміні e-mail)",
  "account.error_current_password": "Невірний поточний пароль.",
  "account.error_invalid": "Будь ласка, вкажіть ім'я та дійсну електронну адресу.",
  "account.name": "Ім'я",
  "account.nav_customer_info": "Особисті дані",
  "account.nav_orders": "Замовлення",
  "account.nav_password": "Зміна пароля",
  "account.orders_date": "Дата",
  "account.orders_empty": "У вас поки немає замовлень.",
  "account.orders_status": "Статус",
  "account.password_submit": "Змінити пароль",
  "account.password_success": "Пароль успішно змінено.",
  "account.phone": "Телефон",
  "account.update_submit": "Зберегти зміни",
  "account.update_success": "Дані успішно збережено.",
```

After editing, verify:
```bash
for f in cs en ru uk sk; do php -r "var_dump(json_decode(file_get_contents('lang/$f.json')) !== null);"; done
php -r '
$sets = [];
foreach (["cs","en","ru","uk","sk"] as $l) {
    $sets[$l] = array_keys(json_decode(file_get_contents("lang/$l.json"), true));
    sort($sets[$l]);
}
$base = $sets["cs"];
foreach ($sets as $l => $keys) {
    if ($keys !== $base) { echo "MISMATCH: $l\n"; }
}
echo "done\n";
'
```
Expected: five `bool(true)` lines, then `done`, no `MISMATCH` lines.

- [ ] **Step 2: Replace `index()` with the Customer Info version and add `update()` + `requireLogin()`**

In `src/Controllers/AccountController.php`, change:

```php
    public function index(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $lang = $request->getAttribute('lang');
        if (empty($_SESSION['customer'])) {
            return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
        }

        $customer = CustomerModel::findById((int) $_SESSION['customer']['id']);
        if (!$customer) {
            unset($_SESSION['customer']);
            return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
        }

        return $this->render($request, $response, 'public/account.twig', [
            'account' => $customer,
        ]);
    }
```

to:

```php
    public function index(Request $request, Response $response, array $args): Response
    {
        $customer = $this->requireLogin($request);
        if (!$customer) {
            $lang = $request->getAttribute('lang');
            return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
        }

        return $this->render($request, $response, 'public/account/customer-info.twig', [
            'account' => $customer,
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $customer = $this->requireLogin($request);
        $lang     = $request->getAttribute('lang');
        if (!$customer) {
            return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
        }

        $body            = (array) $request->getParsedBody();
        $name            = trim($body['name'] ?? '');
        $phone           = trim($body['phone'] ?? '');
        $email           = trim($body['email'] ?? '');
        $currentPassword = $body['current_password'] ?? '';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->render($request, $response, 'public/account/customer-info.twig', [
                'account' => array_merge($customer, ['name' => $name, 'phone' => $phone, 'email' => $email]),
                'error'   => 'account.error_invalid',
            ]);
        }

        if ($email !== $customer['email']) {
            if ($currentPassword === '' || !password_verify($currentPassword, $customer['password_hash'])) {
                return $this->render($request, $response, 'public/account/customer-info.twig', [
                    'account' => array_merge($customer, ['name' => $name, 'phone' => $phone, 'email' => $email]),
                    'error'   => 'account.error_current_password',
                ]);
            }

            $existing = CustomerModel::findByEmail($email);
            if ($existing && (int) $existing['id'] !== (int) $customer['id']) {
                return $this->render($request, $response, 'public/account/customer-info.twig', [
                    'account' => array_merge($customer, ['name' => $name, 'phone' => $phone, 'email' => $email]),
                    'error'   => 'account.error_email_taken',
                ]);
            }

            CustomerModel::updateEmail((int) $customer['id'], $email);
            $_SESSION['customer']['email'] = $email;
        }

        CustomerModel::updateProfile((int) $customer['id'], $name, $phone);
        $this->flash('success', 'account.update_success');
        return $response->withHeader('Location', "/{$lang}/account")->withStatus(302);
    }
```

Then add the `requireLogin()` helper at the very end of the class. Change:

```php
        CustomerModel::updatePasswordAndClearToken((int) $customer['id'], password_hash($password, PASSWORD_BCRYPT));
        $this->flash('success', 'account.reset_success');
        return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
    }
}
```

to:

```php
        CustomerModel::updatePasswordAndClearToken((int) $customer['id'], password_hash($password, PASSWORD_BCRYPT));
        $this->flash('success', 'account.reset_success');
        return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
    }

    private function requireLogin(Request $request): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['customer'])) {
            return null;
        }
        $customer = CustomerModel::findById((int) $_SESSION['customer']['id']);
        if (!$customer) {
            unset($_SESSION['customer']);
            return null;
        }
        return $customer;
    }
}
```

- [ ] **Step 3: Register the `POST /{lang}/account` route**

In `src/routes.php`, change:

```php
$app->get('/{lang}/account',          AccountController::class . ':index');
```

to:

```php
$app->get('/{lang}/account',          AccountController::class . ':index');
$app->post('/{lang}/account',         AccountController::class . ':update');
```

- [ ] **Step 4: Create the sidebar partial**

Create `templates/public/account/_sidebar.twig`:

```twig
<div class="cat-filter-list">
    <a href="/{{ lang }}/account" class="cat-filter {{ current_path == '/account' ? 'active' : '' }}">{{ t('account.nav_customer_info') }}</a>
    <a href="/{{ lang }}/account/orders" class="cat-filter {{ current_path == '/account/orders' ? 'active' : '' }}">{{ t('account.nav_orders') }}</a>
    <a href="/{{ lang }}/account/password" class="cat-filter {{ current_path == '/account/password' ? 'active' : '' }}">{{ t('account.nav_password') }}</a>
</div>
```

Note: the Orders and Change Password links will 404 until Tasks 4 and 5 register
their routes — expected at this point in the plan, same as the prior account feature's
interim states.

- [ ] **Step 5: Create `customer-info.twig`**

Create `templates/public/account/customer-info.twig`:

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('account.nav_customer_info') }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{% endblock %}
{% block head %}<meta name="robots" content="noindex,nofollow">{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container"><h1>{{ t('account.title') }} — {{ t('account.nav_customer_info') }}</h1></div>
</section>
<div class="container shop-layout">
    <aside class="shop-sidebar">
        {% include 'public/account/_sidebar.twig' %}
    </aside>
    <div>
        {% if error %}
        <p class="form-error">{{ t(error) }}</p>
        {% endif %}
        <form action="/{{ lang }}/account" method="POST" class="contact-form">
            <div class="form-group">
                <label for="name">{{ t('account.name') }}</label>
                <input type="text" id="name" name="name" required value="{{ account.name ?? '' }}">
            </div>
            <div class="form-group">
                <label for="phone">{{ t('account.phone') }}</label>
                <input type="text" id="phone" name="phone" value="{{ account.phone ?? '' }}">
            </div>
            <div class="form-group">
                <label for="email">{{ t('account.email') }}</label>
                <input type="email" id="email" name="email" required value="{{ account.email ?? '' }}">
            </div>
            <div class="form-group">
                <label for="current_password">{{ t('account.current_password') }}</label>
                <input type="password" id="current_password" name="current_password">
            </div>
            <button type="submit" class="btn btn-primary">{{ t('account.update_submit') }}</button>
        </form>
    </div>
</div>
{% endblock %}
```

- [ ] **Step 6: Delete the old simple account page**

```bash
git rm templates/public/account.twig
```

- [ ] **Step 7: Manual smoke test**

Ensure the local server is running (Task 1, Step 2), then:

```bash
rm -f /tmp/cookies-ci.txt
EMAIL="ci-smoke-$(date +%s)@example.com"
curl -s -c /tmp/cookies-ci.txt -o /dev/null http://localhost:8080/cs/register
curl -s -b /tmp/cookies-ci.txt -c /tmp/cookies-ci.txt -o /dev/null \
  -d "email=${EMAIL}&password=testpass123&password_confirm=testpass123" \
  http://localhost:8080/cs/register

curl -s -b /tmp/cookies-ci.txt -o /dev/null -w "account page: %{http_code}\n" http://localhost:8080/cs/account
curl -s -b /tmp/cookies-ci.txt http://localhost:8080/cs/account | grep -o 'name="email"[^>]*value="[^"]*"'

curl -s -b /tmp/cookies-ci.txt -c /tmp/cookies-ci.txt -o /dev/null -w "update name/phone: %{http_code}\n" \
  -d "name=Jane+Doe&phone=%2B420999888777&email=${EMAIL}&current_password=" \
  http://localhost:8080/cs/account
curl -s -b /tmp/cookies-ci.txt http://localhost:8080/cs/account | grep -o 'value="Jane Doe"\|value="+420999888777"'

NEWEMAIL="ci-smoke-changed-$(date +%s)@example.com"
curl -s -b /tmp/cookies-ci.txt -c /tmp/cookies-ci.txt -o /dev/null -w "email change wrong pw: %{http_code}\n" \
  -d "name=Jane+Doe&phone=%2B420999888777&email=${NEWEMAIL}&current_password=wrongpass" \
  http://localhost:8080/cs/account
curl -s -b /tmp/cookies-ci.txt http://localhost:8080/cs/account | grep -o 'value="'"${EMAIL}"'"'

curl -s -b /tmp/cookies-ci.txt -c /tmp/cookies-ci.txt -o /dev/null -w "email change correct pw: %{http_code}\n" \
  -d "name=Jane+Doe&phone=%2B420999888777&email=${NEWEMAIL}&current_password=testpass123" \
  http://localhost:8080/cs/account
curl -s -b /tmp/cookies-ci.txt http://localhost:8080/cs/account | grep -o 'value="'"${NEWEMAIL}"'"'
```

Expected: `account page: 200`, the initial email pre-filled in the form, the name/phone
update reflected on reload, the wrong-password email change rejected (still shows the
old email), and the correct-password email change succeeding (shows the new email).

- [ ] **Step 8: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all green.

- [ ] **Step 9: Commit**

```bash
git add src/Controllers/AccountController.php src/routes.php \
  templates/public/account/_sidebar.twig templates/public/account/customer-info.twig \
  lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json
git commit -m "feat: add editable Customer Info page with account sidebar"
```

---

### Task 4: Orders page + checkout linking

**Files:**
- Modify: `src/Controllers/AccountController.php`
- Modify: `src/Controllers/CheckoutController.php`
- Modify: `src/routes.php`
- Create: `templates/public/account/orders.twig`

**Interfaces:**
- Consumes: `OrderModel::forCustomer(int $customerId): array` (Task 2),
  `AccountController::requireLogin()` (Task 3).

- [ ] **Step 1: Add `ordersList()` to `AccountController`**

In `src/Controllers/AccountController.php`, change the import block:

```php
use App\Models\CustomerModel;
use App\Services\Mailer;
use App\Services\Seo;
```

to:

```php
use App\Models\CustomerModel;
use App\Models\OrderModel;
use App\Services\Mailer;
use App\Services\Seo;
```

Then change:

```php
        CustomerModel::updateProfile((int) $customer['id'], $name, $phone);
        $this->flash('success', 'account.update_success');
        return $response->withHeader('Location', "/{$lang}/account")->withStatus(302);
    }

    public function forgotForm(Request $request, Response $response, array $args): Response
```

to:

```php
        CustomerModel::updateProfile((int) $customer['id'], $name, $phone);
        $this->flash('success', 'account.update_success');
        return $response->withHeader('Location', "/{$lang}/account")->withStatus(302);
    }

    public function ordersList(Request $request, Response $response, array $args): Response
    {
        $customer = $this->requireLogin($request);
        if (!$customer) {
            $lang = $request->getAttribute('lang');
            return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
        }

        return $this->render($request, $response, 'public/account/orders.twig', [
            'orders' => OrderModel::forCustomer((int) $customer['id']),
        ]);
    }

    public function forgotForm(Request $request, Response $response, array $args): Response
```

- [ ] **Step 2: Register the route**

In `src/routes.php`, change:

```php
$app->get('/{lang}/account',          AccountController::class . ':index');
$app->post('/{lang}/account',         AccountController::class . ':update');
```

to:

```php
$app->get('/{lang}/account',          AccountController::class . ':index');
$app->post('/{lang}/account',         AccountController::class . ':update');
$app->get('/{lang}/account/orders',   AccountController::class . ':ordersList');
```

- [ ] **Step 3: Link new orders to the logged-in customer at checkout**

In `src/Controllers/CheckoutController.php`, change:

```php
        $orderNumber = OrderModel::create(
            [
                'customer_name'  => $name,
                'customer_email' => $email,
                'customer_phone' => $phone,
                'pickup_date'    => $date,
                'notes'          => $notes,
            ],
            Cart::items(),
            Cart::total()
        );
```

to:

```php
        $orderNumber = OrderModel::create(
            [
                'customer_name'  => $name,
                'customer_email' => $email,
                'customer_phone' => $phone,
                'pickup_date'    => $date,
                'notes'          => $notes,
            ],
            Cart::items(),
            Cart::total(),
            $_SESSION['customer']['id'] ?? null
        );
```

(Session is already started at this point — `Cart::isEmpty()` earlier in `submit()`
calls `Cart::boot()` internally, which starts the session.)

- [ ] **Step 4: Create `orders.twig`**

Create `templates/public/account/orders.twig`:

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('account.nav_orders') }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{% endblock %}
{% block head %}<meta name="robots" content="noindex,nofollow">{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container"><h1>{{ t('account.title') }} — {{ t('account.nav_orders') }}</h1></div>
</section>
<div class="container shop-layout">
    <aside class="shop-sidebar">
        {% include 'public/account/_sidebar.twig' %}
    </aside>
    <div>
        {% if orders %}
        <table class="cart-table">
            <thead>
                <tr>
                    <th>{{ t('checkout.order_number') }}</th>
                    <th>{{ t('account.orders_date') }}</th>
                    <th>{{ t('account.orders_status') }}</th>
                    <th>{{ t('order.total') }}</th>
                </tr>
            </thead>
            <tbody>
                {% for order in orders %}
                <tr>
                    <td data-label="{{ t('checkout.order_number') }}">
                        <a href="/{{ lang }}/order/{{ order.order_number }}">{{ order.order_number }}</a>
                    </td>
                    <td data-label="{{ t('account.orders_date') }}">{{ order.created_at|date('d.m.Y') }}</td>
                    <td data-label="{{ t('account.orders_status') }}">
                        <span class="order-status order-status--{{ order.status }}">{{ t('order.status.' ~ order.status) }}</span>
                    </td>
                    <td data-label="{{ t('order.total') }}">{{ order.total_amount|number_format(2, '.', ' ') }} Kč</td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
        {% else %}
        <p>{{ t('account.orders_empty') }}</p>
        {% endif %}
    </div>
</div>
{% endblock %}
```

- [ ] **Step 5: Manual smoke test — place an order while logged in**

With the local server running and using the cookie jar from Task 3's smoke test (or
a fresh login):

```bash
curl -s -b /tmp/cookies-ci.txt -o /dev/null -w "orders page (should be empty state): %{http_code}\n" http://localhost:8080/cs/account/orders
curl -s -b /tmp/cookies-ci.txt http://localhost:8080/cs/account/orders | grep -i "žádné objednávky\|no orders"

curl -s -b /tmp/cookies-ci.txt -c /tmp/cookies-ci.txt -o /dev/null http://localhost:8080/cs/cart/add \
  -d "sku=$(docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -N -e 'SELECT p.sku FROM products p WHERE NOT EXISTS (SELECT 1 FROM product_subtypes s WHERE s.product_id = p.id) LIMIT 1' | tr -d '\r')&qty=1"
curl -s -b /tmp/cookies-ci.txt -c /tmp/cookies-ci.txt -o /dev/null -w "checkout submit: %{http_code}\n" \
  -d "customer_name=CI+Buyer&customer_email=ci-buyer@example.com&customer_phone=%2B420111222333&pickup_date=2026-12-31&notes=" \
  http://localhost:8080/cs/checkout

curl -s -b /tmp/cookies-ci.txt http://localhost:8080/cs/account/orders | grep -o 'BD-[0-9]*-[0-9]*'
```

Expected: the orders page starts empty, then after checkout shows at least one
`BD-YYYYMMDD-NNNNN` order number linked to the logged-in session.

- [ ] **Step 6: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/AccountController.php src/Controllers/CheckoutController.php \
  src/routes.php templates/public/account/orders.twig
git commit -m "feat: add Orders page and link new orders to logged-in customers"
```

---

### Task 5: Change Password page

**Files:**
- Modify: `src/Controllers/AccountController.php`
- Modify: `src/routes.php`
- Create: `templates/public/account/password.twig`

**Interfaces:**
- Consumes: `CustomerModel::updatePasswordAndClearToken()` (already used by the
  token-reset flow), `AccountController::requireLogin()` (Task 3).

- [ ] **Step 1: Add `passwordForm()`/`passwordSubmit()` to `AccountController`**

In `src/Controllers/AccountController.php`, change:

```php
        CustomerModel::updatePasswordAndClearToken((int) $customer['id'], password_hash($password, PASSWORD_BCRYPT));
        $this->flash('success', 'account.reset_success');
        return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
    }

    private function requireLogin(Request $request): ?array
```

to:

```php
        CustomerModel::updatePasswordAndClearToken((int) $customer['id'], password_hash($password, PASSWORD_BCRYPT));
        $this->flash('success', 'account.reset_success');
        return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
    }

    public function passwordForm(Request $request, Response $response, array $args): Response
    {
        $customer = $this->requireLogin($request);
        if (!$customer) {
            $lang = $request->getAttribute('lang');
            return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
        }

        return $this->render($request, $response, 'public/account/password.twig');
    }

    public function passwordSubmit(Request $request, Response $response, array $args): Response
    {
        $customer = $this->requireLogin($request);
        $lang     = $request->getAttribute('lang');
        if (!$customer) {
            return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
        }

        $body            = (array) $request->getParsedBody();
        $currentPassword = $body['current_password'] ?? '';
        $password        = $body['password'] ?? '';
        $passwordConfirm = $body['password_confirm'] ?? '';

        if ($currentPassword === '' || !password_verify($currentPassword, $customer['password_hash'])) {
            return $this->render($request, $response, 'public/account/password.twig', [
                'error' => 'account.error_current_password',
            ]);
        }

        if (strlen($password) < 8 || $password !== $passwordConfirm) {
            return $this->render($request, $response, 'public/account/password.twig', [
                'error' => 'account.error_password',
            ]);
        }

        CustomerModel::updatePasswordAndClearToken((int) $customer['id'], password_hash($password, PASSWORD_BCRYPT));
        $this->flash('success', 'account.password_success');
        return $response->withHeader('Location', "/{$lang}/account")->withStatus(302);
    }

    private function requireLogin(Request $request): ?array
```

- [ ] **Step 2: Register the routes**

In `src/routes.php`, change:

```php
$app->get('/{lang}/account/orders',   AccountController::class . ':ordersList');
```

to:

```php
$app->get('/{lang}/account/orders',   AccountController::class . ':ordersList');
$app->get('/{lang}/account/password',  AccountController::class . ':passwordForm');
$app->post('/{lang}/account/password', AccountController::class . ':passwordSubmit');
```

- [ ] **Step 3: Create `password.twig`**

Create `templates/public/account/password.twig`:

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('account.nav_password') }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{% endblock %}
{% block head %}<meta name="robots" content="noindex,nofollow">{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container"><h1>{{ t('account.title') }} — {{ t('account.nav_password') }}</h1></div>
</section>
<div class="container shop-layout">
    <aside class="shop-sidebar">
        {% include 'public/account/_sidebar.twig' %}
    </aside>
    <div>
        {% if error %}
        <p class="form-error">{{ t(error) }}</p>
        {% endif %}
        <form action="/{{ lang }}/account/password" method="POST" class="contact-form">
            <div class="form-group">
                <label for="current_password">{{ t('account.current_password') }}</label>
                <input type="password" id="current_password" name="current_password" required>
            </div>
            <div class="form-group">
                <label for="password">{{ t('account.new_password') }}</label>
                <input type="password" id="password" name="password" required minlength="8">
            </div>
            <div class="form-group">
                <label for="password_confirm">{{ t('account.password_confirm') }}</label>
                <input type="password" id="password_confirm" name="password_confirm" required minlength="8">
            </div>
            <button type="submit" class="btn btn-primary">{{ t('account.password_submit') }}</button>
        </form>
    </div>
</div>
{% endblock %}
```

- [ ] **Step 4: Manual smoke test**

```bash
curl -s -b /tmp/cookies-ci.txt -o /dev/null -w "password form: %{http_code}\n" http://localhost:8080/cs/account/password

curl -s -b /tmp/cookies-ci.txt -c /tmp/cookies-ci.txt -o /dev/null -w "wrong current password: %{http_code}\n" \
  -d "current_password=wrongpass&password=newpassword1&password_confirm=newpassword1" \
  http://localhost:8080/cs/account/password
curl -s -b /tmp/cookies-ci.txt http://localhost:8080/cs/account/password | grep -i "form-error"

curl -s -b /tmp/cookies-ci.txt -c /tmp/cookies-ci.txt -o /dev/null -w "correct current password: %{http_code}\n" \
  -d "current_password=testpass123&password=newpassword1&password_confirm=newpassword1" \
  http://localhost:8080/cs/account/password

curl -s -c /tmp/cookies-relogin.txt -o /dev/null -w "relogin with new password: %{http_code}\n" \
  -d "email=ci-smoke-changed-$(date +%s)@example.com&password=newpassword1" \
  http://localhost:8080/cs/login
```

Expected: `password form: 200`, wrong-current-password rejected with a visible
`form-error`, correct current password succeeds (302 redirect to `/account`). Note:
the final relogin command's email won't match exactly (the smoke test's `$(date
+%s)` differs per invocation) — instead verify the password change succeeded by
checking the flash message on `/account`:

```bash
curl -s -b /tmp/cookies-ci.txt http://localhost:8080/cs/account | grep -i "flash-success\|úspěšně změněno"
```

- [ ] **Step 5: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/AccountController.php src/routes.php templates/public/account/password.twig
git commit -m "feat: add Change Password page to account sidebar"
```

---

### Task 6: Cross-language and visual verification

**Files:** none (verification only, per `.claude/rules/unit-testing.md` — Twig
templates/CSS are verified by running the app, not by unit tests).

- [ ] **Step 1: Verify all five languages render the three account pages**

Requires an authenticated session per language (register a throwaway account per
language, or reuse a single logged-in session and just check each language's copy
renders — language doesn't gate login state, so one login is enough):

```bash
for l in cs en ru uk sk; do
  curl -s -b /tmp/cookies-ci.txt -o /dev/null -w "$l account:         %{http_code}\n" http://localhost:8080/$l/account
  curl -s -b /tmp/cookies-ci.txt -o /dev/null -w "$l account/orders:  %{http_code}\n" http://localhost:8080/$l/account/orders
  curl -s -b /tmp/cookies-ci.txt -o /dev/null -w "$l account/password:%{http_code}\n" http://localhost:8080/$l/account/password
done
```
Expected: every line `200`.

- [ ] **Step 2: Browser check**

Open `http://localhost:8080/cs/account` in a browser (or screenshot tool) while
logged in and confirm:
- The sidebar shows all three links (Osobní údaje / Objednávky / Změna hesla) with
  the current page highlighted active.
- The Customer Info form pre-fills name/phone/email correctly.
- The Orders page shows the order placed during Task 4's smoke test.
- The mobile layout (≤768px) collapses the sidebar above the content, matching how
  `/shop` already behaves at that breakpoint.

- [ ] **Step 3: Final full-suite run**

Run: `php vendor/bin/phpunit --testdox`
Expected: all green, count includes the 4 new model tests added in Task 2 (2 in
`CustomerModelTest`, 2 in `OrderModelTest`) on top of the current baseline.

No commit for this task — it's verification only.
