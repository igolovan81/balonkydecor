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

    public function test_orphaned_images_finds_rows_with_missing_files(): void
    {
        $productId = $this->makeProduct();
        ProductModel::addImage($productId, 'orphan-present.jpg');
        ProductModel::addImage($productId, 'orphan-gone.jpg');
        $dir = $this->makeUploadsDir(['orphan-present.jpg']);

        $orphans   = ProductModel::orphanedImages($dir);
        $filenames = array_column(
            array_filter($orphans, fn ($r) => (int) $r['product_id'] === $productId),
            'filename'
        );
        $this->assertSame(['orphan-gone.jpg'], $filenames);
    }

    // NB: cleanupOrphans() is global — in the shared dev DB it also removes other
    // fixture rows whose files don't exist on disk (they're broken anyway).
    public function test_cleanup_orphans_deletes_rows_and_promotes_new_primary(): void
    {
        $productId = $this->makeProduct();
        ProductModel::addImage($productId, 'primary-gone.jpg');          // first image → primary
        ProductModel::addImage($productId, 'secondary-present.jpg');
        $dir = $this->makeUploadsDir(['secondary-present.jpg', 'thumb_primary-gone.jpg']);

        $report = ProductModel::cleanupOrphans($dir);

        $this->assertContains('primary-gone.jpg', $report['deleted_images']);
        $this->assertContains($productId, array_map('intval', $report['promoted_primaries']));
        $pdo  = Database::getConnection();
        $rows = $pdo->prepare('SELECT filename, is_primary FROM product_images WHERE product_id = ?');
        $rows->execute([$productId]);
        $left = $rows->fetchAll();
        $this->assertCount(1, $left);
        $this->assertSame('secondary-present.jpg', $left[0]['filename']);
        $this->assertSame(1, (int) $left[0]['is_primary']);
        $this->assertFileDoesNotExist($dir . '/thumb_primary-gone.jpg');
    }

    private function makeProduct(): int
    {
        $pdo = Database::getConnection();
        $sku = 'ORPHAN-' . strtoupper(uniqid());
        $pdo->prepare('INSERT INTO products (category_id, sku, price) VALUES (?, ?, 9.99)')
            ->execute([self::$categoryId, $sku]);
        return (int) $pdo->lastInsertId();
    }

    private function makeUploadsDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/product-cleanup-test-' . uniqid();
        mkdir($dir, 0777, true);
        foreach ($files as $file) {
            file_put_contents($dir . '/' . $file, 'x');
        }
        return $dir;
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
