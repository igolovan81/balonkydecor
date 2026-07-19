<?php
namespace Tests\Unit\Services;

use App\Models\Database;
use App\Services\Wishlist;
use PHPUnit\Framework\TestCase;

class WishlistTest extends TestCase
{
    private static int $categoryId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO categories (slug) VALUES ('test-wishlist')");
        $row = $pdo->query("SELECT id FROM categories WHERE slug='test-wishlist'")->fetch();
        self::$categoryId = (int) $row['id'];

        $pdo->exec("INSERT IGNORE INTO products (category_id, sku, price) VALUES (" . self::$categoryId . ", 'TEST-WISHLIST-SKU-001', 19.90)");
        $id = $pdo->query("SELECT id FROM products WHERE sku='TEST-WISHLIST-SKU-001'")->fetch()['id'];
        $pdo->exec("INSERT IGNORE INTO product_t (product_id, lang_code, name) VALUES ({$id}, 'en', 'Wishlist Test Balloon')");
    }

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['wishlist'] = [];
    }

    public function test_toggle_adds_sku_and_returns_true(): void
    {
        $result = Wishlist::toggle('SKU-1');
        $this->assertTrue($result);
        $this->assertSame(['SKU-1'], Wishlist::skus());
    }

    public function test_toggle_removes_sku_and_returns_false(): void
    {
        Wishlist::toggle('SKU-1');
        $result = Wishlist::toggle('SKU-1');
        $this->assertFalse($result);
        $this->assertSame([], Wishlist::skus());
    }

    public function test_has_reflects_current_state(): void
    {
        $this->assertFalse(Wishlist::has('SKU-1'));
        Wishlist::toggle('SKU-1');
        $this->assertTrue(Wishlist::has('SKU-1'));
    }

    public function test_count_matches_number_of_saved_skus(): void
    {
        Wishlist::toggle('SKU-1');
        Wishlist::toggle('SKU-2');
        $this->assertSame(2, Wishlist::count());
    }

    public function test_count_is_zero_for_empty_wishlist(): void
    {
        $this->assertSame(0, Wishlist::count());
    }

    public function test_items_hydrates_saved_product(): void
    {
        Wishlist::toggle('TEST-WISHLIST-SKU-001');
        $items = Wishlist::items('en');
        $this->assertCount(1, $items);
        $this->assertSame('TEST-WISHLIST-SKU-001', $items[0]['sku']);
        $this->assertSame('Wishlist Test Balloon', $items[0]['name']);
    }

    public function test_items_skips_sku_that_no_longer_resolves(): void
    {
        Wishlist::toggle('TEST-WISHLIST-SKU-001');
        Wishlist::toggle('SKU-DOES-NOT-EXIST');
        $items = Wishlist::items('en');
        $skus  = array_column($items, 'sku');
        $this->assertSame(['TEST-WISHLIST-SKU-001'], $skus);
    }
}
