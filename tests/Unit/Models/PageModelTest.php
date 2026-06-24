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
}
