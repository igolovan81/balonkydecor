<?php
namespace Tests\Unit\Services;

use App\Models\Database;
use App\Services\Compare;
use PHPUnit\Framework\TestCase;

class CompareTest extends TestCase
{
    private static int $categoryId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO categories (slug) VALUES ('test-compare')");
        $row = $pdo->query("SELECT id FROM categories WHERE slug='test-compare'")->fetch();
        self::$categoryId = (int) $row['id'];

        $pdo->exec("INSERT IGNORE INTO products (category_id, sku, price) VALUES (" . self::$categoryId . ", 'TEST-COMPARE-SKU-001', 29.90)");
        $id = $pdo->query("SELECT id FROM products WHERE sku='TEST-COMPARE-SKU-001'")->fetch()['id'];
        $pdo->exec("INSERT IGNORE INTO product_t (product_id, lang_code, name) VALUES ({$id}, 'en', 'Compare Test Balloon')");
    }

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['compare'] = [];
    }

    public function test_toggle_adds_sku_and_returns_added_true(): void
    {
        $result = Compare::toggle('SKU-1');
        $this->assertSame(['added' => true, 'full' => false], $result);
        $this->assertSame(['SKU-1'], Compare::skus());
    }

    public function test_toggle_removes_sku_and_returns_added_false(): void
    {
        Compare::toggle('SKU-1');
        $result = Compare::toggle('SKU-1');
        $this->assertSame(['added' => false, 'full' => false], $result);
        $this->assertSame([], Compare::skus());
    }

    public function test_has_reflects_current_state(): void
    {
        $this->assertFalse(Compare::has('SKU-1'));
        Compare::toggle('SKU-1');
        $this->assertTrue(Compare::has('SKU-1'));
    }

    public function test_count_matches_number_of_saved_skus(): void
    {
        Compare::toggle('SKU-1');
        Compare::toggle('SKU-2');
        $this->assertSame(2, Compare::count());
    }

    public function test_count_is_zero_for_empty_compare_list(): void
    {
        $this->assertSame(0, Compare::count());
    }

    public function test_toggle_rejects_fifth_distinct_sku_when_full(): void
    {
        Compare::toggle('SKU-1');
        Compare::toggle('SKU-2');
        Compare::toggle('SKU-3');
        Compare::toggle('SKU-4');

        $result = Compare::toggle('SKU-5');

        $this->assertSame(['added' => false, 'full' => true], $result);
        $this->assertSame(['SKU-1', 'SKU-2', 'SKU-3', 'SKU-4'], Compare::skus());
    }

    public function test_toggle_succeeds_after_removing_one_to_free_a_slot(): void
    {
        Compare::toggle('SKU-1');
        Compare::toggle('SKU-2');
        Compare::toggle('SKU-3');
        Compare::toggle('SKU-4');
        Compare::toggle('SKU-1'); // remove, frees a slot

        $result = Compare::toggle('SKU-5');

        $this->assertSame(['added' => true, 'full' => false], $result);
        $this->assertSame(['SKU-2', 'SKU-3', 'SKU-4', 'SKU-5'], Compare::skus());
    }

    public function test_clear_empties_the_list(): void
    {
        Compare::toggle('SKU-1');
        Compare::toggle('SKU-2');
        Compare::clear();
        $this->assertSame([], Compare::skus());
        $this->assertSame(0, Compare::count());
    }

    public function test_items_hydrates_saved_product(): void
    {
        Compare::toggle('TEST-COMPARE-SKU-001');
        $items = Compare::items('en');
        $this->assertCount(1, $items);
        $this->assertSame('TEST-COMPARE-SKU-001', $items[0]['sku']);
        $this->assertSame('Compare Test Balloon', $items[0]['name']);
    }

    public function test_items_skips_sku_that_no_longer_resolves(): void
    {
        Compare::toggle('TEST-COMPARE-SKU-001');
        Compare::toggle('SKU-DOES-NOT-EXIST');
        $items = Compare::items('en');
        $skus  = array_column($items, 'sku');
        $this->assertSame(['TEST-COMPARE-SKU-001'], $skus);
    }
}
