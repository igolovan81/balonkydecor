<?php
namespace Tests\Unit\Models;

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
}
