<?php
namespace Tests\Unit\Models;

use App\Models\CategoryModel;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

class CategoryModelTest extends TestCase
{
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO categories (slug, sort_order) VALUES ('test-cat', 99)");
        $pdo->exec("INSERT IGNORE INTO category_t (category_id, lang_code, name)
                    SELECT id, 'en', 'Test Category' FROM categories WHERE slug='test-cat'");

        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('category-audit-test@example.com', 'x', 'editor')");
        self::$userId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='category-audit-test@example.com'"
        )->fetch()['id'];
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

    public function test_slugify_converts_name_to_kebab_case(): void
    {
        $this->assertSame('summer-party-decorations', CategoryModel::slugify('Summer party decorations'));
    }

    public function test_slugify_collapses_punctuation_and_symbols(): void
    {
        $this->assertSame('foo-bar', CategoryModel::slugify('  Foo!!  ---  Bar??  '));
    }

    public function test_slugify_falls_back_to_category_when_empty(): void
    {
        $this->assertSame('category', CategoryModel::slugify('   '));
        $this->assertSame('category', CategoryModel::slugify('###'));
    }

    public function test_unique_slug_returns_candidate_when_free(): void
    {
        $candidate = 'free-slug-' . uniqid();
        $this->assertSame($candidate, CategoryModel::uniqueSlug($candidate));
    }

    public function test_unique_slug_appends_suffix_on_single_collision(): void
    {
        $base = 'collide-slug-' . uniqid();
        CategoryModel::create(['slug' => $base, 'sort_order' => 0], self::$userId);
        $this->assertSame($base . '-2', CategoryModel::uniqueSlug($base));
    }

    public function test_unique_slug_appends_incrementing_suffix_on_multiple_collisions(): void
    {
        $base = 'collide-slug-' . uniqid();
        CategoryModel::create(['slug' => $base, 'sort_order' => 0], self::$userId);
        CategoryModel::create(['slug' => $base . '-2', 'sort_order' => 0], self::$userId);
        $this->assertSame($base . '-3', CategoryModel::uniqueSlug($base));
    }

    public function test_create_records_creator_and_updater(): void
    {
        $id = CategoryModel::create(['slug' => 'audit-cat-' . uniqid(), 'sort_order' => 1], self::$userId);
        $category = CategoryModel::findById($id);
        $this->assertSame(self::$userId, (int) $category['created_by']);
        $this->assertSame(self::$userId, (int) $category['updated_by']);
        $this->assertSame('category-audit-test@example.com', $category['created_by_email']);
        $this->assertSame('category-audit-test@example.com', $category['updated_by_email']);
        $this->assertNotEmpty($category['created_at']);
        $this->assertNotEmpty($category['updated_at']);
    }

    public function test_update_changes_updated_by_but_not_created_by(): void
    {
        $id = CategoryModel::create(['slug' => 'audit-cat-' . uniqid(), 'sort_order' => 1], self::$userId);

        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('category-audit-editor2@example.com', 'x', 'editor')");
        $secondUserId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='category-audit-editor2@example.com'"
        )->fetch()['id'];

        CategoryModel::update($id, ['slug' => 'audit-cat-updated-' . uniqid(), 'sort_order' => 2], $secondUserId);

        $category = CategoryModel::findById($id);
        $this->assertSame(self::$userId, (int) $category['created_by']);
        $this->assertSame($secondUserId, (int) $category['updated_by']);
    }

    public function test_all_includes_audit_columns(): void
    {
        CategoryModel::create(['slug' => 'audit-cat-' . uniqid(), 'sort_order' => 1], self::$userId);
        $rows = CategoryModel::all('cs');
        $this->assertNotEmpty($rows);
        foreach (['created_by_email', 'created_at', 'updated_by_email', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $rows[0]);
        }
    }

    public function test_all_respects_requested_language(): void
    {
        $id = CategoryModel::create(['slug' => 'lang-test-cat-' . uniqid(), 'sort_order' => 0], self::$userId);
        CategoryModel::setTranslations($id, [
            'cs' => ['name' => 'Český název', 'description' => ''],
            'en' => ['name' => 'English Name', 'description' => ''],
        ]);

        $csRow = current(array_filter(CategoryModel::all('cs'), fn ($r) => (int) $r['id'] === $id));
        $enRow = current(array_filter(CategoryModel::all('en'), fn ($r) => (int) $r['id'] === $id));

        $this->assertSame('Český název', $csRow['name']);
        $this->assertSame('English Name', $enRow['name']);
    }
}
