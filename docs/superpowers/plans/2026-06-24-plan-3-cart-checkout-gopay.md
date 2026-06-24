# BalonkyDecor — Plan 3: Cart, Checkout & GoPay

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the full purchase flow — session cart, checkout form, order creation, GoPay payment initiation/return/IPN, and order status page.

**Architecture:** A static `Cart` service manages `$_SESSION['cart']` and is shared by CartController, CheckoutController, and PaymentController. Orders are created in `pending` state at checkout submit, then transitioned to `paid` via GoPay IPN webhook. A minimal `GoPay` service makes raw cURL calls to the GoPay REST API; when credentials are not configured (local dev), payment is bypassed and the order is immediately marked paid. All controllers extend `BaseController` from Plan 2.

**Tech Stack:** PHP 8.1+ sessions, PDO/MySQL, GoPay REST API (cURL), Slim 4, Twig 3, PHPUnit 11

## Global Constraints

- PHP minimum: 8.1
- No new Composer packages (use native PHP cURL for GoPay, native sessions for cart)
- Cart data structure: `$_SESSION['cart'][sku] = ['qty' => int, 'name' => string, 'price' => string]`
- Order numbers: `BD-YYYYMMDD-NNNNN` (zero-padded DB id, 5 digits)
- Order statuses: `pending`, `paid`, `ready`, `completed`, `cancelled` (matches schema ENUM)
- GoPay sandbox: `https://gw.sandbox.gopay.com/api`; production: `https://gate.gopay.cz/api`
- If `gopay_go_id` setting is empty → dev bypass: order → paid immediately, redirect to order page
- Currency: CZK, amount in halíře (multiply Kč × 100, integer)
- Amounts in DB: `decimal(10,2)` in Kč; GoPay expects integer halíře
- All new routes added to `src/routes.php`

---

### Task 1: Cart Service

**Files:**
- Create: `src/Services/Cart.php`
- Create: `tests/Unit/Services/CartTest.php`
- Modify: `src/Controllers/CartController.php` — replace stub
- Modify: `src/routes.php` — add `POST /{lang}/cart/update`
- Create: `templates/public/cart.twig`

**Interfaces:**
- Produces:
  - `Cart::add(string $sku, int $qty, string $name, string $price): void`
  - `Cart::remove(string $sku): void`
  - `Cart::update(string $sku, int $qty): void`
  - `Cart::items(): array` — `[sku => ['qty','name','price','subtotal'], ...]`
  - `Cart::count(): int` — total item count (sum of qtys)
  - `Cart::total(): string` — formatted decimal string e.g. `"149.00"`
  - `Cart::clear(): void`

- [ ] **Step 1: Create `tests/Unit/Services/CartTest.php`**

```php
<?php
namespace Tests\Unit\Services;

use App\Services\Cart;
use PHPUnit\Framework\TestCase;

class CartTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['cart'] = [];
    }

    public function test_add_creates_item(): void
    {
        Cart::add('SKU-1', 2, 'Red Balloon', '49.00');
        $items = Cart::items();
        $this->assertArrayHasKey('SKU-1', $items);
        $this->assertSame(2, $items['SKU-1']['qty']);
    }

    public function test_add_accumulates_qty(): void
    {
        Cart::add('SKU-1', 1, 'Red Balloon', '49.00');
        Cart::add('SKU-1', 3, 'Red Balloon', '49.00');
        $this->assertSame(4, Cart::items()['SKU-1']['qty']);
    }

    public function test_remove_deletes_item(): void
    {
        Cart::add('SKU-1', 1, 'Red Balloon', '49.00');
        Cart::remove('SKU-1');
        $this->assertArrayNotHasKey('SKU-1', Cart::items());
    }

    public function test_update_changes_qty(): void
    {
        Cart::add('SKU-1', 1, 'Red Balloon', '49.00');
        Cart::update('SKU-1', 5);
        $this->assertSame(5, Cart::items()['SKU-1']['qty']);
    }

    public function test_update_zero_removes_item(): void
    {
        Cart::add('SKU-1', 1, 'Red Balloon', '49.00');
        Cart::update('SKU-1', 0);
        $this->assertArrayNotHasKey('SKU-1', Cart::items());
    }

    public function test_total_sums_all_items(): void
    {
        Cart::add('SKU-1', 2, 'Red Balloon', '49.00');
        Cart::add('SKU-2', 1, 'Blue Balloon', '79.00');
        $this->assertSame('177.00', Cart::total());
    }

    public function test_count_sums_quantities(): void
    {
        Cart::add('SKU-1', 3, 'Red Balloon', '49.00');
        Cart::add('SKU-2', 2, 'Blue Balloon', '79.00');
        $this->assertSame(5, Cart::count());
    }

    public function test_clear_empties_cart(): void
    {
        Cart::add('SKU-1', 1, 'Red Balloon', '49.00');
        Cart::clear();
        $this->assertEmpty(Cart::items());
    }

    public function test_items_includes_subtotal(): void
    {
        Cart::add('SKU-1', 3, 'Red Balloon', '49.00');
        $item = Cart::items()['SKU-1'];
        $this->assertSame('147.00', $item['subtotal']);
    }
}
```

- [ ] **Step 2: Run test to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Services/CartTest.php --testdox
```

Expected: FAIL — `App\Services\Cart` not found.

- [ ] **Step 3: Create `src/Services/Cart.php`**

```php
<?php
namespace App\Services;

class Cart
{
    private static function boot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
    }

    public static function add(string $sku, int $qty, string $name, string $price): void
    {
        self::boot();
        if (isset($_SESSION['cart'][$sku])) {
            $_SESSION['cart'][$sku]['qty'] += $qty;
        } else {
            $_SESSION['cart'][$sku] = ['qty' => $qty, 'name' => $name, 'price' => $price];
        }
    }

    public static function remove(string $sku): void
    {
        self::boot();
        unset($_SESSION['cart'][$sku]);
    }

    public static function update(string $sku, int $qty): void
    {
        self::boot();
        if ($qty <= 0) {
            unset($_SESSION['cart'][$sku]);
        } else {
            $_SESSION['cart'][$sku]['qty'] = $qty;
        }
    }

    public static function items(): array
    {
        self::boot();
        $items = [];
        foreach ($_SESSION['cart'] as $sku => $item) {
            $subtotal     = number_format((float) $item['price'] * $item['qty'], 2, '.', '');
            $items[$sku]  = array_merge($item, ['subtotal' => $subtotal]);
        }
        return $items;
    }

    public static function count(): int
    {
        self::boot();
        return array_sum(array_column($_SESSION['cart'], 'qty'));
    }

    public static function total(): string
    {
        $sum = 0.0;
        foreach ($_SESSION['cart'] ?? [] as $item) {
            $sum += (float) $item['price'] * $item['qty'];
        }
        return number_format($sum, 2, '.', '');
    }

    public static function clear(): void
    {
        self::boot();
        $_SESSION['cart'] = [];
    }

    public static function isEmpty(): bool
    {
        self::boot();
        return empty($_SESSION['cart']);
    }
}
```

- [ ] **Step 4: Run tests to confirm pass**

```bash
./vendor/bin/phpunit tests/Unit/Services/CartTest.php --testdox
```

Expected: All 9 tests pass.

- [ ] **Step 5: Replace `src/Controllers/CartController.php`**

```php
<?php
namespace App\Controllers;

use App\Models\ProductModel;
use App\Services\Cart;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CartController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        return $this->render($request, $response, 'public/cart.twig', [
            'items' => Cart::items(),
            'total' => Cart::total(),
        ]);
    }

    public function add(Request $request, Response $response, array $args): Response
    {
        $lang   = $request->getAttribute('lang');
        $body   = (array) $request->getParsedBody();
        $sku    = trim($body['sku'] ?? '');
        $qty    = max(1, (int) ($body['qty'] ?? 1));

        if ($sku) {
            $product = ProductModel::findBySku($sku, $lang);
            if ($product) {
                Cart::add($sku, $qty, $product['name'], (string) $product['price']);
            }
        }

        return $response
            ->withHeader('Location', "/{$lang}/cart")
            ->withStatus(302);
    }

    public function remove(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        $body = (array) $request->getParsedBody();
        $sku  = trim($body['sku'] ?? '');
        if ($sku) {
            Cart::remove($sku);
        }
        return $response
            ->withHeader('Location', "/{$lang}/cart")
            ->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $lang  = $request->getAttribute('lang');
        $body  = (array) $request->getParsedBody();
        $items = $body['items'] ?? [];
        foreach ($items as $sku => $qty) {
            Cart::update($sku, (int) $qty);
        }
        return $response
            ->withHeader('Location', "/{$lang}/cart")
            ->withStatus(302);
    }
}
```

- [ ] **Step 6: Add update route to `src/routes.php`**

After the existing `$app->post('/{lang}/cart/remove', ...)` line, add:

```php
$app->post('/{lang}/cart/update',     CartController::class    . ':update');
```

- [ ] **Step 7: Add cart translations to all 5 lang files**

Add to `lang/cs.json`:
```json
  "cart.title": "Košík",
  "cart.product": "Produkt",
  "cart.price": "Cena",
  "cart.qty": "Počet",
  "cart.subtotal": "Mezisoučet",
  "cart.remove": "Odebrat",
  "cart.update": "Aktualizovat",
  "cart.checkout": "Přejít k pokladně",
  "cart.continue": "Pokračovat v nákupu",
  "cart.empty_cta": "Přejít do obchodu"
```
Add to `lang/en.json`:
```json
  "cart.title": "Cart",
  "cart.product": "Product",
  "cart.price": "Price",
  "cart.qty": "Qty",
  "cart.subtotal": "Subtotal",
  "cart.remove": "Remove",
  "cart.update": "Update",
  "cart.checkout": "Proceed to checkout",
  "cart.continue": "Continue shopping",
  "cart.empty_cta": "Go to shop"
```
Add to `lang/ru.json`:
```json
  "cart.title": "Корзина",
  "cart.product": "Товар",
  "cart.price": "Цена",
  "cart.qty": "Кол-во",
  "cart.subtotal": "Сумма",
  "cart.remove": "Удалить",
  "cart.update": "Обновить",
  "cart.checkout": "Оформить заказ",
  "cart.continue": "Продолжить покупки",
  "cart.empty_cta": "Перейти в магазин"
```
Add to `lang/uk.json`:
```json
  "cart.title": "Кошик",
  "cart.product": "Товар",
  "cart.price": "Ціна",
  "cart.qty": "К-сть",
  "cart.subtotal": "Підсумок",
  "cart.remove": "Видалити",
  "cart.update": "Оновити",
  "cart.checkout": "Оформити замовлення",
  "cart.continue": "Продовжити покупки",
  "cart.empty_cta": "Перейти до магазину"
```
Add to `lang/sk.json`:
```json
  "cart.title": "Košík",
  "cart.product": "Produkt",
  "cart.price": "Cena",
  "cart.qty": "Počet",
  "cart.subtotal": "Medzisúčet",
  "cart.remove": "Odstrániť",
  "cart.update": "Aktualizovať",
  "cart.checkout": "Prejsť k pokladni",
  "cart.continue": "Pokračovať v nákupe",
  "cart.empty_cta": "Prejsť do obchodu"
```

- [ ] **Step 8: Create `templates/public/cart.twig`**

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('cart.title') }} — {{ t('site.name') }}{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container"><h1>{{ t('cart.title') }}</h1></div>
</section>
<div class="container cart-layout">
    {% if items %}
    <form action="/{{ lang }}/cart/update" method="POST" class="cart-form">
        <table class="cart-table">
            <thead>
                <tr>
                    <th>{{ t('cart.product') }}</th>
                    <th>{{ t('cart.price') }}</th>
                    <th>{{ t('cart.qty') }}</th>
                    <th>{{ t('cart.subtotal') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                {% for sku, item in items %}
                <tr>
                    <td class="cart-name">{{ item.name }}</td>
                    <td class="cart-price">{{ item.price|number_format(2, '.', ' ') }} Kč</td>
                    <td class="cart-qty">
                        <input type="number" name="items[{{ sku }}]"
                               value="{{ item.qty }}" min="0" class="qty-input">
                    </td>
                    <td class="cart-subtotal">{{ item.subtotal|number_format(2, '.', ' ') }} Kč</td>
                    <td class="cart-actions">
                        <button type="submit" name="items[{{ sku }}]" value="0"
                                class="btn-remove" title="{{ t('cart.remove') }}">×</button>
                    </td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
        <div class="cart-footer">
            <button type="submit" class="btn btn-outline">{{ t('cart.update') }}</button>
            <div class="cart-total-block">
                <span class="cart-total-label">{{ t('cart.total') }}</span>
                <span class="cart-total-amount">{{ total|number_format(2, '.', ' ') }} Kč</span>
                <a href="/{{ lang }}/checkout" class="btn btn-primary">{{ t('cart.checkout') }}</a>
            </div>
        </div>
    </form>
    {% else %}
    <div class="cart-empty">
        <p>{{ t('cart.empty') }}</p>
        <a href="/{{ lang }}/shop" class="btn btn-primary">{{ t('cart.empty_cta') }}</a>
    </div>
    {% endif %}
</div>
{% endblock %}
```

- [ ] **Step 9: Append cart CSS to `www/assets/css/style.css`**

```css
/* Cart */
.cart-layout { padding: 2.5rem 1.5rem; }
.cart-table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; font-family: var(--ui-font); }
.cart-table th { text-align: left; padding: .6rem .75rem; border-bottom: 2px solid var(--border); font-size: .85rem; color: var(--muted); font-weight: normal; }
.cart-table td { padding: .75rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
.cart-name { font-family: var(--font); }
.cart-price, .cart-subtotal { font-family: var(--ui-font); white-space: nowrap; }
.cart-subtotal { color: var(--accent); }
.btn-remove { background: none; border: none; color: var(--muted); font-size: 1.3rem; cursor: pointer; padding: 0 .25rem; line-height: 1; }
.btn-remove:hover { color: #c0392b; }
.cart-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; }
.cart-total-block { display: flex; align-items: center; gap: 1.5rem; }
.cart-total-label { font-family: var(--ui-font); color: var(--muted); }
.cart-total-amount { font-size: 1.4rem; color: var(--accent); font-family: var(--ui-font); }
.cart-empty { text-align: center; padding: 4rem 0; }
.cart-empty p { color: var(--muted); font-family: var(--ui-font); margin-bottom: 1.5rem; }
.btn-outline { background: none; border: 1px solid var(--border); color: var(--text); padding: .6rem 1.5rem; font-family: var(--ui-font); font-size: .9rem; cursor: pointer; border-radius: 2px; }
.btn-outline:hover { border-color: var(--accent); color: var(--accent); }
```

- [ ] **Step 10: Run full test suite**

```bash
./vendor/bin/phpunit --testdox
```

Expected: 32 tests (23 existing + 9 new Cart tests), all pass.

- [ ] **Step 11: Smoke test cart page**

```bash
php -S localhost:8080 -t www > /tmp/php_server.log 2>&1 & sleep 1 && \
curl -s -o /dev/null -w "%{http_code} /en/cart\n" http://localhost:8080/en/cart && \
kill %1 2>/dev/null; true
```

Expected: `200 /en/cart`

- [ ] **Step 12: Commit**

```bash
git add src/Services/Cart.php src/Controllers/CartController.php \
        src/routes.php templates/public/cart.twig \
        www/assets/css/style.css lang/ \
        tests/Unit/Services/CartTest.php
git commit -m "feat: session cart with view, add, remove, update"
```

---

### Task 2: Order Model + Checkout Flow

**Files:**
- Create: `src/Models/OrderModel.php`
- Create: `tests/Unit/Models/OrderModelTest.php`
- Modify: `src/Controllers/CheckoutController.php` — replace stub
- Modify: `src/Controllers/OrderController.php` — replace stub
- Create: `templates/public/checkout/index.twig`
- Create: `templates/public/checkout/confirm.twig`
- Create: `templates/public/order/status.twig`

**Interfaces:**
- Consumes: `Cart::items()`, `Cart::total()`, `Cart::clear()`
- Produces:
  - `OrderModel::create(array $customer, array $cartItems, string $total): string` — returns order_number
  - `OrderModel::findByNumber(string $number): ?array` — order row + `items` key
  - `OrderModel::updateStatus(string $number, string $status, ?string $gopayId = null): void`
  - `OrderModel::findByGopayId(string $gopayId): ?array`
- Checkout flow:
  1. GET `/{lang}/checkout` → show form (cart must not be empty)
  2. POST `/{lang}/checkout` → validate → `OrderModel::create()` → store `pending_order` in session → redirect to GET `/{lang}/checkout/confirm`
  3. GET `/{lang}/checkout/confirm` → show summary + "Pay Now" button (form POSTing to `/{lang}/payment/gopay`)

- [ ] **Step 1: Create `tests/Unit/Models/OrderModelTest.php`**

```php
<?php
namespace Tests\Unit\Models;

use App\Models\OrderModel;
use PHPUnit\Framework\TestCase;

class OrderModelTest extends TestCase
{
    private static string $orderNumber;

    public static function setUpBeforeClass(): void
    {
        self::$orderNumber = OrderModel::create(
            [
                'customer_name'  => 'Test User',
                'customer_email' => 'test@example.com',
                'customer_phone' => '+420123456789',
                'pickup_date'    => '2026-12-31',
                'notes'          => 'Test order',
            ],
            [
                'SKU-1' => ['qty' => 2, 'name' => 'Red Balloon', 'price' => '49.00', 'subtotal' => '98.00'],
            ],
            '98.00'
        );
    }

    public function test_create_returns_order_number(): void
    {
        $this->assertStringStartsWith('BD-', self::$orderNumber);
        $this->assertMatchesRegularExpression('/^BD-\d{8}-\d{5}$/', self::$orderNumber);
    }

    public function test_find_by_number_returns_order(): void
    {
        $order = OrderModel::findByNumber(self::$orderNumber);
        $this->assertNotNull($order);
        $this->assertSame('Test User', $order['customer_name']);
        $this->assertSame('pending', $order['status']);
        $this->assertArrayHasKey('items', $order);
        $this->assertCount(1, $order['items']);
    }

    public function test_update_status_changes_status(): void
    {
        OrderModel::updateStatus(self::$orderNumber, 'paid', 'GOPAY-123');
        $order = OrderModel::findByNumber(self::$orderNumber);
        $this->assertSame('paid', $order['status']);
        $this->assertSame('GOPAY-123', $order['gopay_payment_id']);
    }

    public function test_find_by_gopay_id(): void
    {
        $order = OrderModel::findByGopayId('GOPAY-123');
        $this->assertNotNull($order);
        $this->assertSame(self::$orderNumber, $order['order_number']);
    }

    public function test_find_by_number_returns_null_for_unknown(): void
    {
        $this->assertNull(OrderModel::findByNumber('BD-99999999-00000'));
    }
}
```

- [ ] **Step 2: Run test to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Models/OrderModelTest.php --testdox
```

Expected: FAIL — `App\Models\OrderModel` not found.

- [ ] **Step 3: Create `src/Models/OrderModel.php`**

```php
<?php
namespace App\Models;

class OrderModel
{
    public static function create(array $customer, array $cartItems, string $total): string
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        // Insert with temporary order_number; update after we have the id
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
        $id          = (int) $pdo->lastInsertId();
        $orderNumber = 'BD-' . date('Ymd') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);

        $pdo->prepare('UPDATE orders SET order_number = ? WHERE id = ?')
            ->execute([$orderNumber, $id]);

        $itemStmt = $pdo->prepare('
            INSERT INTO order_items (order_id, product_id, quantity, unit_price, product_name_snapshot)
            VALUES (?, (SELECT id FROM products WHERE sku = ? LIMIT 1), ?, ?, ?)
        ');
        foreach ($cartItems as $sku => $item) {
            $itemStmt->execute([$id, $sku, $item['qty'], $item['price'], $item['name']]);
        }

        $pdo->commit();
        return $orderNumber;
    }

    public static function findByNumber(string $number): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ?');
        $stmt->execute([$number]);
        $order = $stmt->fetch();
        if (!$order) {
            return null;
        }
        $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $items->execute([$order['id']]);
        $order['items'] = $items->fetchAll();
        return $order;
    }

    public static function updateStatus(string $number, string $status, ?string $gopayId = null): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            UPDATE orders SET status = ?, gopay_payment_id = COALESCE(?, gopay_payment_id)
            WHERE order_number = ?
        ');
        $stmt->execute([$status, $gopayId, $number]);
    }

    public static function findByGopayId(string $gopayId): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE gopay_payment_id = ?');
        $stmt->execute([$gopayId]);
        $order = $stmt->fetch();
        return $order ?: null;
    }
}
```

- [ ] **Step 4: Run tests to confirm pass**

```bash
./vendor/bin/phpunit tests/Unit/Models/OrderModelTest.php --testdox
```

Expected: All 5 tests pass.

- [ ] **Step 5: Add checkout/order translations to all 5 lang files**

Add to `lang/cs.json`:
```json
  "checkout.title": "Pokladna",
  "checkout.name": "Jméno a příjmení",
  "checkout.email": "E-mail",
  "checkout.phone": "Telefon",
  "checkout.notes": "Poznámka k objednávce",
  "checkout.submit": "Pokračovat k platbě",
  "checkout.cart_empty": "Váš košík je prázdný.",
  "checkout.error": "Prosím zkontrolujte vyplněné údaje.",
  "checkout.confirm_title": "Shrnutí objednávky",
  "checkout.pay_now": "Zaplatit",
  "checkout.order_number": "Číslo objednávky",
  "checkout.pickup": "Datum vyzvednutí",
  "order.title": "Objednávka",
  "order.items": "Položky",
  "order.product": "Produkt",
  "order.qty": "Počet",
  "order.unit_price": "Cena/ks",
  "order.total": "Celkem",
  "order.thank_you": "Děkujeme za Vaši objednávku!"
```

Add to `lang/en.json`:
```json
  "checkout.title": "Checkout",
  "checkout.name": "Full name",
  "checkout.email": "Email",
  "checkout.phone": "Phone",
  "checkout.notes": "Order notes",
  "checkout.submit": "Continue to payment",
  "checkout.cart_empty": "Your cart is empty.",
  "checkout.error": "Please check the details you entered.",
  "checkout.confirm_title": "Order summary",
  "checkout.pay_now": "Pay now",
  "checkout.order_number": "Order number",
  "checkout.pickup": "Pickup date",
  "order.title": "Order",
  "order.items": "Items",
  "order.product": "Product",
  "order.qty": "Qty",
  "order.unit_price": "Unit price",
  "order.total": "Total",
  "order.thank_you": "Thank you for your order!"
```

Add to `lang/ru.json`:
```json
  "checkout.title": "Оформление заказа",
  "checkout.name": "Имя и фамилия",
  "checkout.email": "E-mail",
  "checkout.phone": "Телефон",
  "checkout.notes": "Примечание к заказу",
  "checkout.submit": "Перейти к оплате",
  "checkout.cart_empty": "Ваша корзина пуста.",
  "checkout.error": "Пожалуйста, проверьте введённые данные.",
  "checkout.confirm_title": "Сводка заказа",
  "checkout.pay_now": "Оплатить",
  "checkout.order_number": "Номер заказа",
  "checkout.pickup": "Дата получения",
  "order.title": "Заказ",
  "order.items": "Позиции",
  "order.product": "Товар",
  "order.qty": "Кол-во",
  "order.unit_price": "Цена/шт",
  "order.total": "Итого",
  "order.thank_you": "Спасибо за ваш заказ!"
```

Add to `lang/uk.json`:
```json
  "checkout.title": "Оформлення замовлення",
  "checkout.name": "Ім'я та прізвище",
  "checkout.email": "E-mail",
  "checkout.phone": "Телефон",
  "checkout.notes": "Примітка до замовлення",
  "checkout.submit": "Перейти до оплати",
  "checkout.cart_empty": "Ваш кошик порожній.",
  "checkout.error": "Будь ласка, перевірте введені дані.",
  "checkout.confirm_title": "Підсумок замовлення",
  "checkout.pay_now": "Оплатити",
  "checkout.order_number": "Номер замовлення",
  "checkout.pickup": "Дата отримання",
  "order.title": "Замовлення",
  "order.items": "Позиції",
  "order.product": "Товар",
  "order.qty": "К-сть",
  "order.unit_price": "Ціна/шт",
  "order.total": "Разом",
  "order.thank_you": "Дякуємо за ваше замовлення!"
```

Add to `lang/sk.json`:
```json
  "checkout.title": "Pokladňa",
  "checkout.name": "Meno a priezvisko",
  "checkout.email": "E-mail",
  "checkout.phone": "Telefón",
  "checkout.notes": "Poznámka k objednávke",
  "checkout.submit": "Pokračovať k platbe",
  "checkout.cart_empty": "Váš košík je prázdny.",
  "checkout.error": "Prosím skontrolujte vyplnené údaje.",
  "checkout.confirm_title": "Prehľad objednávky",
  "checkout.pay_now": "Zaplatiť",
  "checkout.order_number": "Číslo objednávky",
  "checkout.pickup": "Dátum vyzdvihnutia",
  "order.title": "Objednávka",
  "order.items": "Položky",
  "order.product": "Produkt",
  "order.qty": "Počet",
  "order.unit_price": "Cena/ks",
  "order.total": "Spolu",
  "order.thank_you": "Ďakujeme za vašu objednávku!"
```

- [ ] **Step 6: Replace `src/Controllers/CheckoutController.php`**

```php
<?php
namespace App\Controllers;

use App\Models\OrderModel;
use App\Services\Cart;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CheckoutController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');

        if (Cart::isEmpty()) {
            return $response->withHeader('Location', "/{$lang}/cart")->withStatus(302);
        }

        return $this->render($request, $response, 'public/checkout/index.twig', [
            'items'  => Cart::items(),
            'total'  => Cart::total(),
            'error'  => false,
            'values' => [],
        ]);
    }

    public function submit(Request $request, Response $response, array $args): Response
    {
        $lang   = $request->getAttribute('lang');
        $body   = (array) $request->getParsedBody();
        $name   = trim($body['customer_name']  ?? '');
        $email  = trim($body['customer_email'] ?? '');
        $phone  = trim($body['customer_phone'] ?? '');
        $date   = trim($body['pickup_date']    ?? '');
        $notes  = trim($body['notes']          ?? '');

        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$phone) {
            return $this->render($request, $response, 'public/checkout/index.twig', [
                'items'  => Cart::items(),
                'total'  => Cart::total(),
                'error'  => true,
                'values' => $body,
            ]);
        }

        if (Cart::isEmpty()) {
            return $response->withHeader('Location', "/{$lang}/cart")->withStatus(302);
        }

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

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['pending_order'] = $orderNumber;
        Cart::clear();

        return $response->withHeader('Location', "/{$lang}/checkout/confirm")->withStatus(302);
    }

    public function confirm(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $orderNumber = $_SESSION['pending_order'] ?? null;
        if (!$orderNumber) {
            return $response->withHeader('Location', "/{$lang}/")->withStatus(302);
        }
        $order = OrderModel::findByNumber($orderNumber);
        if (!$order) {
            return $response->withHeader('Location', "/{$lang}/")->withStatus(302);
        }
        return $this->render($request, $response, 'public/checkout/confirm.twig', [
            'order' => $order,
        ]);
    }
}
```

- [ ] **Step 7: Replace `src/Controllers/OrderController.php`**

```php
<?php
namespace App\Controllers;

use App\Models\OrderModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OrderController extends BaseController
{
    public function status(Request $request, Response $response, array $args): Response
    {
        $lang  = $request->getAttribute('lang');
        $order = OrderModel::findByNumber($args['number']);
        if (!$order) {
            return $response->withStatus(404);
        }
        return $this->render($request, $response, 'public/order/status.twig', [
            'order' => $order,
        ]);
    }
}
```

- [ ] **Step 8: Create template directories**

```bash
mkdir -p templates/public/checkout templates/public/order
```

- [ ] **Step 9: Create `templates/public/checkout/index.twig`**

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('checkout.title') }} — {{ t('site.name') }}{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container"><h1>{{ t('checkout.title') }}</h1></div>
</section>
<div class="container checkout-layout">
    <div class="checkout-form-col">
        {% if error %}
        <p class="form-error">{{ t('checkout.error') }}</p>
        {% endif %}
        <form action="/{{ lang }}/checkout" method="POST" class="contact-form">
            <div class="form-group">
                <label>{{ t('checkout.name') }}</label>
                <input type="text" name="customer_name" required
                       value="{{ values.customer_name ?? '' }}">
            </div>
            <div class="form-group">
                <label>{{ t('checkout.email') }}</label>
                <input type="email" name="customer_email" required
                       value="{{ values.customer_email ?? '' }}">
            </div>
            <div class="form-group">
                <label>{{ t('checkout.phone') }}</label>
                <input type="tel" name="customer_phone" required
                       value="{{ values.customer_phone ?? '' }}">
            </div>
            <div class="form-group">
                <label>{{ t('checkout.pickup_date') }}</label>
                <input type="date" name="pickup_date"
                       value="{{ values.pickup_date ?? '' }}">
            </div>
            <div class="form-group">
                <label>{{ t('checkout.notes') }}</label>
                <textarea name="notes" rows="3">{{ values.notes ?? '' }}</textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-lg">{{ t('checkout.submit') }}</button>
        </form>
    </div>
    <aside class="checkout-summary">
        <table class="cart-table">
            <thead><tr><th>{{ t('cart.product') }}</th><th>{{ t('cart.qty') }}</th><th>{{ t('cart.subtotal') }}</th></tr></thead>
            <tbody>
                {% for sku, item in items %}
                <tr>
                    <td>{{ item.name }}</td>
                    <td>{{ item.qty }}</td>
                    <td>{{ item.subtotal|number_format(2, '.', ' ') }} Kč</td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
        <div class="summary-total">
            <span>{{ t('cart.total') }}</span>
            <strong>{{ total|number_format(2, '.', ' ') }} Kč</strong>
        </div>
    </aside>
</div>
{% endblock %}
```

- [ ] **Step 10: Create `templates/public/checkout/confirm.twig`**

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('checkout.confirm_title') }} — {{ t('site.name') }}{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container"><h1>{{ t('checkout.confirm_title') }}</h1></div>
</section>
<div class="container checkout-confirm">
    <div class="confirm-meta">
        <p><strong>{{ t('checkout.order_number') }}:</strong> {{ order.order_number }}</p>
        <p><strong>{{ t('checkout.pickup') }}:</strong> {{ order.pickup_date ?? '—' }}</p>
    </div>
    <table class="cart-table" style="margin: 1.5rem 0;">
        <thead><tr><th>{{ t('order.product') }}</th><th>{{ t('order.qty') }}</th><th>{{ t('order.unit_price') }}</th><th>{{ t('order.total') }}</th></tr></thead>
        <tbody>
            {% for item in order.items %}
            <tr>
                <td>{{ item.product_name_snapshot }}</td>
                <td>{{ item.quantity }}</td>
                <td>{{ item.unit_price|number_format(2, '.', ' ') }} Kč</td>
                <td>{{ (item.unit_price * item.quantity)|number_format(2, '.', ' ') }} Kč</td>
            </tr>
            {% endfor %}
        </tbody>
    </table>
    <div class="summary-total">
        <span>{{ t('cart.total') }}</span>
        <strong>{{ order.total_amount|number_format(2, '.', ' ') }} Kč</strong>
    </div>
    <form action="/{{ lang }}/payment/gopay" method="POST" style="margin-top: 2rem;">
        <input type="hidden" name="order_number" value="{{ order.order_number }}">
        <button type="submit" class="btn btn-primary btn-lg">{{ t('checkout.pay_now') }}</button>
    </form>
</div>
{% endblock %}
```

- [ ] **Step 11: Create `templates/public/order/status.twig`**

```twig
{% extends "layout/base.twig" %}

{% block title %}{{ t('order.title') }} {{ order.order_number }} — {{ t('site.name') }}{% endblock %}

{% block content %}
<section class="page-hero">
    <div class="container">
        <h1>{{ t('order.title') }} {{ order.order_number }}</h1>
    </div>
</section>
<div class="container checkout-confirm">
    {% if order.status == 'paid' or order.status == 'ready' or order.status == 'completed' %}
    <p class="form-success">{{ t('order.thank_you') }}</p>
    {% endif %}

    <div class="confirm-meta">
        <p>
            <strong>Status:</strong>
            <span class="order-status order-status--{{ order.status }}">
                {{ t('order.status.' ~ order.status) }}
            </span>
        </p>
        <p><strong>{{ t('checkout.pickup') }}:</strong> {{ order.pickup_date ?? '—' }}</p>
    </div>

    <table class="cart-table" style="margin: 1.5rem 0;">
        <thead><tr><th>{{ t('order.product') }}</th><th>{{ t('order.qty') }}</th><th>{{ t('order.unit_price') }}</th><th>{{ t('order.total') }}</th></tr></thead>
        <tbody>
            {% for item in order.items %}
            <tr>
                <td>{{ item.product_name_snapshot }}</td>
                <td>{{ item.quantity }}</td>
                <td>{{ item.unit_price|number_format(2, '.', ' ') }} Kč</td>
                <td>{{ (item.unit_price * item.quantity)|number_format(2, '.', ' ') }} Kč</td>
            </tr>
            {% endfor %}
        </tbody>
    </table>
    <div class="summary-total">
        <span>{{ t('cart.total') }}</span>
        <strong>{{ order.total_amount|number_format(2, '.', ' ') }} Kč</strong>
    </div>
</div>
{% endblock %}
```

- [ ] **Step 12: Append checkout/order CSS to `www/assets/css/style.css`**

```css
/* Checkout */
.checkout-layout { display: grid; grid-template-columns: 1fr 360px; gap: 3rem; padding: 2.5rem 1.5rem; }
.checkout-form-col { }
.checkout-summary { background: #fff; border: 1px solid var(--border); padding: 1.5rem; align-self: start; }
.summary-total { display: flex; justify-content: space-between; padding: 1rem 0 0; font-family: var(--ui-font); border-top: 1px solid var(--border); margin-top: .5rem; }
.summary-total strong { color: var(--accent); font-size: 1.1rem; }
.checkout-confirm { padding: 2.5rem 1.5rem; max-width: 700px; }
.confirm-meta { background: #fff; border: 1px solid var(--border); padding: 1.25rem 1.5rem; border-radius: 2px; margin-bottom: 1rem; font-family: var(--ui-font); line-height: 2; }
.order-status { display: inline-block; padding: .2rem .6rem; border-radius: 2px; font-size: .85rem; }
.order-status--pending   { background: #fff3cd; color: #856404; }
.order-status--paid      { background: #d1ecf1; color: #0c5460; }
.order-status--ready     { background: #d4edda; color: #155724; }
.order-status--completed { background: #d4edda; color: #155724; }
.order-status--cancelled { background: #f8d7da; color: #721c24; }
```

- [ ] **Step 13: Run full test suite**

```bash
./vendor/bin/phpunit --testdox
```

Expected: 37 tests, all pass.

- [ ] **Step 14: Smoke test checkout and order pages**

```bash
php -S localhost:8080 -t www > /tmp/php_server.log 2>&1 & sleep 1 && \
curl -s -o /dev/null -w "%{http_code} /en/checkout (empty cart → redirect)\n" http://localhost:8080/en/checkout && \
kill %1 2>/dev/null; true
```

Expected: `302 /en/checkout` (redirects to cart because cart is empty).

- [ ] **Step 15: Commit**

```bash
git add src/Models/OrderModel.php src/Controllers/CheckoutController.php \
        src/Controllers/OrderController.php \
        templates/public/checkout/ templates/public/order/ \
        www/assets/css/style.css lang/ \
        tests/Unit/Models/OrderModelTest.php
git commit -m "feat: checkout form, order creation, order status page"
```

---

### Task 3: GoPay Payment Integration

**Files:**
- Create: `src/Services/GoPay.php`
- Modify: `src/Controllers/PaymentController.php` — replace stub

**Interfaces:**
- Consumes:
  - `OrderModel::findByNumber()`, `OrderModel::updateStatus()`, `OrderModel::findByGopayId()`
  - `$_SESSION['pending_order']`
- Produces:
  - `GoPay::fromSettings(): ?self` — returns null when credentials not configured
  - `GoPay::createPayment(array $order, string $returnUrl, string $notifyUrl): array` — `['payment_id' => string, 'gw_url' => string]`
  - `GoPay::getStatus(string $paymentId): array` — GoPay payment object

**GoPay flow:**
1. `POST /{lang}/payment/gopay` — read order from POST body, call `GoPay::fromSettings()`. If null (dev mode) → mark order paid, redirect to order status. If GoPay → create payment, redirect to GoPay URL.
2. `GET /{lang}/payment/return?id=PAYMENT_ID` — call `GoPay::getStatus()`. If `PAID` → update order. Redirect to order status page.
3. `POST /payment/notify` — called by GoPay server. Read body, get `id`, call `GoPay::getStatus()`. If `PAID` → update order. Return 200.

- [ ] **Step 1: Create `src/Services/GoPay.php`**

```php
<?php
namespace App\Services;

use App\Models\Database;

class GoPay
{
    private string $baseUrl;

    public function __construct(
        private string $goId,
        private string $clientId,
        private string $clientSecret,
        bool $testMode = true
    ) {
        $this->baseUrl = $testMode
            ? 'https://gw.sandbox.gopay.com/api'
            : 'https://gate.gopay.cz/api';
    }

    public static function fromSettings(): ?self
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->query(
            "SELECT `key`, `value` FROM settings
             WHERE `key` IN ('gopay_go_id','gopay_client_id','gopay_client_secret','gopay_test_mode')"
        );
        $cfg = [];
        foreach ($stmt->fetchAll() as $row) {
            $cfg[$row['key']] = $row['value'];
        }
        if (empty($cfg['gopay_go_id'])) {
            return null;
        }
        return new self(
            $cfg['gopay_go_id'],
            $cfg['gopay_client_id'],
            $cfg['gopay_client_secret'],
            (bool) ($cfg['gopay_test_mode'] ?? true)
        );
    }

    private function getToken(): string
    {
        $ch = curl_init("{$this->baseUrl}/oauth2/token");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => "{$this->clientId}:{$this->clientSecret}",
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials&scope=payment-all',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $body, true);
        return $data['access_token'] ?? '';
    }

    public function createPayment(array $order, string $returnUrl, string $notifyUrl): array
    {
        $token   = $this->getToken();
        $amountH = (int) round((float) $order['total_amount'] * 100);

        $payload = [
            'payer'             => ['allowed_payment_instruments' => ['PAYMENT_CARD','BANK_ACCOUNT']],
            'amount'            => $amountH,
            'currency'          => 'CZK',
            'order_number'      => $order['order_number'],
            'order_description' => 'BalonkyDecor ' . $order['order_number'],
            'callback'          => ['return_url' => $returnUrl, 'notification_url' => $notifyUrl],
            'lang'              => 'CS',
        ];

        $ch = curl_init("{$this->baseUrl}/payments/payment");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $body, true);
        return [
            'payment_id' => (string) ($data['id'] ?? ''),
            'gw_url'     => (string) ($data['gw_url'] ?? ''),
        ];
    }

    public function getStatus(string $paymentId): array
    {
        $token = $this->getToken();
        $ch    = curl_init("{$this->baseUrl}/payments/payment/{$paymentId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return json_decode((string) $body, true) ?? [];
    }
}
```

- [ ] **Step 2: Replace `src/Controllers/PaymentController.php`**

```php
<?php
namespace App\Controllers;

use App\Models\OrderModel;
use App\Services\GoPay;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PaymentController extends BaseController
{
    public function initiate(Request $request, Response $response, array $args): Response
    {
        $lang        = $request->getAttribute('lang');
        $body        = (array) $request->getParsedBody();
        $orderNumber = trim($body['order_number'] ?? '');

        if (!$orderNumber) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $orderNumber = $_SESSION['pending_order'] ?? '';
        }

        $order = $orderNumber ? OrderModel::findByNumber($orderNumber) : null;
        if (!$order) {
            return $response->withHeader('Location', "/{$lang}/")->withStatus(302);
        }

        $gopay = GoPay::fromSettings();
        if (!$gopay) {
            // Dev bypass: mark paid immediately
            OrderModel::updateStatus($orderNumber, 'paid');
            return $response
                ->withHeader('Location', "/{$lang}/order/{$orderNumber}")
                ->withStatus(302);
        }

        $uri       = $request->getUri();
        $base      = $uri->getScheme() . '://' . $uri->getHost();
        $returnUrl = "{$base}/{$lang}/payment/return";
        $notifyUrl = "{$base}/payment/notify";

        $payment = $gopay->createPayment($order, $returnUrl, $notifyUrl);
        if (empty($payment['gw_url'])) {
            return $response->withHeader('Location', "/{$lang}/order/{$orderNumber}")->withStatus(302);
        }

        OrderModel::updateStatus($orderNumber, 'pending', $payment['payment_id']);
        return $response->withHeader('Location', $payment['gw_url'])->withStatus(302);
    }

    public function paymentReturn(Request $request, Response $response, array $args): Response
    {
        $lang      = $request->getAttribute('lang');
        $params    = $request->getQueryParams();
        $paymentId = $params['id'] ?? '';

        if ($paymentId) {
            $gopay = GoPay::fromSettings();
            if ($gopay) {
                $status = $gopay->getStatus($paymentId);
                if (($status['state'] ?? '') === 'PAID') {
                    $order = OrderModel::findByGopayId($paymentId);
                    if ($order) {
                        OrderModel::updateStatus($order['order_number'], 'paid', $paymentId);
                        return $response
                            ->withHeader('Location', "/{$lang}/order/{$order['order_number']}")
                            ->withStatus(302);
                    }
                }
            }
        }

        return $response->withHeader('Location', "/{$lang}/")->withStatus(302);
    }

    public function notify(Request $request, Response $response, array $args): Response
    {
        $body      = (string) $request->getBody();
        $data      = json_decode($body, true) ?? [];
        $paymentId = (string) ($data['id'] ?? '');

        if ($paymentId) {
            $gopay = GoPay::fromSettings();
            if ($gopay) {
                $status = $gopay->getStatus($paymentId);
                if (($status['state'] ?? '') === 'PAID') {
                    $order = OrderModel::findByGopayId($paymentId);
                    if ($order) {
                        OrderModel::updateStatus($order['order_number'], 'paid', $paymentId);
                    }
                }
            }
        }

        return $response->withStatus(200);
    }
}
```

- [ ] **Step 3: Run full test suite**

```bash
./vendor/bin/phpunit --testdox
```

Expected: 37 tests, all pass (GoPay has no unit tests — it wraps external API; tested end-to-end manually).

- [ ] **Step 4: Full smoke test of all pages**

```bash
php -S localhost:8080 -t www > /tmp/php_server.log 2>&1 & sleep 1 && \
curl -s -o /dev/null -w "%{http_code} /en/\n"        http://localhost:8080/en/ && \
curl -s -o /dev/null -w "%{http_code} /en/shop\n"    http://localhost:8080/en/shop && \
curl -s -o /dev/null -w "%{http_code} /en/cart\n"    http://localhost:8080/en/cart && \
curl -s -o /dev/null -w "%{http_code} /en/checkout\n" http://localhost:8080/en/checkout && \
kill %1 2>/dev/null; true
```

Expected: `200, 200, 200, 302` (checkout redirects to cart when empty).

- [ ] **Step 5: Commit**

```bash
git add src/Services/GoPay.php src/Controllers/PaymentController.php
git commit -m "feat: GoPay payment integration with dev bypass"
```

---

## Self-Review

**Spec coverage:**
- ✅ Session cart — add, remove, update qty, total, item count
- ✅ Product → cart via Add to Cart form on product detail page (Plan 2 template → Plan 3 CartController)
- ✅ Cart page with quantity editing and remove
- ✅ Checkout form — name, email, phone, pickup date, notes; validates required fields
- ✅ Order creation — DB transaction, order_number `BD-YYYYMMDD-NNNNN`
- ✅ Checkout confirm page — summary + Pay Now button
- ✅ GoPay payment initiation — redirect to GoPay sandbox/live URL
- ✅ GoPay return URL — verify status, update order, redirect to order page
- ✅ GoPay IPN webhook at `/payment/notify` (no lang prefix) — idempotent status update
- ✅ Dev bypass — when `gopay_go_id` empty → order immediately marked paid
- ✅ Order status page — all 5 statuses with colour-coded badge
- ✅ 5-language support for all new strings

**What this plan does NOT cover (Plan 4):**
- Admin panel: product/category/order/gallery/blog/page management, image uploads, settings
