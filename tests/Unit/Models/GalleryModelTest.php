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

    public function test_albums_cover_falls_back_to_first_image(): void
    {
        $slug = $this->makeAlbum();
        $id   = $this->albumId($slug);
        GalleryModel::addImage($id, "{$slug}-a.jpg");
        GalleryModel::addImage($id, "{$slug}-b.jpg");

        $album = $this->findAlbum($slug);
        $this->assertSame("{$slug}-a.jpg", $album['cover_file']);
        $this->assertSame(0, (int) $album['cover_is_video']);
    }

    public function test_albums_cover_prefers_image_over_video(): void
    {
        $slug = $this->makeAlbum();
        $id   = $this->albumId($slug);
        GalleryModel::addImage($id, "{$slug}.mp4", 'video');
        GalleryModel::addImage($id, "{$slug}.jpg");

        $album = $this->findAlbum($slug);
        $this->assertSame("{$slug}.jpg", $album['cover_file']);
        $this->assertSame(0, (int) $album['cover_is_video']);
    }

    public function test_albums_video_only_album_uses_video_cover(): void
    {
        $slug = $this->makeAlbum();
        GalleryModel::addImage($this->albumId($slug), "{$slug}.mp4", 'video');

        $album = $this->findAlbum($slug);
        $this->assertSame("{$slug}.mp4", $album['cover_file']);
        $this->assertSame(1, (int) $album['cover_is_video']);
    }

    public function test_albums_explicit_cover_wins(): void
    {
        $slug = $this->makeAlbum();
        $id   = $this->albumId($slug);
        Database::getConnection()
            ->prepare('UPDATE gallery_albums SET cover_image = ? WHERE id = ?')
            ->execute(["{$slug}-cover.jpg", $id]);
        GalleryModel::addImage($id, "{$slug}-other.jpg");

        $album = $this->findAlbum($slug);
        $this->assertSame("{$slug}-cover.jpg", $album['cover_file']);
        $this->assertSame(0, (int) $album['cover_is_video']);
    }

    public function test_albums_empty_album_has_null_cover(): void
    {
        $slug  = $this->makeAlbum();
        $album = $this->findAlbum($slug);
        $this->assertNull($album['cover_file']);
    }

    private function makeAlbum(): string
    {
        $slug = 'cover-test-' . uniqid();
        Database::getConnection()
            ->prepare('INSERT INTO gallery_albums (slug) VALUES (?)')
            ->execute([$slug]);
        return $slug;
    }

    private function albumId(string $slug): int
    {
        $stmt = Database::getConnection()->prepare('SELECT id FROM gallery_albums WHERE slug = ?');
        $stmt->execute([$slug]);
        return (int) $stmt->fetch()['id'];
    }

    private function findAlbum(string $slug): array
    {
        foreach (GalleryModel::albums('en') as $album) {
            if ($album['slug'] === $slug) {
                return $album;
            }
        }
        $this->fail("Album {$slug} not returned by albums()");
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
