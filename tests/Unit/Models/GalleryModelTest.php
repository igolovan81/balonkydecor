<?php
namespace Tests\Unit\Models;

use App\Models\GalleryModel;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

class GalleryModelTest extends TestCase
{
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO gallery_albums (slug) VALUES ('test-album')");
        $row = $pdo->query("SELECT id FROM gallery_albums WHERE slug='test-album'")->fetch();
        $id  = $row['id'];
        $pdo->exec("INSERT IGNORE INTO gallery_album_t (album_id, lang_code, name)
                    VALUES ({$id}, 'en', 'Test Album')");

        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('gallery-audit-test@example.com', 'x', 'editor')");
        self::$userId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='gallery-audit-test@example.com'"
        )->fetch()['id'];
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

    public function test_create_album_records_creator_and_updater(): void
    {
        $id    = GalleryModel::createAlbum(['slug' => 'audit-album-' . uniqid(), 'sort_order' => 1], self::$userId);
        $album = GalleryModel::findAlbumById($id);
        $this->assertSame(self::$userId, (int) $album['created_by']);
        $this->assertSame(self::$userId, (int) $album['updated_by']);
        $this->assertSame('gallery-audit-test@example.com', $album['created_by_email']);
        $this->assertSame('gallery-audit-test@example.com', $album['updated_by_email']);
        $this->assertNotEmpty($album['created_at']);
        $this->assertNotEmpty($album['updated_at']);
    }

    public function test_update_album_bumps_updated_at_even_when_other_fields_unchanged(): void
    {
        $slug = 'audit-album-' . uniqid();
        $id   = GalleryModel::createAlbum(['slug' => $slug, 'sort_order' => 1], self::$userId);
        $before = GalleryModel::findAlbumById($id)['updated_at'];

        sleep(1);
        // Same slug/sort_order as at creation — simulates a save where only
        // a translation field changed. MySQL's ON UPDATE CURRENT_TIMESTAMP
        // only fires when a column value actually changes, so this must
        // not silently leave updated_at stale.
        GalleryModel::updateAlbum($id, ['slug' => $slug, 'sort_order' => 1], self::$userId);

        $after = GalleryModel::findAlbumById($id)['updated_at'];
        $this->assertGreaterThan($before, $after);
    }

    public function test_update_album_changes_updated_by_but_not_created_by(): void
    {
        $id = GalleryModel::createAlbum(['slug' => 'audit-album-' . uniqid(), 'sort_order' => 1], self::$userId);

        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('gallery-audit-editor2@example.com', 'x', 'editor')");
        $secondUserId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='gallery-audit-editor2@example.com'"
        )->fetch()['id'];

        GalleryModel::updateAlbum($id, ['slug' => 'audit-album-updated-' . uniqid(), 'sort_order' => 2], $secondUserId);

        $album = GalleryModel::findAlbumById($id);
        $this->assertSame(self::$userId, (int) $album['created_by']);
        $this->assertSame($secondUserId, (int) $album['updated_by']);
    }

    public function test_all_albums_includes_audit_columns(): void
    {
        GalleryModel::createAlbum(['slug' => 'audit-album-' . uniqid(), 'sort_order' => 1], self::$userId);
        $rows = GalleryModel::allAlbums('cs');
        $this->assertNotEmpty($rows);
        foreach (['created_by_email', 'created_at', 'updated_by_email', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $rows[0]);
        }
    }

    public function test_all_albums_respects_requested_language(): void
    {
        $slug = 'audit-album-' . uniqid();
        $id   = GalleryModel::createAlbum(['slug' => $slug, 'sort_order' => 1], self::$userId);
        GalleryModel::setAlbumTranslations($id, [
            'cs' => ['name' => 'Český název alba'],
            'en' => ['name' => 'English Album Name'],
        ]);

        $csRow = $this->findInAllAlbums($slug, 'cs');
        $enRow = $this->findInAllAlbums($slug, 'en');

        $this->assertSame('Český název alba', $csRow['name']);
        $this->assertSame('English Album Name', $enRow['name']);
    }

    public function test_all_albums_falls_back_to_slug_when_no_translation(): void
    {
        $slug = 'audit-album-' . uniqid();
        GalleryModel::createAlbum(['slug' => $slug, 'sort_order' => 1], self::$userId);

        $row = $this->findInAllAlbums($slug, 'en');
        $this->assertSame($slug, $row['name']);
    }

    private function findInAllAlbums(string $slug, string $lang): array
    {
        foreach (GalleryModel::allAlbums($lang) as $album) {
            if ($album['slug'] === $slug) {
                return $album;
            }
        }
        $this->fail("Album {$slug} not returned by allAlbums()");
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

    public function test_albums_returns_photo_and_video_counts(): void
    {
        $slug = $this->makeAlbum();
        $id   = $this->albumId($slug);
        GalleryModel::addImage($id, "{$slug}-a.jpg");
        GalleryModel::addImage($id, "{$slug}-b.jpg");
        GalleryModel::addImage($id, "{$slug}.mp4", 'video');

        $album = $this->findAlbum($slug);
        $this->assertSame(2, (int) $album['photo_count']);
        $this->assertSame(1, (int) $album['video_count']);
    }

    public function test_resolve_cover_keeps_cover_whose_file_exists(): void
    {
        $slug = $this->makeAlbum();
        GalleryModel::addImage($this->albumId($slug), "{$slug}-a.jpg");
        $dir = $this->makeUploadsDir(["{$slug}-a.jpg"]);

        $album = GalleryModel::resolveCover($this->findAlbum($slug), $dir);
        $this->assertSame("{$slug}-a.jpg", $album['cover_file']);
        $this->assertSame(0, (int) $album['cover_is_video']);
    }

    public function test_resolve_cover_falls_back_to_existing_image_file(): void
    {
        $slug = $this->makeAlbum();
        $id   = $this->albumId($slug);
        GalleryModel::addImage($id, "{$slug}-missing.jpg");
        GalleryModel::addImage($id, "{$slug}-present.jpg");
        $dir = $this->makeUploadsDir(["{$slug}-present.jpg"]);

        $album = GalleryModel::resolveCover($this->findAlbum($slug), $dir);
        $this->assertSame("{$slug}-present.jpg", $album['cover_file']);
        $this->assertSame(0, (int) $album['cover_is_video']);
    }

    public function test_resolve_cover_falls_back_to_existing_video_file(): void
    {
        $slug = $this->makeAlbum();
        $id   = $this->albumId($slug);
        GalleryModel::addImage($id, "{$slug}-missing.jpg");
        GalleryModel::addImage($id, "{$slug}.mp4", 'video');
        $dir = $this->makeUploadsDir(["{$slug}.mp4"]);

        $album = GalleryModel::resolveCover($this->findAlbum($slug), $dir);
        $this->assertSame("{$slug}.mp4", $album['cover_file']);
        $this->assertSame(1, (int) $album['cover_is_video']);
    }

    public function test_resolve_cover_nulls_cover_when_no_file_exists(): void
    {
        $slug = $this->makeAlbum();
        GalleryModel::addImage($this->albumId($slug), "{$slug}-missing.jpg");
        $dir = $this->makeUploadsDir([]);

        $album = GalleryModel::resolveCover($this->findAlbum($slug), $dir);
        $this->assertNull($album['cover_file']);
    }

    public function test_orphaned_media_finds_rows_with_missing_files(): void
    {
        $slug = $this->makeAlbum();
        $id   = $this->albumId($slug);
        GalleryModel::addImage($id, "{$slug}-present.jpg");
        GalleryModel::addImage($id, "{$slug}-gone.jpg");
        GalleryModel::addImage($id, "{$slug}-gone.mp4", 'video');
        $dir = $this->makeUploadsDir(["{$slug}-present.jpg"]);

        $orphans   = GalleryModel::orphanedMedia($dir);
        $filenames = array_column(
            array_filter($orphans['images'], fn ($r) => (int) $r['album_id'] === $id),
            'filename'
        );
        $this->assertEqualsCanonicalizing(["{$slug}-gone.jpg", "{$slug}-gone.mp4"], $filenames);
    }

    public function test_orphaned_media_flags_stale_explicit_cover(): void
    {
        $slug = $this->makeAlbum();
        $id   = $this->albumId($slug);
        Database::getConnection()
            ->prepare('UPDATE gallery_albums SET cover_image = ? WHERE id = ?')
            ->execute(["{$slug}-cover-gone.jpg", $id]);
        $dir = $this->makeUploadsDir([]);

        $orphans = GalleryModel::orphanedMedia($dir);
        $this->assertContains($slug, array_column($orphans['covers'], 'slug'));
    }

    public function test_orphaned_media_ignores_existing_files_and_empty_covers(): void
    {
        $slug = $this->makeAlbum();
        $id   = $this->albumId($slug);
        GalleryModel::addImage($id, "{$slug}-present.jpg");
        $dir = $this->makeUploadsDir(["{$slug}-present.jpg"]);

        $orphans = GalleryModel::orphanedMedia($dir);
        $this->assertSame([], array_filter($orphans['images'], fn ($r) => (int) $r['album_id'] === $id));
        $this->assertNotContains($slug, array_column($orphans['covers'], 'slug'));
    }

    // NB: cleanupOrphans() is global — in the shared dev DB it also removes other
    // fixture rows whose files don't exist on disk (they're broken anyway).
    public function test_cleanup_orphans_deletes_rows_and_clears_stale_covers(): void
    {
        $slug = $this->makeAlbum();
        $id   = $this->albumId($slug);
        $pdo  = Database::getConnection();
        GalleryModel::addImage($id, "{$slug}-present.jpg");
        GalleryModel::addImage($id, "{$slug}-gone.jpg");
        $pdo->prepare('UPDATE gallery_albums SET cover_image = ? WHERE id = ?')
            ->execute(["{$slug}-cover-gone.jpg", $id]);
        $dir = $this->makeUploadsDir(["{$slug}-present.jpg", "thumb_{$slug}-gone.jpg"]);

        $report = GalleryModel::cleanupOrphans($dir);

        $this->assertContains("{$slug}-gone.jpg", $report['deleted_images']);
        $this->assertContains($slug, $report['cleared_covers']);
        $left = $pdo->prepare('SELECT filename FROM gallery_images WHERE album_id = ?');
        $left->execute([$id]);
        $this->assertSame(["{$slug}-present.jpg"], array_column($left->fetchAll(), 'filename'));
        $cover = $pdo->prepare('SELECT cover_image FROM gallery_albums WHERE id = ?');
        $cover->execute([$id]);
        $this->assertNull($cover->fetch()['cover_image']);
        $this->assertFileDoesNotExist($dir . "/thumb_{$slug}-gone.jpg");
    }

    private function makeUploadsDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/gallery-cover-test-' . uniqid();
        mkdir($dir, 0777, true);
        foreach ($files as $file) {
            file_put_contents($dir . '/' . $file, 'x');
        }
        return $dir;
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
