<?php
namespace Tests\Unit\Models;

use App\Models\BlogModel;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

class BlogModelTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO blog_posts (slug, status, published_at)
                    VALUES ('test-post', 'published', NOW())");
        $row = $pdo->query("SELECT id FROM blog_posts WHERE slug='test-post'")->fetch();
        $id  = $row['id'];
        $pdo->exec("INSERT IGNORE INTO blog_post_t (post_id, lang_code, title, body)
                    VALUES ({$id}, 'en', 'Test Post', '<p>Hello</p>')");
    }

    public function test_published_returns_structure(): void
    {
        $result = BlogModel::published('en');
        $this->assertArrayHasKey('posts', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('pages', $result);
    }

    public function test_find_by_slug_returns_post(): void
    {
        $post = BlogModel::findBySlug('test-post', 'en');
        $this->assertNotNull($post);
        $this->assertSame('Test Post', $post['title']);
    }

    public function test_find_by_slug_returns_null_for_draft(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO blog_posts (slug, status) VALUES ('draft-post', 'draft')");
        $this->assertNull(BlogModel::findBySlug('draft-post', 'en'));
    }
}
