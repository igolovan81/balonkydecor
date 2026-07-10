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

    public function test_add_with_subtype_creates_distinct_line(): void
    {
        Cart::add('SKU-1', 1, 'Balloon — Macarons', '1.90', 10, 'Macarons');
        Cart::add('SKU-1', 1, 'Balloon — Chrome', '3.40', 20, 'Chrome');
        $items = Cart::items();
        $this->assertArrayHasKey('SKU-1:10', $items);
        $this->assertArrayHasKey('SKU-1:20', $items);
        $this->assertSame(1, $items['SKU-1:10']['qty']);
    }

    public function test_add_with_same_subtype_accumulates_qty(): void
    {
        Cart::add('SKU-1', 1, 'Balloon — Macarons', '1.90', 10, 'Macarons');
        Cart::add('SKU-1', 2, 'Balloon — Macarons', '1.90', 10, 'Macarons');
        $this->assertSame(3, Cart::items()['SKU-1:10']['qty']);
    }

    public function test_add_with_subtype_stores_sku_and_subtype_fields(): void
    {
        Cart::add('SKU-1', 1, 'Balloon — Macarons', '1.90', 10, 'Macarons');
        $item = Cart::items()['SKU-1:10'];
        $this->assertSame('SKU-1', $item['sku']);
        $this->assertSame(10, $item['subtype_id']);
        $this->assertSame('Macarons', $item['subtype_name']);
    }

    public function test_add_without_subtype_still_stores_sku(): void
    {
        Cart::add('SKU-1', 1, 'Red Balloon', '49.00');
        $this->assertSame('SKU-1', Cart::items()['SKU-1']['sku']);
        $this->assertNull(Cart::items()['SKU-1']['subtype_id']);
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
