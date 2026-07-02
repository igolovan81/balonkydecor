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

    public function test_set_translations_stores_meta_fields(): void
    {
        $pdo = Database::getConnection();
        $id  = $pdo->query("SELECT id FROM products WHERE sku='TEST-SKU-001'")->fetch()['id'];
        ProductModel::setTranslations($id, [
            'en' => ['name' => 'Test Product', 'meta_title' => 'Buy Test Product', 'meta_desc' => 'Best test product in town.'],
        ]);
        $translations = ProductModel::getTranslations($id);
        $this->assertSame('Buy Test Product', $translations['en']['meta_title']);
        $this->assertSame('Best test product in town.', $translations['en']['meta_desc']);
    }

    public function test_create_persists_limited_stock(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-STOCK-' . uniqid(),
            'price'       => 19.99,
            'category_id' => self::$categoryId,
            'stock_type'  => 'limited',
            'stock_qty'   => 5,
        ]);
        $product = ProductModel::findById($id);
        $this->assertSame('limited', $product['stock_type']);
        $this->assertSame(5, (int) $product['stock_qty']);
    }

    public function test_create_defaults_to_unlimited_when_stock_fields_omitted(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-STOCK-' . uniqid(),
            'price'       => 19.99,
            'category_id' => self::$categoryId,
        ]);
        $product = ProductModel::findById($id);
        $this->assertSame('unlimited', $product['stock_type']);
        $this->assertSame(0, (int) $product['stock_qty']);
    }

    public function test_create_clamps_negative_stock_qty_to_zero(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-STOCK-' . uniqid(),
            'price'       => 19.99,
            'category_id' => self::$categoryId,
            'stock_type'  => 'limited',
            'stock_qty'   => -5,
        ]);
        $product = ProductModel::findById($id);
        $this->assertSame(0, (int) $product['stock_qty']);
    }

    public function test_create_forces_zero_qty_when_unlimited(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-STOCK-' . uniqid(),
            'price'       => 19.99,
            'category_id' => self::$categoryId,
            'stock_type'  => 'unlimited',
            'stock_qty'   => 42,
        ]);
        $product = ProductModel::findById($id);
        $this->assertSame('unlimited', $product['stock_type']);
        $this->assertSame(0, (int) $product['stock_qty']);
    }

    public function test_update_persists_limited_stock(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-STOCK-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
        ]);
        ProductModel::update($id, [
            'sku'         => 'TEST-STOCK-UPDATED-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
            'stock_type'  => 'limited',
            'stock_qty'   => 3,
        ]);
        $product = ProductModel::findById($id);
        $this->assertSame('limited', $product['stock_type']);
        $this->assertSame(3, (int) $product['stock_qty']);
    }

    public function test_add_image_becomes_primary_when_product_has_no_images(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-IMG-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
        ]);

        ProductModel::addImage($id, 'first-upload.jpg', false);

        $product = ProductModel::findById($id);
        $this->assertCount(1, $product['images']);
        $this->assertSame(1, (int) $product['images'][0]['is_primary']);
    }

    public function test_add_image_does_not_displace_existing_primary(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-IMG-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
        ]);

        ProductModel::addImage($id, 'main.jpg', true);
        ProductModel::addImage($id, 'second.jpg', false);

        $product = ProductModel::findById($id);
        $byFilename = [];
        foreach ($product['images'] as $img) {
            $byFilename[$img['filename']] = (int) $img['is_primary'];
        }
        $this->assertSame(1, $byFilename['main.jpg']);
        $this->assertSame(0, $byFilename['second.jpg']);
    }
}
