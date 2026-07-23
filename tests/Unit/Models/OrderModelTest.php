<?php
namespace Tests\Unit\Models;

use App\Models\CustomerModel;
use App\Models\OrderModel;
use PHPUnit\Framework\TestCase;

class OrderModelTest extends TestCase
{
    private static string $orderNumber;
    private static string $gopayId;

    public static function setUpBeforeClass(): void
    {
        self::$gopayId     = 'GOPAY-TEST-' . uniqid();
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
        OrderModel::updateStatus(self::$orderNumber, 'paid', self::$gopayId);
        $order = OrderModel::findByNumber(self::$orderNumber);
        $this->assertSame('paid', $order['status']);
        $this->assertSame(self::$gopayId, $order['gopay_payment_id']);
    }

    public function test_find_by_gopay_id(): void
    {
        $order = OrderModel::findByGopayId(self::$gopayId);
        $this->assertNotNull($order);
        $this->assertSame(self::$orderNumber, $order['order_number']);
    }

    public function test_find_by_number_returns_null_for_unknown(): void
    {
        $this->assertNull(OrderModel::findByNumber('BD-99999999-00000'));
    }

    public function test_create_persists_subtype_id_and_name_snapshot(): void
    {
        $pdo = \App\Models\Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO categories (slug) VALUES ('test-order-subtype')");
        $catId = $pdo->query("SELECT id FROM categories WHERE slug='test-order-subtype'")->fetch()['id'];

        $sku = 'ORDER-SUB-' . uniqid();
        $pdo->prepare('INSERT INTO products (category_id, sku, price) VALUES (?, ?, 9.99)')
            ->execute([$catId, $sku]);
        $productId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO product_subtypes (product_id, price, sort_order) VALUES (?, ?, 0)')
            ->execute([$productId, '1.90']);
        $subtypeId = (int) $pdo->lastInsertId();

        $orderNumber = OrderModel::create(
            [
                'customer_name'  => 'Subtype Buyer',
                'customer_email' => 'sub@example.com',
                'customer_phone' => '+420000000000',
                'pickup_date'    => '2026-12-31',
                'notes'          => '',
            ],
            [
                $sku . ':' . $subtypeId => [
                    'sku' => $sku, 'subtype_id' => $subtypeId, 'subtype_name' => 'Makarons',
                    'qty' => 2, 'name' => 'Test — Makarons', 'price' => '1.90', 'subtotal' => '3.80',
                ],
            ],
            '3.80'
        );

        $order = OrderModel::findByNumber($orderNumber);
        $this->assertSame($subtypeId, (int) $order['items'][0]['subtype_id']);
        $this->assertSame('Makarons', $order['items'][0]['subtype_name_snapshot']);
        $this->assertSame($productId, (int) $order['items'][0]['product_id']);
    }

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

    public function test_dashboardStats_reflects_new_order(): void
    {
        $before = OrderModel::dashboardStats();

        OrderModel::create(
            [
                'customer_name'  => 'Dashboard Stats Buyer',
                'customer_email' => 'dash-stats-' . uniqid() . '@example.com',
                'customer_phone' => '+420000000002',
                'pickup_date'    => '2026-12-31',
                'notes'          => '',
            ],
            ['SKU-DASH-STATS' => ['qty' => 1, 'name' => 'Dash Stats Balloon', 'price' => '5.00', 'subtotal' => '5.00']],
            '5.00'
        );

        $after = OrderModel::dashboardStats();

        $this->assertSame($before['orders_today'] + 1, $after['orders_today']);
        $this->assertSame($before['orders_pending'] + 1, $after['orders_pending']);
        $this->assertSame($before['orders_total'] + 1, $after['orders_total']);
        $this->assertSame($before['gopay_count'], $after['gopay_count']);
    }

    public function test_statusBreakdown_has_all_five_statuses_and_reflects_new_order(): void
    {
        $before = OrderModel::statusBreakdown();

        OrderModel::create(
            [
                'customer_name'  => 'Status Breakdown Buyer',
                'customer_email' => 'status-breakdown-' . uniqid() . '@example.com',
                'customer_phone' => '+420000000003',
                'pickup_date'    => '2026-12-31',
                'notes'          => '',
            ],
            ['SKU-STATUS-BREAKDOWN' => ['qty' => 1, 'name' => 'Status Balloon', 'price' => '5.00', 'subtotal' => '5.00']],
            '5.00'
        );

        $after = OrderModel::statusBreakdown();

        $this->assertSame(['pending', 'paid', 'ready', 'completed', 'cancelled'], array_keys($after));
        $this->assertSame($before['pending'] + 1, $after['pending']);
    }

    public function test_revenueByDay_includes_todays_order_and_zero_fills_range(): void
    {
        $today  = date('Y-m-d');
        $before = OrderModel::revenueByDay(7);
        $beforeToday = end($before)['total'];

        OrderModel::create(
            [
                'customer_name'  => 'Revenue Buyer',
                'customer_email' => 'revenue-' . uniqid() . '@example.com',
                'customer_phone' => '+420000000004',
                'pickup_date'    => '2026-12-31',
                'notes'          => '',
            ],
            ['SKU-REVENUE' => ['qty' => 1, 'name' => 'Revenue Balloon', 'price' => '12.34', 'subtotal' => '12.34']],
            '12.34'
        );

        $after = OrderModel::revenueByDay(7);

        $this->assertCount(7, $after);
        $this->assertSame($today, end($after)['date']);
        $this->assertEqualsWithDelta($beforeToday + 12.34, end($after)['total'], 0.001);
    }
}
