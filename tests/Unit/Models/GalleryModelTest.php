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
        $this->assertIsArray($album['images']);
    }

    public function test_album_returns_null_for_unknown(): void
    {
        $this->assertNull(GalleryModel::album('no-such-album', 'en'));
    }

    public function test_set_album_translations_stores_meta_fields(): void
    {
        $pdo = Database::getConnection();
        $id  = $pdo->query("SELECT id FROM gallery_albums WHERE slug='test-album'")->fetch()['id'];
        GalleryModel::setAlbumTranslations($id, [
            'en' => ['name' => 'Test Album', 'meta_title' => 'Our Test Album', 'meta_desc' => 'Photos from our test album.'],
        ]);
        $translations = GalleryModel::getAlbumTranslations($id);
        $this->assertSame('Our Test Album', $translations['en']['meta_title']);
        $this->assertSame('Photos from our test album.', $translations['en']['meta_desc']);
    }

    public function test_album_read_includes_meta_fields(): void
    {
        $pdo = Database::getConnection();
        $id  = $pdo->query("SELECT id FROM gallery_albums WHERE slug='test-album'")->fetch()['id'];
        GalleryModel::setAlbumTranslations($id, [
            'en' => ['name' => 'Test Album', 'meta_title' => 'Our Test Album', 'meta_desc' => 'Photos from our test album.'],
        ]);
        $album = GalleryModel::album('test-album', 'en');
        $this->assertSame('Our Test Album', $album['meta_title']);
        $this->assertSame('Photos from our test album.', $album['meta_desc']);
    }

    public function test_add_image_defaults_to_image_media_type(): void
    {
        $pdo = Database::getConnection();
        $id  = $pdo->query("SELECT id FROM gallery_albums WHERE slug='test-album'")->fetch()['id'];
        GalleryModel::addImage($id, 'default-type-test.jpg');
        $row = $pdo->query("SELECT media_type FROM gallery_images WHERE album_id={$id} AND filename='default-type-test.jpg'")->fetch();
        $this->assertSame('image', $row['media_type']);
    }

    public function test_add_image_stores_video_media_type(): void
    {
        $pdo = Database::getConnection();
        $id  = $pdo->query("SELECT id FROM gallery_albums WHERE slug='test-album'")->fetch()['id'];
        GalleryModel::addImage($id, 'clip-test.mp4', 'video');
        $row = $pdo->query("SELECT media_type FROM gallery_images WHERE album_id={$id} AND filename='clip-test.mp4'")->fetch();
        $this->assertSame('video', $row['media_type']);
    }

    public function test_delete_image_returns_media_type(): void
    {
        $pdo = Database::getConnection();
        $id  = $pdo->query("SELECT id FROM gallery_albums WHERE slug='test-album'")->fetch()['id'];
        GalleryModel::addImage($id, 'delete-me-test.mp4', 'video');
        $imageId = $pdo->query("SELECT id FROM gallery_images WHERE album_id={$id} AND filename='delete-me-test.mp4'")->fetch()['id'];

        $result = GalleryModel::deleteImage($imageId);

        $this->assertSame('delete-me-test.mp4', $result['filename']);
        $this->assertSame('video', $result['media_type']);
        $row = $pdo->query("SELECT id FROM gallery_images WHERE id={$imageId}")->fetch();
        $this->assertFalse($row);
    }
}
