<?php
namespace Tests\Unit\Models;

use App\Models\PageModel;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

class PageModelTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO page_t (page_id, lang_code, title, body)
                    SELECT id, 'en', 'Services', '<p>Our services</p>'
                    FROM pages WHERE slug='services'");
    }

    public function test_find_returns_page(): void
    {
        $page = PageModel::find('services', 'en');
        $this->assertNotNull($page);
        $this->assertSame('Services', $page['title']);
    }

    public function test_find_returns_null_for_unknown(): void
    {
        $this->assertNull(PageModel::find('nonexistent-page', 'en'));
    }

    public function test_upsert_stores_meta_fields(): void
    {
        PageModel::upsert('services', 'en', 'Services', '<p>Our services</p>', 'Our Balloon Services', 'Book balloon decoration services.');
        $page = PageModel::find('services', 'en');
        $this->assertSame('Our Balloon Services', $page['meta_title']);
        $this->assertSame('Book balloon decoration services.', $page['meta_desc']);
    }

    public function test_all_translations_includes_meta_fields(): void
    {
        PageModel::upsert('services', 'en', 'Services', '<p>Our services</p>', 'Our Balloon Services', 'Book balloon decoration services.');
        $translations = PageModel::allTranslations('services');
        $this->assertSame('Our Balloon Services', $translations['en']['meta_title']);
        $this->assertSame('Book balloon decoration services.', $translations['en']['meta_desc']);
    }
}
