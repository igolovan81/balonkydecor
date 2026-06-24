<?php
namespace Tests\Unit\Models;

use App\Models\GalleryModel;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

class GalleryModelTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO gallery_albums (slug) VALUES ('test-album')");
        $row = $pdo->query("SELECT id FROM gallery_albums WHERE slug='test-album'")->fetch();
        $id  = $row['id'];
        $pdo->exec("INSERT IGNORE INTO gallery_album_t (album_id, lang_code, name)
                    VALUES ({$id}, 'en', 'Test Album')");
    }

    public function test_albums_returns_array(): void
    {
        $this->assertIsArray(GalleryModel::albums('en'));
    }

    public function test_album_returns_data(): void
    {
        $album = GalleryModel::album('test-album', 'en');
        $this->assertNotNull($album);
        $this->assertSame('Test Album', $album['name']);
        $this->assertArrayHasKey('images', $album);
    }

    public function test_album_returns_null_for_unknown(): void
    {
        $this->assertNull(GalleryModel::album('no-such-album', 'en'));
    }
}
