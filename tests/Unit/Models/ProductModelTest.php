<?php
namespace Tests\Unit\Models;

use App\Models\ProductModel;
use App\Models\CategoryModel;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

class ProductModelTest extends TestCase
{
    private static int $categoryId;
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO categories (slug) VALUES ('test-products')");
        $row = $pdo->query("SELECT id FROM categories WHERE slug='test-products'")->fetch();
        self::$categoryId = (int) $row['id'];

        $pdo->exec("INSERT IGNORE INTO products (category_id, sku, price) VALUES (" . self::$categoryId . ", 'TEST-SKU-001', 9.99)");
        $id = $pdo->query("SELECT id FROM products WHERE sku='TEST-SKU-001'")->fetch()['id'];
        $pdo->exec("INSERT IGNORE INTO product_t (product_id, lang_code, name) VALUES ({$id}, 'en', 'Test Product')");

        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('product-audit-test@example.com', 'x', 'editor')");
        self::$userId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='product-audit-test@example.com'"
        )->fetch()['id'];
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

    private function skuOf(int $productId): string
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT sku FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        return (string) $stmt->fetchColumn();
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

    public function test_find_by_sku_resolves_subtype_names_for_requested_lang(): void
    {
        $productId = $this->makeProduct();
        $sku       = $this->skuOf($productId);
        ProductModel::setSubtypes($productId, [
            ['price' => '1.90', 't' => ['cs' => 'Makarons', 'en' => 'Macarons']],
        ]);

        $product = ProductModel::findBySku($sku, 'en');
        $this->assertCount(1, $product['subtypes']);
        $this->assertSame('Macarons', $product['subtypes'][0]['name']);
        $this->assertSame('1.90', $product['subtypes'][0]['price']);
    }

    public function test_find_by_sku_subtypes_empty_without_any(): void
    {
        $product = ProductModel::findBySku('TEST-SKU-001', 'en');
        $this->assertSame([], $product['subtypes']);
    }

    public function test_filter_by_category(): void
    {
        $result = ProductModel::allActive('en', self::$categoryId);
        $this->assertIsArray($result);
        foreach ($result as $row) {
            $this->assertSame(self::$categoryId, (int) $row['category_id']);
        }
    }

    public function test_all_active_reports_min_subtype_price_for_products_with_subtypes(): void
    {
        $productId = $this->makeProduct();
        ProductModel::setSubtypes($productId, [
            ['price' => '1.90', 't' => ['cs' => 'Makarons']],
            ['price' => '1.20', 't' => ['cs' => 'SDM']],
        ]);

        $row = $this->findActiveRow($productId);
        $this->assertSame('1.20', $row['min_subtype_price']);
    }

    public function test_all_active_min_subtype_price_is_null_without_subtypes(): void
    {
        $productId = $this->makeProduct();
        $row       = $this->findActiveRow($productId);
        $this->assertNull($row['min_subtype_price']);
    }

    private function findActiveRow(int $productId): array
    {
        foreach (ProductModel::allActive('en', self::$categoryId) as $row) {
            if ((int) $row['id'] === $productId) {
                return $row;
            }
        }
        $this->fail('Product ' . $productId . ' not found in allActive() results');
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

    public function test_set_subtypes_creates_and_returns_translated_rows(): void
    {
        $productId = $this->makeProduct();
        ProductModel::setSubtypes($productId, [
            ['price' => '1.90', 't' => ['cs' => 'Makarons', 'en' => 'Macarons']],
            ['price' => '3.40', 't' => ['cs' => 'Chrom', 'en' => 'Chrome']],
        ]);

        $product = ProductModel::findById($productId);
        $this->assertCount(2, $product['subtypes']);
        $this->assertSame('1.90', $product['subtypes'][0]['price']);
        $this->assertSame('Makarons', $product['subtypes'][0]['t']['cs']);
        $this->assertSame('Chrome', $product['subtypes'][1]['t']['en']);
    }

    public function test_set_subtypes_replaces_existing_rows(): void
    {
        $productId = $this->makeProduct();
        ProductModel::setSubtypes($productId, [
            ['price' => '1.00', 't' => ['cs' => 'A']],
            ['price' => '2.00', 't' => ['cs' => 'B']],
        ]);
        ProductModel::setSubtypes($productId, [
            ['price' => '3.00', 't' => ['cs' => 'C']],
        ]);

        $subtypes = ProductModel::getSubtypes($productId);
        $this->assertCount(1, $subtypes);
        $this->assertSame('C', $subtypes[0]['t']['cs']);
    }

    public function test_set_subtypes_skips_rows_with_no_names(): void
    {
        $productId = $this->makeProduct();
        ProductModel::setSubtypes($productId, [
            ['price' => '1.00', 't' => ['cs' => '', 'en' => '']],
            ['price' => '2.00', 't' => ['cs' => 'Valid']],
        ]);

        $subtypes = ProductModel::getSubtypes($productId);
        $this->assertCount(1, $subtypes);
        $this->assertSame('Valid', $subtypes[0]['t']['cs']);
    }

    public function test_slugify_converts_name_to_kebab_case(): void
    {
        $this->assertSame('latex-balloons-kitten-50-pcs', ProductModel::slugify('Latex balloons kitten 50 pcs'));
    }

    public function test_slugify_collapses_punctuation_and_symbols(): void
    {
        $this->assertSame('foo-bar', ProductModel::slugify('  Foo!!  ---  Bar??  '));
    }

    public function test_slugify_falls_back_to_product_when_empty(): void
    {
        $this->assertSame('product', ProductModel::slugify('   '));
        $this->assertSame('product', ProductModel::slugify('###'));
    }

    public function test_unique_sku_returns_candidate_when_free(): void
    {
        $candidate = 'free-sku-' . uniqid();
        $this->assertSame($candidate, ProductModel::uniqueSku($candidate));
    }

    public function test_unique_sku_appends_suffix_on_single_collision(): void
    {
        $base = 'collide-sku-' . uniqid();
        ProductModel::create(['sku' => $base, 'price' => 9.99, 'category_id' => self::$categoryId], self::$userId);
        $this->assertSame($base . '-2', ProductModel::uniqueSku($base));
    }

    public function test_unique_sku_appends_incrementing_suffix_on_multiple_collisions(): void
    {
        $base = 'collide-sku-' . uniqid();
        ProductModel::create(['sku' => $base, 'price' => 9.99, 'category_id' => self::$categoryId], self::$userId);
        ProductModel::create(['sku' => $base . '-2', 'price' => 9.99, 'category_id' => self::$categoryId], self::$userId);
        $this->assertSame($base . '-3', ProductModel::uniqueSku($base));
    }

    public function test_create_persists_limited_stock(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-STOCK-' . uniqid(),
            'price'       => 19.99,
            'category_id' => self::$categoryId,
            'stock_type'  => 'limited',
            'stock_qty'   => 5,
        ], self::$userId);
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
        ], self::$userId);
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
        ], self::$userId);
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
        ], self::$userId);
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
        ], self::$userId);
        ProductModel::update($id, [
            'sku'         => 'TEST-STOCK-UPDATED-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
            'stock_type'  => 'limited',
            'stock_qty'   => 3,
        ], self::$userId);
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
        ], self::$userId);

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
        ], self::$userId);

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

    public function test_delete_image_promotes_new_primary_when_primary_is_deleted(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-IMG-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
        ], self::$userId);

        ProductModel::addImage($id, 'first.jpg', false);  // becomes primary
        ProductModel::addImage($id, 'second.jpg', false);

        $product   = ProductModel::findById($id);
        $primaryId = array_values(array_filter(
            $product['images'],
            fn ($img) => (int) $img['is_primary'] === 1
        ))[0]['id'];

        ProductModel::deleteImage((int) $primaryId);

        $remaining = ProductModel::findById($id)['images'];
        $this->assertCount(1, $remaining);
        $this->assertSame('second.jpg', $remaining[0]['filename']);
        $this->assertSame(1, (int) $remaining[0]['is_primary']);
    }

    public function test_create_records_creator_and_updater(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-AUDIT-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
        ], self::$userId);

        $product = ProductModel::findById($id);
        $this->assertSame(self::$userId, (int) $product['created_by']);
        $this->assertSame(self::$userId, (int) $product['updated_by']);
        $this->assertSame('product-audit-test@example.com', $product['created_by_email']);
        $this->assertSame('product-audit-test@example.com', $product['updated_by_email']);
        $this->assertNotEmpty($product['updated_at']);
    }

    public function test_update_changes_updated_by_but_not_created_by(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-AUDIT-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
        ], self::$userId);

        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('product-audit-editor2@example.com', 'x', 'editor')");
        $secondUserId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='product-audit-editor2@example.com'"
        )->fetch()['id'];

        ProductModel::update($id, [
            'sku'         => 'TEST-AUDIT-UPDATED-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
        ], $secondUserId);

        $product = ProductModel::findById($id);
        $this->assertSame(self::$userId, (int) $product['created_by']);
        $this->assertSame($secondUserId, (int) $product['updated_by']);
    }

    public function test_all_includes_audit_columns(): void
    {
        ProductModel::create([
            'sku'         => 'TEST-AUDIT-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
        ], self::$userId);

        $rows = ProductModel::all('cs');
        $this->assertNotEmpty($rows);
        foreach (['created_by_email', 'updated_by_email', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $rows[0]);
        }
    }

    public function test_all_respects_requested_language_for_category_name(): void
    {
        $catId = CategoryModel::create(['slug' => 'lang-test-cat-' . uniqid(), 'sort_order' => 0], self::$userId);
        CategoryModel::setTranslations($catId, [
            'cs' => ['name' => 'Český název kategorie', 'description' => ''],
            'en' => ['name' => 'English Category Name', 'description' => ''],
        ]);

        $id = ProductModel::create([
            'sku'         => 'TEST-LANG-' . uniqid(),
            'price'       => 9.99,
            'category_id' => $catId,
        ], self::$userId);

        $csRow = current(array_filter(ProductModel::all('cs'), fn ($r) => (int) $r['id'] === $id));
        $enRow = current(array_filter(ProductModel::all('en'), fn ($r) => (int) $r['id'] === $id));

        $this->assertSame('Český název kategorie', $csRow['category_name']);
        $this->assertSame('English Category Name', $enRow['category_name']);
    }

    public function test_all_includes_product_name_respecting_language(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-NAME-' . uniqid(),
            'price'       => 9.99,
            'category_id' => self::$categoryId,
        ], self::$userId);
        ProductModel::setTranslations($id, [
            'cs' => ['name' => 'Český název produktu'],
            'en' => ['name' => 'English Product Name'],
        ]);

        $csRow = current(array_filter(ProductModel::all('cs'), fn ($r) => (int) $r['id'] === $id));
        $enRow = current(array_filter(ProductModel::all('en'), fn ($r) => (int) $r['id'] === $id));

        $this->assertSame('Český název produktu', $csRow['name']);
        $this->assertSame('English Product Name', $enRow['name']);
    }

    public function test_all_falls_back_to_sku_when_no_translation(): void
    {
        $sku = 'TEST-NONAME-' . uniqid();
        $id  = ProductModel::create([
            'sku'         => $sku,
            'price'       => 9.99,
            'category_id' => self::$categoryId,
        ], self::$userId);

        $row = current(array_filter(ProductModel::all('en'), fn ($r) => (int) $r['id'] === $id));
        $this->assertSame($sku, $row['name']);
    }

    public function test_clone_copies_translations_and_creates_inactive_product(): void
    {
        $id = ProductModel::create([
            'sku'         => 'TEST-CLONE-' . uniqid(),
            'price'       => 24.50,
            'category_id' => self::$categoryId,
            'is_active'   => 1,
            'stock_type'  => 'limited',
            'stock_qty'   => 7,
        ], self::$userId);
        ProductModel::setTranslations($id, [
            'cs' => ['name' => 'Balónek modrý', 'description' => 'Popis', 'meta_title' => 'Meta CS', 'meta_desc' => 'Desc CS'],
            'en' => ['name' => 'Blue Balloon', 'description' => 'Description', 'meta_title' => 'Meta EN', 'meta_desc' => 'Desc EN'],
        ]);

        $newId = ProductModel::clone($id, self::$userId);

        $this->assertNotNull($newId);
        $this->assertNotSame($id, $newId);

        $original = ProductModel::findById($id);
        $clone    = ProductModel::findById($newId);

        $this->assertNotSame($original['sku'], $clone['sku']);
        $this->assertEquals(24.50, (float) $clone['price']);
        $this->assertSame(self::$categoryId, (int) $clone['category_id']);
        $this->assertSame('limited', $clone['stock_type']);
        $this->assertSame(7, (int) $clone['stock_qty']);
        $this->assertSame(0, (int) $clone['is_active']);
        $this->assertSame([], $clone['images']);

        $cloneTranslations = ProductModel::getTranslations($newId);
        $this->assertSame('Blue Balloon', $cloneTranslations['en']['name']);
        $this->assertSame('Meta EN', $cloneTranslations['en']['meta_title']);
        $this->assertSame('Balónek modrý', $cloneTranslations['cs']['name']);
    }

    public function test_clone_generates_unique_sku_on_collision(): void
    {
        $base = 'CLONE-SKU-' . uniqid();
        $id   = ProductModel::create([
            'sku'         => $base,
            'price'       => 9.99,
            'category_id' => self::$categoryId,
        ], self::$userId);

        $firstCloneId  = ProductModel::clone($id, self::$userId);
        $secondCloneId = ProductModel::clone($id, self::$userId);

        $firstSku  = ProductModel::findById($firstCloneId)['sku'];
        $secondSku = ProductModel::findById($secondCloneId)['sku'];

        $this->assertSame($base . '-2', $firstSku);
        $this->assertSame($base . '-3', $secondSku);
    }

    public function test_clone_returns_null_for_missing_product(): void
    {
        $this->assertNull(ProductModel::clone(999999999, self::$userId));
    }
}
