<?php
namespace Tests\Unit\Models;

use App\Models\CategoryModel;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

class CategoryModelTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO categories (slug, sort_order) VALUES ('test-cat', 99)");
        $pdo->exec("INSERT IGNORE INTO category_t (category_id, lang_code, name)
                    SELECT id, 'en', 'Test Category' FROM categories WHERE slug='test-cat'");
    }

    public function test_returns_array(): void
    {
        $result = CategoryModel::allWithTranslation('en');
        $this->assertIsArray($result);
    }

    public function test_each_row_has_expected_keys(): void
    {
        $result = CategoryModel::allWithTranslation('en');
        $this->assertNotEmpty($result);
        $row = $result[0];
        foreach (['id', 'slug', 'name'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
    }
}
