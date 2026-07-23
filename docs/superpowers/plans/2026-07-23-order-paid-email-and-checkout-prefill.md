# Order-Paid Admin Notification & Checkout Prefill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Email the shop's `contact_email` whenever a customer's order is actually paid, and prefill the checkout form's name/email/phone when the customer is logged in.

**Architecture:** Both changes are pure controller edits reusing existing models/services — no new tables, models, or Twig templates. `PaymentController` gains a duplicate-send guard plus a private `notifyOrderPaid()` helper built on the existing `Mailer`/`Database` services. `CheckoutController::index()` gains a `CustomerModel::findById()` lookup to seed the `values` array the template already reads.

**Tech Stack:** PHP 8, Slim 4, PDO/MySQL, existing `Mailer`/`OrderModel`/`CustomerModel` — no new dependencies.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-23-order-paid-email-and-checkout-prefill-design.md`
- No new DB tables/columns — `orders`, `order_items`, `customers.name`/`customers.phone`, `settings.contact_email` already exist.
- Notification email is plain inline HTML built in the controller (same convention as `ContactController::send()`), sent via the existing `Mailer::send()` — no Twig template, no `t()` translation keys (internal transactional email, not page content).
- All interpolated values into the email HTML must go through `htmlspecialchars()` (order data originates from customer-submitted checkout input).
- Notification fires only from customer-facing payment completion (`PaymentController::initiate()` dev bypass, `paymentReturn()`, `notify()`) — never from `Admin/OrderController::updateStatus()`.
- Guard against double-send: only update status + notify when the order's current status is not already `'paid'`.
- Per `.claude/rules/unit-testing.md`, controllers are untested by convention — no PHPUnit tests are added in this plan; verification is manual via `/start` + browser + `tmp/mail.log`, plus running the existing suite to confirm no regressions.
- Run `php vendor/bin/phpunit` before each commit (project-wide rule) — expect it to stay green since no models change.

---

### Task 1: Order-paid admin notification email

**Files:**
- Modify: `src/Controllers/PaymentController.php` (entire file — see below)

**Interfaces:**
- Consumes: `OrderModel::findByNumber(string $number): ?array` (returns order row + `items` array, each item having `product_name_snapshot`, `subtype_name_snapshot`, `quantity`, `unit_price`), `OrderModel::findByGopayId(string $gopayId): ?array`, `OrderModel::updateStatus(string $number, string $status, ?string $gopayId = null): void`, `App\Models\Database::getConnection(): \PDO`, `App\Services\Mailer::send(string $to, string $subject, string $body, string $replyTo = ''): bool`.
- Produces: `PaymentController::notifyOrderPaid(string $orderNumber): void` (private) — no other task depends on it.

- [ ] **Step 1: Replace `src/Controllers/PaymentController.php` with the guarded + notifying version**

Replace the full file contents with:

```php
<?php
namespace App\Controllers;

use App\Models\Database;
use App\Models\OrderModel;
use App\Services\GoPay;
use App\Services\Mailer;
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
            if ($order['status'] !== 'paid') {
                OrderModel::updateStatus($orderNumber, 'paid');
                $this->notifyOrderPaid($orderNumber);
            }
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
            return $response
                ->withHeader('Location', "/{$lang}/order/{$orderNumber}")
                ->withStatus(302);
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
                        if ($order['status'] !== 'paid') {
                            OrderModel::updateStatus($order['order_number'], 'paid', $paymentId);
                            $this->notifyOrderPaid($order['order_number']);
                        }
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
                    if ($order && $order['status'] !== 'paid') {
                        OrderModel::updateStatus($order['order_number'], 'paid', $paymentId);
                        $this->notifyOrderPaid($order['order_number']);
                    }
                }
            }
        }

        return $response->withStatus(200);
    }

    private function notifyOrderPaid(string $orderNumber): void
    {
        $order = OrderModel::findByNumber($orderNumber);
        if (!$order) {
            return;
        }

        $pdo          = Database::getConnection();
        $contactEmail = $pdo->query("SELECT value FROM settings WHERE `key`='contact_email'")->fetchColumn();
        if (!$contactEmail) {
            return;
        }

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
    }
}
```

- [ ] **Step 2: Start the local stack**

Run: `docker compose up -d && php -S localhost:8080 -t www`
Expected: MySQL container up, dev server listening on `:8080`.

- [ ] **Step 3: Verify the dev-bypass path sends the notification**

In the admin panel (`/admin/settings`), confirm `gopay_go_id` is empty (dev bypass active) and `contact_email` is set to some address, e.g. `owner@example.test`. Then in a browser: add a product to the cart, go to `/{lang}/checkout`, submit the form, click through to payment. You should land on `/{lang}/order/{number}` with status "Paid".

Run: `cat tmp/mail.log | tail -30`
Expected: a new entry `TO:owner@example.test SUBJECT:Paid order BD-...` containing the order's customer name/email/phone and an items table with the product you added.

- [ ] **Step 4: Verify the duplicate-send guard**

With the same order still in `tmp/mail.log`, re-submit the payment for the same order number (re-visit `/{lang}/order/{number}` and, if the "pay" button/form is still reachable, POST `/{lang}/payment/gopay` again with that `order_number`; otherwise call it directly, e.g. `curl -X POST localhost:8080/en/payment/gopay -d "order_number=BD-...".`

Run: `cat tmp/mail.log | tail -30`
Expected: no new `Paid order BD-...` entry was appended — the count of matching entries for that order number is unchanged from Step 3.

- [ ] **Step 5: Verify admin manual status changes stay silent**

Create another order (add to cart, checkout, but don't pay — leave it `pending`). In `/admin/orders`, open it and change its status to `paid` via the admin status dropdown.

Run: `cat tmp/mail.log | tail -30`
Expected: no new `Paid order ...` entry for that order number.

- [ ] **Step 6: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests pass (no test touches `PaymentController`, so this just confirms no regression elsewhere).

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/PaymentController.php
git commit -m "feat: email contact address when a customer order is paid"
```

---

### Task 2: Prefill checkout form for logged-in customers

**Files:**
- Modify: `src/Controllers/CheckoutController.php:11-25` (the `index()` method)

**Interfaces:**
- Consumes: `App\Models\CustomerModel::findById(int $id): ?array` (returns a row with `id`, `email`, `name` (nullable), `phone` (nullable), `password_hash`, ...).
- Produces: nothing consumed by other tasks — `templates/public/checkout/index.twig` already reads `values.customer_name` / `values.customer_email` / `values.customer_phone`, no template change needed.

- [ ] **Step 1: Update `CheckoutController::index()`**

In `src/Controllers/CheckoutController.php`, add the `CustomerModel` import:

```php
use App\Models\CustomerModel;
use App\Models\OrderModel;
```

Replace the `index()` method:

```php
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');

        if (Cart::isEmpty()) {
            return $response->withHeader('Location', "/{$lang}/cart")->withStatus(302);
        }

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

        return $this->render($request, $response, 'public/checkout/index.twig', [
            'items'  => Cart::items(),
            'total'  => Cart::total(),
            'error'  => false,
            'values' => $values,
        ]);
    }
```

- [ ] **Step 2: Verify prefill for a logged-in customer with a saved profile**

In the browser: log in as a customer (register one at `/{lang}/register` if needed), go to `/{lang}/account` and fill in name + phone via the Customer Info page (so `customers.name`/`customers.phone` are non-null), then add a product to the cart and visit `/{lang}/checkout`.
Expected: the "Full name", "Email", and "Phone" fields are pre-filled with the account's saved name/email/phone, and remain editable (typing into them works normally).

- [ ] **Step 3: Verify guest checkout is unaffected**

Log out (`/{lang}/logout`), add a product to the cart, visit `/{lang}/checkout`.
Expected: "Full name", "Email", and "Phone" are blank, exactly as before this change.

- [ ] **Step 4: Verify a logged-in customer with no saved name/phone degrades gracefully**

Register a brand-new customer (name/phone never filled in — `NULL` in the DB), add a product to the cart, visit `/{lang}/checkout`.
Expected: "Email" is pre-filled with the account email; "Full name" and "Phone" render blank (not the literal string "null").

- [ ] **Step 5: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/CheckoutController.php
git commit -m "feat: prefill checkout form from logged-in customer's profile"
```
