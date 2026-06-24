<?php
namespace Tests\Unit\Models;

use App\Models\ProductModel;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

class ProductModelTest extends TestCase
{
    private static int $categoryId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO categories (slug) VALUES ('test-products')");
        $row = $pdo->query("SELECT id FROM categories WHERE slug='test-products'")->fetch();
        self::$categoryId = (int) $row['id'];

        $pdo->exec("INSERT IGNORE INTO products (category_id, sku, price) VALUES (" . self::$categoryId . ", 'TEST-SKU-001', 9.99)");
        $id = $pdo->query("SELECT id FROM products WHERE sku='TEST-SKU-001'")->fetch()['id'];
        $pdo->exec("INSERT IGNORE INTO product_t (product_id, lang_code, name) VALUES ({$id}, 'en', 'Test Product')");
    }

    public function test_all_active_returns_array(): void
    {
        $result = ProductModel::allActive('en');
        $this->assertIsArray($result);
    }

    public function test_find_by_sku_returns_product(): void
    {
        $product = ProductModel::findBySku('TEST-SKU-001', 'en');
        $this->assertNotNull($product);
        $this->assertSame('TEST-SKU-001', $product['sku']);
        $this->assertSame('Test Product', $product['name']);
        $this->assertArrayHasKey('images', $product);
    }

    public function test_find_by_sku_returns_null_for_unknown(): void
    {
        $this->assertNull(ProductModel::findBySku('NONEXISTENT', 'en'));
    }

    public function test_filter_by_category(): void
    {
        $result = ProductModel::allActive('en', self::$categoryId);
        $this->assertIsArray($result);
        foreach ($result as $row) {
            $this->assertSame(self::$categoryId, (int) $row['category_id']);
        }
    }
}
