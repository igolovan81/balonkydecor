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

    public function test_set_translations_stores_legal_notice(): void
    {
        $pdo = Database::getConnection();
        $id  = $pdo->query("SELECT id FROM categories WHERE slug='test-cat'")->fetch()['id'];
        CategoryModel::setTranslations($id, [
            'en' => ['name' => 'Test Category', 'legal_notice' => 'Test warning text.'],
        ]);
        $translations = CategoryModel::getTranslations($id);
        $this->assertSame('Test warning text.', $translations['en']['legal_notice']);
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

    public function test_update_bumps_updated_at_even_when_other_fields_unchanged(): void
    {
        $slug = 'audit-cat-' . uniqid();
        $id   = CategoryModel::create(['slug' => $slug, 'sort_order' => 1], self::$userId);
        $before = CategoryModel::findById($id)['updated_at'];

        sleep(1);
        // Same slug/sort_order as at creation — simulates a save where only
        // a translation field changed. MySQL's ON UPDATE CURRENT_TIMESTAMP
        // only fires when a column value actually changes, so this must
        // not silently leave updated_at stale.
        CategoryModel::update($id, ['slug' => $slug, 'sort_order' => 1], self::$userId);

        $after = CategoryModel::findById($id)['updated_at'];
        $this->assertGreaterThan($before, $after);
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

    public function test_withProductCounts_reflects_products_in_category(): void
    {
        $pdo  = Database::getConnection();
        $slug = 'test-cat-counts-' . uniqid();
        $pdo->prepare('INSERT INTO categories (slug) VALUES (?)')->execute([$slug]);
        $catId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO category_t (category_id, lang_code, name) VALUES (?, 'en', 'Counts Category')")
            ->execute([$catId]);

        foreach (range(1, 3) as $i) {
            $pdo->prepare('INSERT INTO products (category_id, sku, price) VALUES (?, ?, 9.99)')
                ->execute([$catId, 'COUNT-TEST-' . $i . '-' . uniqid()]);
        }

        $rows = CategoryModel::withProductCounts('en');
        $row  = current(array_filter($rows, fn ($r) => $r['id'] === $catId));

        $this->assertNotFalse($row);
        $this->assertSame('Counts Category', $row['name']);
        $this->assertSame(3, $row['product_count']);
    }
}
