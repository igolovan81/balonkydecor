<?php
namespace App\Models;

class GalleryModel
{
    public static function albums(string $lang): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT a.id, a.slug, a.cover_image, a.sort_order,
                   COALESCE(t.name, a.slug) AS name, t.description,
                   COALESCE(NULLIF(a.cover_image, ''),
                            (SELECT gi.filename FROM gallery_images gi WHERE gi.album_id = a.id
                             ORDER BY (gi.media_type = 'video'), gi.sort_order, gi.id LIMIT 1)) AS cover_file,
                   CASE WHEN NULLIF(a.cover_image, '') IS NULL
                             AND (SELECT gi.media_type FROM gallery_images gi WHERE gi.album_id = a.id
                                  ORDER BY (gi.media_type = 'video'), gi.sort_order, gi.id LIMIT 1) = 'video'
                        THEN 1 ELSE 0 END AS cover_is_video,
                   (SELECT COUNT(*) FROM gallery_images gi WHERE gi.album_id = a.id AND gi.media_type = 'image') AS photo_count,
                   (SELECT COUNT(*) FROM gallery_images gi WHERE gi.album_id = a.id AND gi.media_type = 'video') AS video_count
            FROM gallery_albums a
            LEFT JOIN gallery_album_t t ON t.album_id = a.id AND t.lang_code = :lang
            ORDER BY a.sort_order, a.id
        ");
        $stmt->execute(['lang' => $lang]);
        return $stmt->fetchAll();
    }

    /**
     * Ensure the album's cover points at a file that exists in $uploadsDir.
     * Falls back to the album's first on-disk item (photos preferred), or null.
     */
    public static function resolveCover(array $album, string $uploadsDir): array
    {
        if ($album['cover_file'] !== null && is_file($uploadsDir . '/' . $album['cover_file'])) {
            return $album;
        }
        $album['cover_file']     = null;
        $album['cover_is_video'] = 0;

        $stmt = Database::getConnection()->prepare(
            "SELECT filename, media_type FROM gallery_images WHERE album_id = ?
             ORDER BY (media_type = 'video'), sort_order, id"
        );
        $stmt->execute([$album['id']]);
        foreach ($stmt->fetchAll() as $item) {
            if (is_file($uploadsDir . '/' . $item['filename'])) {
                $album['cover_file']     = $item['filename'];
                $album['cover_is_video'] = $item['media_type'] === 'video' ? 1 : 0;
                break;
            }
        }
        return $album;
    }

    /**
     * Find gallery DB rows whose files no longer exist in $uploadsDir:
     * ['images' => gallery_images rows, 'covers' => albums with a stale explicit cover_image].
     */
    public static function orphanedMedia(string $uploadsDir): array
    {
        $pdo     = Database::getConnection();
        $orphans = ['images' => [], 'covers' => []];

        $rows = $pdo->query('SELECT id, album_id, filename, media_type FROM gallery_images ORDER BY album_id, id')->fetchAll();
        foreach ($rows as $row) {
            if (!is_file($uploadsDir . '/' . $row['filename'])) {
                $orphans['images'][] = $row;
            }
        }

        $albums = $pdo->query("SELECT id, slug, cover_image FROM gallery_albums WHERE cover_image IS NOT NULL AND cover_image <> ''")->fetchAll();
        foreach ($albums as $album) {
            if (!is_file($uploadsDir . '/' . $album['cover_image'])) {
                $orphans['covers'][] = $album;
            }
        }
        return $orphans;
    }

    /**
     * Delete orphaned gallery_images rows (plus leftover thumb_ files) and clear
     * stale explicit covers. Returns ['deleted_images' => filenames, 'cleared_covers' => slugs].
     */
    public static function cleanupOrphans(string $uploadsDir): array
    {
        $pdo     = Database::getConnection();
        $orphans = self::orphanedMedia($uploadsDir);
        $report  = ['deleted_images' => [], 'cleared_covers' => []];

        $deleteRow = $pdo->prepare('DELETE FROM gallery_images WHERE id = ?');
        foreach ($orphans['images'] as $row) {
            $deleteRow->execute([$row['id']]);
            $thumb = $uploadsDir . '/thumb_' . $row['filename'];
            if ($row['media_type'] === 'image' && is_file($thumb)) {
                unlink($thumb);
            }
            $report['deleted_images'][] = $row['filename'];
        }

        $clearCover = $pdo->prepare('UPDATE gallery_albums SET cover_image = NULL WHERE id = ?');
        foreach ($orphans['covers'] as $album) {
            $clearCover->execute([$album['id']]);
            $report['cleared_covers'][] = $album['slug'];
        }
        return $report;
    }

    public static function album(string $slug, string $lang): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT a.id, a.slug, a.cover_image,
                   COALESCE(t.name, a.slug) AS name, t.description, t.meta_title, t.meta_desc
            FROM gallery_albums a
            LEFT JOIN gallery_album_t t ON t.album_id = a.id AND t.lang_code = :lang
            WHERE a.slug = :slug
        ');
        $stmt->execute(['slug' => $slug, 'lang' => $lang]);
        $album = $stmt->fetch();
        if (!$album) {
            return null;
        }
        $imgs = $pdo->prepare('SELECT id, filename, media_type FROM gallery_images WHERE album_id = ? ORDER BY sort_order, id');
        $imgs->execute([$album['id']]);
        $album['images'] = $imgs->fetchAll();
        return $album;
    }

    public static function allAlbums(string $lang): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT a.*, COALESCE(t.name, cs.name, a.slug) AS name,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM gallery_albums a
             LEFT JOIN gallery_album_t t  ON t.album_id = a.id AND t.lang_code  = :lang
             LEFT JOIN gallery_album_t cs ON cs.album_id = a.id AND cs.lang_code = \'cs\'
             LEFT JOIN users creator ON creator.id = a.created_by
             LEFT JOIN users updater ON updater.id = a.updated_by
             ORDER BY a.sort_order, a.id'
        );
        $stmt->execute(['lang' => $lang]);
        return $stmt->fetchAll();
    }

    public static function findAlbumById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT a.*,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM gallery_albums a
             LEFT JOIN users creator ON creator.id = a.created_by
             LEFT JOIN users updater ON updater.id = a.updated_by
             WHERE a.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $album = $stmt->fetch();
        if (!$album) return null;
        $imgs = $pdo->prepare('SELECT * FROM gallery_images WHERE album_id = ? ORDER BY sort_order, id');
        $imgs->execute([$id]);
        $album['images'] = $imgs->fetchAll();
        return $album;
    }

    public static function createAlbum(array $data, int $userId): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO gallery_albums (slug, sort_order, created_by, updated_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$data['slug'], (int) ($data['sort_order'] ?? 0), $userId, $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function updateAlbum(int $id, array $data, int $userId): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE gallery_albums SET slug = ?, sort_order = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$data['slug'], (int) ($data['sort_order'] ?? 0), $userId, $id]);
    }

    public static function deleteAlbum(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM gallery_albums WHERE id = ?')->execute([$id]);
    }

    public static function getAlbumTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, name, description, meta_title, meta_desc FROM gallery_album_t WHERE album_id = ?');
        $stmt->execute([$id]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['lang_code']] = $row;
        }
        return $result;
    }

    public static function setAlbumTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO gallery_album_t (album_id, lang_code, name, description, meta_title, meta_desc)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
                                     meta_title = VALUES(meta_title), meta_desc = VALUES(meta_desc)'
        );
        foreach ($translations as $lang => $t) {
            if (empty($t['name'])) continue;
            $stmt->execute([$id, $lang, $t['name'], $t['description'] ?? '', $t['meta_title'] ?? null, $t['meta_desc'] ?? null]);
        }
    }

    public static function addImage(int $albumId, string $filename, string $mediaType = 'image'): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('INSERT INTO gallery_images (album_id, filename, media_type, sort_order) VALUES (?, ?, ?, 0)')
            ->execute([$albumId, $filename, $mediaType]);
    }

    public static function deleteImage(int $imageId): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT filename, media_type FROM gallery_images WHERE id = ?');
        $stmt->execute([$imageId]);
        $row  = $stmt->fetch();
        if (!$row) return null;
        $pdo->prepare('DELETE FROM gallery_images WHERE id = ?')->execute([$imageId]);
        return $row;
    }
}
