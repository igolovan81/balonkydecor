<?php
namespace Tests\Unit\Services;

use App\Models\Database;
use App\Services\RecentlyViewed;
use PHPUnit\Framework\TestCase;

class RecentlyViewedTest extends TestCase
{
    private static int $categoryId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO categories (slug) VALUES ('test-recently-viewed')");
        $row = $pdo->query("SELECT id FROM categories WHERE slug='test-recently-viewed'")->fetch();
        self::$categoryId = (int) $row['id'];

        $pdo->exec("INSERT IGNORE INTO products (category_id, sku, price) VALUES (" . self::$categoryId . ", 'TEST-RV-SKU-001', 19.90)");
        $id = $pdo->query("SELECT id FROM products WHERE sku='TEST-RV-SKU-001'")->fetch()['id'];
        $pdo->exec("INSERT IGNORE INTO product_t (product_id, lang_code, name) VALUES ({$id}, 'en', 'Recently Viewed Test Balloon')");
    }

    protected function setUp(): void
    {
        unset($_COOKIE['recently_viewed']);
    }

    public function test_track_adds_sku_to_empty_list(): void
    {
        RecentlyViewed::track('SKU-1');
        $this->assertSame(['SKU-1'], RecentlyViewed::skus());
    }

    public function test_track_prepends_new_sku_before_older_ones(): void
    {
        RecentlyViewed::track('SKU-1');
        RecentlyViewed::track('SKU-2');
        $this->assertSame(['SKU-2', 'SKU-1'], RecentlyViewed::skus());
    }

    public function test_track_moves_existing_sku_to_front_instead_of_duplicating(): void
    {
        RecentlyViewed::track('SKU-1');
        RecentlyViewed::track('SKU-2');
        RecentlyViewed::track('SKU-1');
        $this->assertSame(['SKU-1', 'SKU-2'], RecentlyViewed::skus());
    }

    public function test_track_caps_list_at_eight_dropping_oldest(): void
    {
        for ($i = 1; $i <= 9; $i++) {
            RecentlyViewed::track("SKU-{$i}");
        }
        $this->assertSame(
            ['SKU-9', 'SKU-8', 'SKU-7', 'SKU-6', 'SKU-5', 'SKU-4', 'SKU-3', 'SKU-2'],
            RecentlyViewed::skus()
        );
    }

    public function test_skus_excludes_given_sku_when_asked(): void
    {
        RecentlyViewed::track('SKU-1');
        RecentlyViewed::track('SKU-2');
        $this->assertSame(['SKU-1'], RecentlyViewed::skus('SKU-2'));
    }

    public function test_skus_is_empty_when_no_cookie_set(): void
    {
        $this->assertSame([], RecentlyViewed::skus());
    }

    public function test_items_hydrates_saved_product(): void
    {
        RecentlyViewed::track('TEST-RV-SKU-001');
        $items = RecentlyViewed::items('en');
        $this->assertCount(1, $items);
        $this->assertSame('TEST-RV-SKU-001', $items[0]['sku']);
        $this->assertSame('Recently Viewed Test Balloon', $items[0]['name']);
    }

    public function test_items_skips_sku_that_no_longer_resolves(): void
    {
        RecentlyViewed::track('TEST-RV-SKU-001');
        RecentlyViewed::track('SKU-DOES-NOT-EXIST');
        $items = RecentlyViewed::items('en');
        $skus  = array_column($items, 'sku');
        $this->assertSame(['TEST-RV-SKU-001'], $skus);
    }

    public function test_items_excludes_given_sku(): void
    {
        RecentlyViewed::track('TEST-RV-SKU-001');
        $items = RecentlyViewed::items('en', 'TEST-RV-SKU-001');
        $this->assertSame([], $items);
    }
}
