<?php
namespace Tests\Unit\Services;

use App\Models\Database;
use App\Services\Sitemap;
use PHPUnit\Framework\TestCase;

class SitemapTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO categories (slug) VALUES ('test-sitemap-cat')");
        $catId = $pdo->query("SELECT id FROM categories WHERE slug='test-sitemap-cat'")->fetch()['id'];
        $pdo->exec("INSERT IGNORE INTO products (category_id, sku, price, is_active) VALUES ({$catId}, 'SITEMAP-SKU-001', 9.99, 1)");
        $pdo->exec("INSERT IGNORE INTO products (category_id, sku, price, is_active) VALUES ({$catId}, 'SITEMAP-SKU-INACTIVE', 9.99, 0)");

        $pdo->exec("INSERT IGNORE INTO gallery_albums (slug) VALUES ('sitemap-test-album')");

        $pdo->exec("INSERT IGNORE INTO blog_posts (slug, status, published_at) VALUES ('sitemap-test-post', 'published', NOW())");
        $pdo->exec("INSERT IGNORE INTO blog_posts (slug, status) VALUES ('sitemap-test-draft', 'draft')");
    }

    public function test_paths_includes_static_pages(): void
    {
        $paths = Sitemap::paths();
        foreach (['/', '/shop', '/services', '/gallery', '/blog', '/contact'] as $expected) {
            $this->assertContains($expected, $paths);
        }
    }

    public function test_paths_includes_active_product(): void
    {
        $this->assertContains('/shop/SITEMAP-SKU-001', Sitemap::paths());
    }

    public function test_paths_excludes_inactive_product(): void
    {
        $this->assertNotContains('/shop/SITEMAP-SKU-INACTIVE', Sitemap::paths());
    }

    public function test_paths_includes_gallery_album(): void
    {
        $this->assertContains('/gallery/sitemap-test-album', Sitemap::paths());
    }

    public function test_paths_includes_published_blog_post(): void
    {
        $this->assertContains('/blog/sitemap-test-post', Sitemap::paths());
    }

    public function test_paths_excludes_draft_blog_post(): void
    {
        $this->assertNotContains('/blog/sitemap-test-draft', Sitemap::paths());
    }

    public function test_entries_produces_one_row_per_path_per_language(): void
    {
        $this->assertCount(count(Sitemap::paths()) * 5, Sitemap::entries());
    }

    public function test_entries_include_all_language_alternates(): void
    {
        $entries = Sitemap::entries();
        $this->assertCount(6, $entries[0]['alternates']);
    }
}
