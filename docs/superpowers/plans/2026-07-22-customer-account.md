# Customer Register / Login / Logout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give public-site visitors a basic account: register, log in, log out, and reset a forgotten password, with no changes to guest checkout.

**Architecture:** A new `customers` table + static `CustomerModel` (mirrors `AdminUserModel`), a new `AccountController` (mirrors `Admin\AuthController`'s session pattern but with its own `$_SESSION['customer']` key, separate from `$_SESSION['admin_user']`), five new Twig templates reusing the existing `.contact-form`/`.form-group`/`.btn` CSS, and small additions to `BaseController::render()` and `layout/base.twig` so every public page can show login/register vs. account/logout links.

**Tech Stack:** Slim 4, Twig 3, PDO/MySQL 8, PHPUnit 11 (Docker MySQL for model tests). No new dependencies.

## Global Constraints

- Registration collects email + password only — no name/phone field (per approved spec).
- No email verification step — account is active immediately on registration.
- No CSRF token — matches the existing codebase convention (contact form has none).
- Passwords: `password_hash()`/`password_verify()` with `PASSWORD_BCRYPT`, minimum 8 characters — same rule as admin setup (`AuthController::setupSubmit`).
- Login failures show one generic message — never reveal whether an email is registered.
- Forgot-password always shows the same success message regardless of whether the email exists.
- Reset tokens: `bin2hex(random_bytes(32))`, 1-hour expiry, single-use (cleared on successful reset).
- Every visible string goes through `t('key')`; the key must exist in all five `lang/{cs,en,ru,uk,sk}.json` files with matching key sets (`.claude/rules/frontend.md`).
- All public links are language-prefixed: `/{lang}/...` (`.claude/rules/frontend.md`).
- Prepared statements with bound parameters only, no string interpolation of request data into SQL (`.claude/rules/database.md`).
- New migration file named `V0NN__snake_case_description.sql`, never edit an applied migration (`.claude/rules/database.md`).
- `noindex,nofollow` on all five new pages; none are added to `Sitemap::paths()` — matches how `/cart`, `/checkout`, `/wishlist`, `/compare` are already excluded.
- Run `php vendor/bin/phpunit` (full suite) before considering any task's tests "done" is not required per-task, but the new `CustomerModelTest.php` must pass on its own each time it's run.
- Controllers stay untested per project convention (`.claude/rules/unit-testing.md`) — `AccountController` is verified by hand via `/start` + curl/browser, not a PHPUnit HTTP harness.

---

### Task 1: `customers` table migration

**Files:**
- Create: `database/migrations/V025__customers.sql`

**Interfaces:**
- Produces: a `customers` table with columns `id`, `email` (unique), `password_hash`, `reset_token`, `reset_token_expires`, `created_at` — consumed by `CustomerModel` in Task 2.

- [ ] **Step 1: Write the migration file**

```sql
CREATE TABLE `customers` (
  `id`                   INT AUTO_INCREMENT PRIMARY KEY,
  `email`                VARCHAR(255) NOT NULL UNIQUE,
  `password_hash`        VARCHAR(255) NOT NULL,
  `reset_token`          VARCHAR(64) NULL,
  `reset_token_expires`  DATETIME NULL,
  `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Save this as the full contents of `database/migrations/V025__customers.sql`.

- [ ] **Step 2: Start the local DB if it isn't running**

Run: `docker compose up -d && until docker compose exec -T db mysqladmin ping -ubalonky -pbalonky --silent 2>/dev/null; do sleep 1; done`
Expected: command returns once MySQL accepts connections (no error output).

- [ ] **Step 3: Apply the migration locally**

Run:
```bash
php -S localhost:8080 -t www >/tmp/php-server.log 2>&1 &
sleep 1
MIGRATE_TOKEN=$(php -r "echo (require 'config/settings.php')['migrate_token'];")
curl -s "http://localhost:8080/migrate.php?token=${MIGRATE_TOKEN}" | python3 -m json.tool
```
Expected: JSON showing `"applied": ["V025__customers.sql"]` (or similar) and `"count": 1`. If a server was already running on 8080 from a previous task, skip starting a new one and just run the `curl` line.

- [ ] **Step 4: Verify the table exists**

Run: `docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e "DESCRIBE customers;"`
Expected: a table listing showing `id`, `email`, `password_hash`, `reset_token`, `reset_token_expires`, `created_at`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/V025__customers.sql
git commit -m "feat: add customers table migration"
```

---

### Task 2: `CustomerModel` with tests (TDD)

**Files:**
- Create: `tests/Unit/Models/CustomerModelTest.php`
- Create: `src/Models/CustomerModel.php`

**Interfaces:**
- Consumes: `App\Models\Database::getConnection()` (existing PDO singleton).
- Produces (consumed by `AccountController` in Tasks 3–4):
  - `CustomerModel::findByEmail(string $email): ?array`
  - `CustomerModel::findById(int $id): ?array`
  - `CustomerModel::create(string $email, string $passwordHash): int`
  - `CustomerModel::setResetToken(int $id, string $token, string $expiresAt): void`
  - `CustomerModel::findByValidResetToken(string $token): ?array`
  - `CustomerModel::updatePasswordAndClearToken(int $id, string $passwordHash): void`

- [ ] **Step 1: Write the failing test file**

Create `tests/Unit/Models/CustomerModelTest.php`:

```php
<?php
namespace Tests\Unit\Models;

use App\Models\CustomerModel;
use PHPUnit\Framework\TestCase;

class CustomerModelTest extends TestCase
{
    private static string $email;
    private static string $hash;
    private static int $customerId;

    public static function setUpBeforeClass(): void
    {
        self::$email      = 'customer-test-' . uniqid() . '@example.com';
        self::$hash       = password_hash('testpassword', PASSWORD_BCRYPT);
        self::$customerId = CustomerModel::create(self::$email, self::$hash);
    }

    public function test_create_returns_positive_id(): void
    {
        $this->assertGreaterThan(0, self::$customerId);
    }

    public function test_findByEmail_returns_created_customer(): void
    {
        $customer = CustomerModel::findByEmail(self::$email);
        $this->assertNotNull($customer);
        $this->assertSame(self::$email, $customer['email']);
        $this->assertSame(self::$hash, $customer['password_hash']);
    }

    public function test_findByEmail_returns_null_for_unknown_email(): void
    {
        $this->assertNull(CustomerModel::findByEmail('nobody-' . uniqid() . '@example.com'));
    }

    public function test_findById_returns_created_customer(): void
    {
        $customer = CustomerModel::findById(self::$customerId);
        $this->assertNotNull($customer);
        $this->assertSame(self::$email, $customer['email']);
    }

    public function test_findById_returns_null_for_unknown_id(): void
    {
        $this->assertNull(CustomerModel::findById(999999999));
    }

    public function test_setResetToken_then_findByValidResetToken_finds_it(): void
    {
        $token = 'token-' . uniqid();
        CustomerModel::setResetToken(self::$customerId, $token, date('Y-m-d H:i:s', time() + 3600));

        $found = CustomerModel::findByValidResetToken($token);
        $this->assertNotNull($found);
        $this->assertSame(self::$customerId, (int) $found['id']);
    }

    public function test_findByValidResetToken_returns_null_when_expired(): void
    {
        $token = 'expired-token-' . uniqid();
        CustomerModel::setResetToken(self::$customerId, $token, date('Y-m-d H:i:s', time() - 3600));

        $this->assertNull(CustomerModel::findByValidResetToken($token));
    }

    public function test_updatePasswordAndClearToken_updates_hash_and_clears_token(): void
    {
        $token = 'clear-token-' . uniqid();
        CustomerModel::setResetToken(self::$customerId, $token, date('Y-m-d H:i:s', time() + 3600));

        $newHash = password_hash('newpassword', PASSWORD_BCRYPT);
        CustomerModel::updatePasswordAndClearToken(self::$customerId, $newHash);

        $customer = CustomerModel::findById(self::$customerId);
        $this->assertSame($newHash, $customer['password_hash']);
        $this->assertNull(CustomerModel::findByValidResetToken($token));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor/bin/phpunit tests/Unit/Models/CustomerModelTest.php`
Expected: FAIL — `Class "App\Models\CustomerModel" not found` (or similar autoload/class-not-found error).

- [ ] **Step 3: Write `CustomerModel`**

Create `src/Models/CustomerModel.php`:

```php
<?php
namespace App\Models;

class CustomerModel
{
    public static function findByEmail(string $email): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(string $email, string $passwordHash): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO customers (email, password_hash) VALUES (?, ?)');
        $stmt->execute([$email, $passwordHash]);
        return (int) $pdo->lastInsertId();
    }

    public static function setResetToken(int $id, string $token, string $expiresAt): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE customers SET reset_token = ?, reset_token_expires = ? WHERE id = ?');
        $stmt->execute([$token, $expiresAt, $id]);
    }

    public static function findByValidResetToken(string $token): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE reset_token = ? AND reset_token_expires > NOW()');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function updatePasswordAndClearToken(int $id, string $passwordHash): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE customers SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?');
        $stmt->execute([$passwordHash, $id]);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php vendor/bin/phpunit tests/Unit/Models/CustomerModelTest.php --testdox`
Expected: `OK (8 tests, ...)`, all green.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Models/CustomerModelTest.php src/Models/CustomerModel.php
git commit -m "feat: add CustomerModel with reset-token support"
```

---

### Task 3: Register / Login / Logout / Account page

**Files:**
- Create: `src/Controllers/AccountController.php`
- Modify: `src/routes.php`
- Modify: `src/Controllers/BaseController.php`
- Modify: `templates/layout/base.twig`
- Modify: `www/assets/css/style.css`
- Create: `templates/public/register.twig`
- Create: `templates/public/login.twig`
- Create: `templates/public/account.twig`
- Modify: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`

**Interfaces:**
- Consumes: `CustomerModel::findByEmail`, `CustomerModel::create`, `CustomerModel::findById` (Task 2); `BaseController::render()`.
- Produces: `$_SESSION['customer'] = ['id' => int, 'email' => string]` — the session shape every later account-related check relies on (Task 4's `forgotSubmit`/`resetSubmit` don't touch it, but any future account feature would read this same key/shape).

- [ ] **Step 1: Add translation keys to all five language files**

Add these keys to `lang/cs.json` (insert alphabetically — `account.*` keys go right after the closing of `checkout.*`/before `compare.*`... simplest: add them wherever alphabetical order places `account.*`, i.e. as the **first** keys in the file since `account` < `cart` alphabetically). Edit `lang/cs.json`, changing the opening of the object from:

```json
{
  "cart.checkout": "Přejít k pokladně",
```

to:

```json
{
  "account.email": "E-mail",
  "account.error_email_taken": "Tento e-mail je již zaregistrován.",
  "account.error_login": "Nesprávný e-mail nebo heslo.",
  "account.error_password": "Heslo musí mít alespoň 8 znaků a obě hesla se musí shodovat.",
  "account.logout": "Odhlásit se",
  "account.login_submit": "Přihlásit se",
  "account.login_title": "Přihlášení",
  "account.member_since": "Registrace od {date}",
  "account.password": "Heslo",
  "account.password_confirm": "Heslo znovu",
  "account.register_submit": "Zaregistrovat se",
  "account.register_title": "Registrace",
  "account.title": "Můj účet",
  "cart.checkout": "Přejít k pokladně",
```

Then add the four nav keys in their alphabetical place among the existing `nav.*` keys — edit:

```json
  "nav.cart": "Košík",
  "nav.compare": "Porovnání",
  "nav.contact": "Kontakt",
  "nav.gallery": "Naše realizace",
  "nav.home": "Domů",
  "nav.info": "Info",
  "nav.services": "Služby",
```

to:

```json
  "nav.account": "Můj účet",
  "nav.cart": "Košík",
  "nav.compare": "Porovnání",
  "nav.contact": "Kontakt",
  "nav.gallery": "Naše realizace",
  "nav.home": "Domů",
  "nav.info": "Info",
  "nav.login": "Přihlásit se",
  "nav.logout": "Odhlásit se",
  "nav.register": "Registrace",
  "nav.services": "Služby",
```

Repeat the same two edits for the other four files with these translations:

**`lang/en.json`** — account.* block (inserted before `"cart.checkout": "Proceed to checkout",`):
```json
  "account.email": "Email",
  "account.error_email_taken": "This email is already registered.",
  "account.error_login": "Incorrect email or password.",
  "account.error_password": "Password must be at least 8 characters and both passwords must match.",
  "account.logout": "Log out",
  "account.login_submit": "Log in",
  "account.login_title": "Log In",
  "account.member_since": "Member since {date}",
  "account.password": "Password",
  "account.password_confirm": "Confirm password",
  "account.register_submit": "Register",
  "account.register_title": "Register",
  "account.title": "My Account",
```
nav.* additions (alphabetical, among existing `nav.*` block):
```json
  "nav.account": "My Account",
  "nav.login": "Log in",
  "nav.logout": "Log out",
  "nav.register": "Register",
```

**`lang/sk.json`** — account.* block:
```json
  "account.email": "E-mail",
  "account.error_email_taken": "Tento e-mail je už zaregistrovaný.",
  "account.error_login": "Nesprávny e-mail alebo heslo.",
  "account.error_password": "Heslo musí mať aspoň 8 znakov a obe heslá sa musia zhodovať.",
  "account.logout": "Odhlásiť sa",
  "account.login_submit": "Prihlásiť sa",
  "account.login_title": "Prihlásenie",
  "account.member_since": "Registrácia od {date}",
  "account.password": "Heslo",
  "account.password_confirm": "Heslo znova",
  "account.register_submit": "Zaregistrovať sa",
  "account.register_title": "Registrácia",
  "account.title": "Môj účet",
```
nav.* additions:
```json
  "nav.account": "Môj účet",
  "nav.login": "Prihlásiť sa",
  "nav.logout": "Odhlásiť sa",
  "nav.register": "Registrácia",
```

**`lang/ru.json`** — account.* block:
```json
  "account.email": "Эл. почта",
  "account.error_email_taken": "Этот адрес электронной почты уже зарегистрирован.",
  "account.error_login": "Неверный адрес электронной почты или пароль.",
  "account.error_password": "Пароль должен содержать не менее 8 символов, и пароли должны совпадать.",
  "account.logout": "Выйти",
  "account.login_submit": "Войти",
  "account.login_title": "Вход",
  "account.member_since": "Регистрация с {date}",
  "account.password": "Пароль",
  "account.password_confirm": "Повторите пароль",
  "account.register_submit": "Зарегистрироваться",
  "account.register_title": "Регистрация",
  "account.title": "Мой аккаунт",
```
nav.* additions:
```json
  "nav.account": "Мой аккаунт",
  "nav.login": "Войти",
  "nav.logout": "Выйти",
  "nav.register": "Регистрация",
```

**`lang/uk.json`** — account.* block:
```json
  "account.email": "Ел. пошта",
  "account.error_email_taken": "Ця електронна адреса вже зареєстрована.",
  "account.error_login": "Невірна електронна адреса або пароль.",
  "account.error_password": "Пароль має містити щонайменше 8 символів, і паролі мають збігатися.",
  "account.logout": "Вийти",
  "account.login_submit": "Увійти",
  "account.login_title": "Вхід",
  "account.member_since": "Реєстрація з {date}",
  "account.password": "Пароль",
  "account.password_confirm": "Повторіть пароль",
  "account.register_submit": "Зареєструватися",
  "account.register_title": "Реєстрація",
  "account.title": "Мій акаунт",
```
nav.* additions:
```json
  "nav.account": "Мій акаунт",
  "nav.login": "Увійти",
  "nav.logout": "Вийти",
  "nav.register": "Реєстрація",
```

After editing, verify all five files still parse and have identical key sets:

Run:
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
Expected: five `bool(true)` lines, then `done` with no `MISMATCH` lines.

- [ ] **Step 2: Create `AccountController`**

Create `src/Controllers/AccountController.php`:

```php
<?php
namespace App\Controllers;

use App\Models\CustomerModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AccountController extends BaseController
{
    public function registerForm(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $lang = $request->getAttribute('lang');
        if (!empty($_SESSION['customer'])) {
            return $response->withHeader('Location', "/{$lang}/account")->withStatus(302);
        }
        return $this->render($request, $response, 'public/register.twig');
    }

    public function registerSubmit(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $lang            = $request->getAttribute('lang');
        $body            = (array) $request->getParsedBody();
        $email           = trim($body['email'] ?? '');
        $password        = $body['password'] ?? '';
        $passwordConfirm = $body['password_confirm'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || $password !== $passwordConfirm) {
            return $this->render($request, $response, 'public/register.twig', [
                'error' => 'account.error_password',
                'email' => $email,
            ]);
        }

        if (CustomerModel::findByEmail($email)) {
            return $this->render($request, $response, 'public/register.twig', [
                'error' => 'account.error_email_taken',
                'email' => $email,
            ]);
        }

        $customerId = CustomerModel::create($email, password_hash($password, PASSWORD_BCRYPT));
        $_SESSION['customer'] = ['id' => $customerId, 'email' => $email];

        return $response->withHeader('Location', "/{$lang}/account")->withStatus(302);
    }

    public function loginForm(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $lang = $request->getAttribute('lang');
        if (!empty($_SESSION['customer'])) {
            return $response->withHeader('Location', "/{$lang}/account")->withStatus(302);
        }
        return $this->render($request, $response, 'public/login.twig');
    }

    public function loginSubmit(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $lang     = $request->getAttribute('lang');
        $body     = (array) $request->getParsedBody();
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        $customer = CustomerModel::findByEmail($email);
        if (!$customer || !password_verify($password, $customer['password_hash'])) {
            return $this->render($request, $response, 'public/login.twig', [
                'error' => 'account.error_login',
                'email' => $email,
            ]);
        }

        $_SESSION['customer'] = ['id' => (int) $customer['id'], 'email' => $customer['email']];
        return $response->withHeader('Location', "/{$lang}/account")->withStatus(302);
    }

    public function logout(Request $request, Response $response, array $args): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $lang = $request->getAttribute('lang');
        unset($_SESSION['customer']);
        return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
    }

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
}
```

- [ ] **Step 3: Register routes**

In `src/routes.php`, add the import. Change:

```php
use App\Controllers\CartController;
```

to:

```php
use App\Controllers\AccountController;
use App\Controllers\CartController;
```

Then add the routes right after the contact routes and before the cart routes. Change:

```php
$app->get('/{lang}/contact',          ContactController::class . ':index');
$app->post('/{lang}/contact',         ContactController::class . ':send');
$app->get('/{lang}/cart',             CartController::class    . ':index');
```

to:

```php
$app->get('/{lang}/contact',          ContactController::class . ':index');
$app->post('/{lang}/contact',         ContactController::class . ':send');
$app->get('/{lang}/register',         AccountController::class . ':registerForm');
$app->post('/{lang}/register',        AccountController::class . ':registerSubmit');
$app->get('/{lang}/login',            AccountController::class . ':loginForm');
$app->post('/{lang}/login',           AccountController::class . ':loginSubmit');
$app->get('/{lang}/logout',           AccountController::class . ':logout');
$app->get('/{lang}/account',          AccountController::class . ':index');
$app->get('/{lang}/cart',             CartController::class    . ':index');
```

- [ ] **Step 4: Inject `customer` into every public template**

In `src/Controllers/BaseController.php`, change:

```php
            'flash'                => $this->getFlash(),
            'compare_count'        => Compare::count(),
        ], $data));
```

to:

```php
            'flash'                => $this->getFlash(),
            'compare_count'        => Compare::count(),
            'customer'             => $_SESSION['customer'] ?? null,
        ], $data));
```

- [ ] **Step 5: Add account links to the nav**

In `templates/layout/base.twig`, change:

```twig
            <a href="/{{ lang }}/cart" class="cart-link">{{ t('nav.cart') }}</a>
            <div class="lang-switcher">
```

to:

```twig
            <a href="/{{ lang }}/cart" class="cart-link">{{ t('nav.cart') }}</a>
            {% if customer %}
            <a href="/{{ lang }}/account" class="account-link">{{ t('nav.account') }}</a>
            <a href="/{{ lang }}/logout" class="account-link">{{ t('nav.logout') }}</a>
            {% else %}
            <a href="/{{ lang }}/login" class="account-link">{{ t('nav.login') }}</a>
            <a href="/{{ lang }}/register" class="account-link">{{ t('nav.register') }}</a>
            {% endif %}
            <div class="lang-switcher">
```

- [ ] **Step 6: Style the new nav links**

In `www/assets/css/style.css`, change:

```css
.cart-link, .wishlist-link, .compare-link { order: 3; color: var(--text); text-decoration: none; font-family: var(--ui-font); font-size: .9rem; letter-spacing: .03em; }
.cart-link:hover, .wishlist-link:hover, .compare-link:hover { color: var(--accent); }
```

to:

```css
.cart-link, .wishlist-link, .compare-link, .account-link { order: 3; color: var(--text); text-decoration: none; font-family: var(--ui-font); font-size: .9rem; letter-spacing: .03em; }
.cart-link:hover, .wishlist-link:hover, .compare-link:hover, .account-link:hover { color: var(--accent); }
```

And change (inside the `@media (max-width: 768px)` block):

```css
    .cart-link, .wishlist-link, .compare-link { order: 2; margin-left: auto; }
```

to:

```css
    .cart-link, .wishlist-link, .compare-link, .account-link { order: 2; margin-left: auto; }
```

- [ ] **Step 7: Create `register.twig`**

Create `templates/public/register.twig`:

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('account.register_title') }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{% endblock %}
{% block head %}<meta name="robots" content="noindex,nofollow">{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container"><h1>{{ t('account.register_title') }}</h1></div>
</section>
<div class="container contact-layout">
    <div class="contact-form-wrap">
        {% if error %}
        <p class="form-error">{{ t(error) }}</p>
        {% endif %}
        <form action="/{{ lang }}/register" method="POST" class="contact-form">
            <div class="form-group">
                <label for="email">{{ t('account.email') }}</label>
                <input type="email" id="email" name="email" required value="{{ email ?? '' }}">
            </div>
            <div class="form-group">
                <label for="password">{{ t('account.password') }}</label>
                <input type="password" id="password" name="password" required minlength="8">
            </div>
            <div class="form-group">
                <label for="password_confirm">{{ t('account.password_confirm') }}</label>
                <input type="password" id="password_confirm" name="password_confirm" required minlength="8">
            </div>
            <button type="submit" class="btn btn-primary">{{ t('account.register_submit') }}</button>
        </form>
    </div>
</div>
{% endblock %}
```

- [ ] **Step 8: Create `login.twig`**

Create `templates/public/login.twig`:

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('account.login_title') }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{% endblock %}
{% block head %}<meta name="robots" content="noindex,nofollow">{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container"><h1>{{ t('account.login_title') }}</h1></div>
</section>
<div class="container contact-layout">
    <div class="contact-form-wrap">
        {% if error %}
        <p class="form-error">{{ t(error) }}</p>
        {% endif %}
        <form action="/{{ lang }}/login" method="POST" class="contact-form">
            <div class="form-group">
                <label for="email">{{ t('account.email') }}</label>
                <input type="email" id="email" name="email" required value="{{ email ?? '' }}">
            </div>
            <div class="form-group">
                <label for="password">{{ t('account.password') }}</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary">{{ t('account.login_submit') }}</button>
        </form>
        <p><a href="/{{ lang }}/forgot-password">{{ t('account.forgot_link') }}</a></p>
    </div>
</div>
{% endblock %}
```

Note: `account.forgot_link` is added in Task 4 — the link will render as the literal key text until then, which is expected and fixed by the next task.

- [ ] **Step 9: Create `account.twig`**

Create `templates/public/account.twig`:

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('account.title') }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{% endblock %}
{% block head %}<meta name="robots" content="noindex,nofollow">{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container"><h1>{{ t('account.title') }}</h1></div>
</section>
<div class="container contact-layout">
    <div class="contact-form-wrap">
        <p>{{ account.email }}</p>
        <p>{{ t('account.member_since', {date: account.created_at|date('d.m.Y')}) }}</p>
        <a href="/{{ lang }}/logout" class="btn btn-outline">{{ t('account.logout') }}</a>
    </div>
</div>
{% endblock %}
```

- [ ] **Step 10: Manual smoke test**

Ensure the local server is running with the migration applied (Task 1, Step 3), then:

```bash
curl -s -c /tmp/cookies.txt -o /dev/null -w "register form: %{http_code}\n" http://localhost:8080/cs/register
EMAIL="smoke-$(date +%s)@example.com"
curl -s -b /tmp/cookies.txt -c /tmp/cookies.txt -o /dev/null -w "register submit: %{http_code}\n" \
  -d "email=${EMAIL}&password=testpass123&password_confirm=testpass123" \
  http://localhost:8080/cs/register
curl -s -b /tmp/cookies.txt -o /dev/null -w "account (should be 200, logged in): %{http_code}\n" http://localhost:8080/cs/account
curl -s -b /tmp/cookies.txt -c /tmp/cookies.txt -o /dev/null -w "logout: %{http_code}\n" -L http://localhost:8080/cs/logout
curl -s -b /tmp/cookies.txt -o /dev/null -w "account after logout (should redirect, curl -L not used so 302): %{http_code}\n" http://localhost:8080/cs/account
curl -s -b /tmp/cookies.txt -c /tmp/cookies.txt -o /dev/null -w "login submit: %{http_code}\n" \
  -d "email=${EMAIL}&password=testpass123" \
  http://localhost:8080/cs/login
curl -s -b /tmp/cookies.txt -o /dev/null -w "account after login: %{http_code}\n" http://localhost:8080/cs/account
```

Expected: `register form: 200`, `register submit: 302`, `account (should be 200, logged in): 200`, `logout: 200` (following the redirect with `-L`), `account after logout: 302`, `login submit: 302`, `account after login: 200`.

- [ ] **Step 11: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests pass (no regressions from the `BaseController`/routes changes).

- [ ] **Step 12: Commit**

```bash
git add src/Controllers/AccountController.php src/routes.php src/Controllers/BaseController.php \
  templates/layout/base.twig www/assets/css/style.css \
  templates/public/register.twig templates/public/login.twig templates/public/account.twig \
  lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json
git commit -m "feat: add public register/login/logout/account flow"
```

---

### Task 4: Forgot / Reset password

**Files:**
- Modify: `src/Controllers/AccountController.php`
- Modify: `src/routes.php`
- Create: `templates/public/forgot-password.twig`
- Create: `templates/public/reset-password.twig`
- Modify: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`

**Interfaces:**
- Consumes: `CustomerModel::findByEmail`, `CustomerModel::setResetToken`, `CustomerModel::findByValidResetToken`, `CustomerModel::updatePasswordAndClearToken` (Task 2); `Seo::canonicalUrl(string $lang, string $path): string` (existing); `Mailer::send(string $to, string $subject, string $body, string $replyTo = ''): bool` (existing).
- Produces: nothing consumed by a later task — this is the last piece of the flow.

- [ ] **Step 1: Add translation keys to all five language files**

Add these keys into the existing `account.*` block (alphabetical order) in each file.

**`lang/cs.json`** — insert among the `account.*` keys added in Task 3:
```json
  "account.error_reset_token": "Odkaz pro obnovení hesla je neplatný nebo vypršel.",
  "account.forgot_link": "Zapomenuté heslo?",
  "account.forgot_submit": "Odeslat odkaz pro obnovení",
  "account.forgot_success": "Pokud e-mail existuje, odeslali jsme na něj odkaz pro obnovení hesla.",
  "account.forgot_title": "Zapomenuté heslo",
  "account.new_password": "Nové heslo",
  "account.reset_submit": "Nastavit heslo",
  "account.reset_success": "Heslo bylo úspěšně změněno. Nyní se můžete přihlásit.",
  "account.reset_title": "Nastavit nové heslo",
```

**`lang/en.json`**:
```json
  "account.error_reset_token": "This password reset link is invalid or has expired.",
  "account.forgot_link": "Forgot password?",
  "account.forgot_submit": "Send reset link",
  "account.forgot_success": "If that email is registered, we've sent a password reset link to it.",
  "account.forgot_title": "Forgot Password",
  "account.new_password": "New password",
  "account.reset_submit": "Set password",
  "account.reset_success": "Your password has been changed. You can now log in.",
  "account.reset_title": "Set New Password",
```

**`lang/sk.json`**:
```json
  "account.error_reset_token": "Odkaz na obnovenie hesla je neplatný alebo vypršal.",
  "account.forgot_link": "Zabudnuté heslo?",
  "account.forgot_submit": "Odoslať odkaz na obnovenie",
  "account.forgot_success": "Ak tento e-mail existuje, poslali sme naň odkaz na obnovenie hesla.",
  "account.forgot_title": "Zabudnuté heslo",
  "account.new_password": "Nové heslo",
  "account.reset_submit": "Nastaviť heslo",
  "account.reset_success": "Heslo bolo úspešne zmenené. Teraz sa môžete prihlásiť.",
  "account.reset_title": "Nastaviť nové heslo",
```

**`lang/ru.json`**:
```json
  "account.error_reset_token": "Ссылка для сброса пароля недействительна или истекла.",
  "account.forgot_link": "Забыли пароль?",
  "account.forgot_submit": "Отправить ссылку для сброса",
  "account.forgot_success": "Если этот адрес электронной почты зарегистрирован, мы отправили на него ссылку для сброса пароля.",
  "account.forgot_title": "Забыли пароль",
  "account.new_password": "Новый пароль",
  "account.reset_submit": "Установить пароль",
  "account.reset_success": "Ваш пароль был успешно изменён. Теперь вы можете войти.",
  "account.reset_title": "Установить новый пароль",
```

**`lang/uk.json`**:
```json
  "account.error_reset_token": "Посилання для скидання пароля недійсне або застаріле.",
  "account.forgot_link": "Забули пароль?",
  "account.forgot_submit": "Надіслати посилання для скидання",
  "account.forgot_success": "Якщо ця електронна адреса зареєстрована, ми надіслали на неї посилання для скидання пароля.",
  "account.forgot_title": "Забули пароль",
  "account.new_password": "Новий пароль",
  "account.reset_submit": "Встановити пароль",
  "account.reset_success": "Ваш пароль успішно змінено. Тепер ви можете увійти.",
  "account.reset_title": "Встановити новий пароль",
```

Verify again with the same script from Task 3 Step 1:
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

- [ ] **Step 2: Add `forgotForm`/`forgotSubmit`/`resetForm`/`resetSubmit` to `AccountController`**

In `src/Controllers/AccountController.php`, add the `use` statements. Change:

```php
use App\Models\CustomerModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
```

to:

```php
use App\Models\CustomerModel;
use App\Services\Mailer;
use App\Services\Seo;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
```

Then add the four new methods before the final closing `}` of the class. Change:

```php
        return $this->render($request, $response, 'public/account.twig', [
            'account' => $customer,
        ]);
    }
}
```

to:

```php
        return $this->render($request, $response, 'public/account.twig', [
            'account' => $customer,
        ]);
    }

    public function forgotForm(Request $request, Response $response, array $args): Response
    {
        return $this->render($request, $response, 'public/forgot-password.twig');
    }

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

    public function resetForm(Request $request, Response $response, array $args): Response
    {
        $token    = (string) ($request->getQueryParams()['token'] ?? '');
        $customer = $token !== '' ? CustomerModel::findByValidResetToken($token) : null;

        if (!$customer) {
            return $this->render($request, $response, 'public/reset-password.twig', [
                'error' => 'account.error_reset_token',
            ]);
        }

        return $this->render($request, $response, 'public/reset-password.twig', [
            'token' => $token,
        ]);
    }

    public function resetSubmit(Request $request, Response $response, array $args): Response
    {
        $lang            = $request->getAttribute('lang');
        $body            = (array) $request->getParsedBody();
        $token           = trim($body['token'] ?? '');
        $password        = $body['password'] ?? '';
        $passwordConfirm = $body['password_confirm'] ?? '';

        $customer = $token !== '' ? CustomerModel::findByValidResetToken($token) : null;
        if (!$customer) {
            return $this->render($request, $response, 'public/reset-password.twig', [
                'error' => 'account.error_reset_token',
            ]);
        }

        if (strlen($password) < 8 || $password !== $passwordConfirm) {
            return $this->render($request, $response, 'public/reset-password.twig', [
                'error' => 'account.error_password',
                'token' => $token,
            ]);
        }

        CustomerModel::updatePasswordAndClearToken((int) $customer['id'], password_hash($password, PASSWORD_BCRYPT));
        $this->flash('success', 'account.reset_success');
        return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
    }
}
```

- [ ] **Step 3: Register the new routes**

In `src/routes.php`, change:

```php
$app->get('/{lang}/logout',           AccountController::class . ':logout');
$app->get('/{lang}/account',          AccountController::class . ':index');
$app->get('/{lang}/cart',             CartController::class    . ':index');
```

to:

```php
$app->get('/{lang}/logout',           AccountController::class . ':logout');
$app->get('/{lang}/account',          AccountController::class . ':index');
$app->get('/{lang}/forgot-password',  AccountController::class . ':forgotForm');
$app->post('/{lang}/forgot-password', AccountController::class . ':forgotSubmit');
$app->get('/{lang}/reset-password',   AccountController::class . ':resetForm');
$app->post('/{lang}/reset-password',  AccountController::class . ':resetSubmit');
$app->get('/{lang}/cart',             CartController::class    . ':index');
```

- [ ] **Step 4: Create `forgot-password.twig`**

Create `templates/public/forgot-password.twig`:

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('account.forgot_title') }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{% endblock %}
{% block head %}<meta name="robots" content="noindex,nofollow">{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container"><h1>{{ t('account.forgot_title') }}</h1></div>
</section>
<div class="container contact-layout">
    <div class="contact-form-wrap">
        {% if success %}
        <p class="form-success">{{ t('account.forgot_success') }}</p>
        {% else %}
        <form action="/{{ lang }}/forgot-password" method="POST" class="contact-form">
            <div class="form-group">
                <label for="email">{{ t('account.email') }}</label>
                <input type="email" id="email" name="email" required value="{{ email ?? '' }}">
            </div>
            <button type="submit" class="btn btn-primary">{{ t('account.forgot_submit') }}</button>
        </form>
        {% endif %}
    </div>
</div>
{% endblock %}
```

- [ ] **Step 5: Create `reset-password.twig`**

Create `templates/public/reset-password.twig`:

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('account.reset_title') }} — {{ t('site.name') }}{% endblock %}
{% block meta_desc %}{% endblock %}
{% block head %}<meta name="robots" content="noindex,nofollow">{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container"><h1>{{ t('account.reset_title') }}</h1></div>
</section>
<div class="container contact-layout">
    <div class="contact-form-wrap">
        {% if error and not token %}
        <p class="form-error">{{ t(error) }}</p>
        <p><a href="/{{ lang }}/forgot-password">{{ t('account.forgot_title') }}</a></p>
        {% else %}
            {% if error %}
            <p class="form-error">{{ t(error) }}</p>
            {% endif %}
            <form action="/{{ lang }}/reset-password" method="POST" class="contact-form">
                <input type="hidden" name="token" value="{{ token }}">
                <div class="form-group">
                    <label for="password">{{ t('account.new_password') }}</label>
                    <input type="password" id="password" name="password" required minlength="8">
                </div>
                <div class="form-group">
                    <label for="password_confirm">{{ t('account.password_confirm') }}</label>
                    <input type="password" id="password_confirm" name="password_confirm" required minlength="8">
                </div>
                <button type="submit" class="btn btn-primary">{{ t('account.reset_submit') }}</button>
            </form>
        {% endif %}
    </div>
</div>
{% endblock %}
```

- [ ] **Step 6: Manual smoke test of the full reset flow**

With the local server running (from Task 1/3):

```bash
rm -f tmp/mail.log
EMAIL="reset-smoke-$(date +%s)@example.com"
curl -s -c /tmp/cookies2.txt -o /dev/null http://localhost:8080/cs/register
curl -s -b /tmp/cookies2.txt -c /tmp/cookies2.txt -o /dev/null \
  -d "email=${EMAIL}&password=oldpassword&password_confirm=oldpassword" \
  http://localhost:8080/cs/register
curl -s -b /tmp/cookies2.txt -c /tmp/cookies2.txt -o /dev/null http://localhost:8080/cs/logout

curl -s -o /dev/null -w "forgot form: %{http_code}\n" http://localhost:8080/cs/forgot-password
curl -s -o /dev/null -w "forgot submit: %{http_code}\n" -d "email=${EMAIL}" http://localhost:8080/cs/forgot-password

TOKEN=$(grep -o 'token=[a-f0-9]\{64\}' tmp/mail.log | tail -1 | cut -d= -f2)
echo "token: ${TOKEN}"

curl -s -o /dev/null -w "reset form (valid token): %{http_code}\n" "http://localhost:8080/cs/reset-password?token=${TOKEN}"
curl -s -o /dev/null -w "reset form (bad token): %{http_code}\n" "http://localhost:8080/cs/reset-password?token=deadbeef"

curl -s -c /tmp/cookies3.txt -o /dev/null -w "reset submit: %{http_code}\n" \
  -d "token=${TOKEN}&password=newpassword&password_confirm=newpassword" \
  http://localhost:8080/cs/reset-password

curl -s -b /tmp/cookies3.txt -c /tmp/cookies3.txt -o /dev/null -w "login with new password: %{http_code}\n" \
  -d "email=${EMAIL}&password=newpassword" \
  http://localhost:8080/cs/login
curl -s -b /tmp/cookies3.txt -o /dev/null -w "account after re-login: %{http_code}\n" http://localhost:8080/cs/account
```

Expected: `forgot form: 200`, `forgot submit: 200`, a 64-character hex `token`, `reset form (valid token): 200`, `reset form (bad token): 200` (the page renders 200 with an inline error, not an HTTP error status — see spec's "Error handling" section), `reset submit: 302`, `login with new password: 302`, `account after re-login: 200`.

- [ ] **Step 7: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests pass, no regressions.

- [ ] **Step 8: Commit**

```bash
git add src/Controllers/AccountController.php src/routes.php \
  templates/public/forgot-password.twig templates/public/reset-password.twig \
  lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json
git commit -m "feat: add forgot/reset password flow"
```

---

### Task 5: Cross-language and visual verification

**Files:** none (verification only, per `.claude/rules/unit-testing.md` — Twig templates/CSS are verified by running the app, not by unit tests).

- [ ] **Step 1: Verify all five languages render the new pages**

```bash
for l in cs en ru uk sk; do
  curl -s -o /dev/null -w "$l register: %{http_code}\n" http://localhost:8080/$l/register
  curl -s -o /dev/null -w "$l login:    %{http_code}\n" http://localhost:8080/$l/login
done
```
Expected: every line `200`.

- [ ] **Step 2: Browser check**

Open `http://localhost:8080/cs/` in a browser (or use a screenshot tool if available) and confirm:
- The header shows "Přihlásit se" / "Registrace" links when logged out.
- After registering, the header shows "Můj účet" / "Odhlásit se" instead.
- The account page shows the email and "Registrace od ..." date.
- The mobile layout (narrow viewport, ≤768px) still shows the account links without breaking the hamburger menu.

- [ ] **Step 3: Final full-suite run**

Run: `php vendor/bin/phpunit --testdox`
Expected: all green, matching the count from before this feature plus the 8 new `CustomerModelTest` tests.

No commit for this task — it's verification only.
