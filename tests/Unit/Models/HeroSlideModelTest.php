<?php
namespace Tests\Unit\Models;

use App\Models\Database;
use App\Models\HeroSlideModel;
use PHPUnit\Framework\TestCase;

class HeroSlideModelTest extends TestCase
{
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('hero-slide-test@example.com', 'x', 'editor')");
        self::$userId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='hero-slide-test@example.com'"
        )->fetch()['id'];
    }

    private function makeSlide(array $overrides = []): int
    {
        return HeroSlideModel::create(array_merge([
            'image'      => null,
            'cta_url'    => '/shop',
            'is_active'  => 1,
            'sort_order' => 0,
        ], $overrides), self::$userId);
    }

    public function test_create_records_creator_and_updater(): void
    {
        $id    = $this->makeSlide();
        $slide = HeroSlideModel::findById($id);
        $this->assertSame(self::$userId, (int) $slide['created_by']);
        $this->assertSame(self::$userId, (int) $slide['updated_by']);
        $this->assertSame('hero-slide-test@example.com', $slide['created_by_email']);
        $this->assertSame('/shop', $slide['cta_url']);
    }

    public function test_update_changes_updated_by_but_not_created_by(): void
    {
        $id = $this->makeSlide();

        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('hero-slide-editor2@example.com', 'x', 'editor')");
        $secondUserId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='hero-slide-editor2@example.com'"
        )->fetch()['id'];

        HeroSlideModel::update($id, [
            'image' => null, 'cta_url' => '/services', 'is_active' => 1, 'sort_order' => 5,
        ], $secondUserId);

        $slide = HeroSlideModel::findById($id);
        $this->assertSame(self::$userId, (int) $slide['created_by']);
        $this->assertSame($secondUserId, (int) $slide['updated_by']);
        $this->assertSame('/services', $slide['cta_url']);
        $this->assertSame(5, (int) $slide['sort_order']);
    }

    public function test_set_and_get_translations_upserts(): void
    {
        $id = $this->makeSlide();
        HeroSlideModel::setTranslations($id, [
            'en' => ['title' => 'Hello', 'subtitle' => 'World', 'cta_label' => 'Go'],
        ]);
        HeroSlideModel::setTranslations($id, [
            'en' => ['title' => 'Updated', 'subtitle' => 'World', 'cta_label' => 'Go'],
        ]);
        $translations = HeroSlideModel::getTranslations($id);
        $this->assertSame('Updated', $translations['en']['title']);
    }

    public function test_active_returns_only_active_slides_ordered_by_sort_order(): void
    {
        $activeId   = $this->makeSlide(['is_active' => 1, 'sort_order' => 1]);
        $inactiveId = $this->makeSlide(['is_active' => 0, 'sort_order' => 2]);
        HeroSlideModel::setTranslations($activeId,   ['en' => ['title' => 'Active',   'subtitle' => '', 'cta_label' => 'Go']]);
        HeroSlideModel::setTranslations($inactiveId, ['en' => ['title' => 'Inactive', 'subtitle' => '', 'cta_label' => 'Go']]);

        $ids = array_column(HeroSlideModel::active('en'), 'id');
        $this->assertContains($activeId, $ids);
        $this->assertNotContains($inactiveId, $ids);
    }

    public function test_active_excludes_slide_without_translation_for_requested_lang(): void
    {
        $id = $this->makeSlide(['is_active' => 1]);
        HeroSlideModel::setTranslations($id, ['en' => ['title' => 'English only', 'subtitle' => '', 'cta_label' => 'Go']]);

        $ids = array_column(HeroSlideModel::active('sk'), 'id');
        $this->assertNotContains($id, $ids);
    }

    public function test_delete_cascades_translations(): void
    {
        $id = $this->makeSlide();
        HeroSlideModel::setTranslations($id, ['en' => ['title' => 'Bye', 'subtitle' => '', 'cta_label' => 'Go']]);
        HeroSlideModel::delete($id);

        $this->assertNull(HeroSlideModel::findById($id));
        $this->assertEmpty(HeroSlideModel::getTranslations($id));
    }
}
