<?php
namespace App\Models;

class GalleryModel
{
    public static function albums(string $lang): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT a.id, a.slug, a.cover_image, a.sort_order,
                   COALESCE(t.name, a.slug) AS name, t.description
            FROM gallery_albums a
            LEFT JOIN gallery_album_t t ON t.album_id = a.id AND t.lang_code = :lang
            ORDER BY a.sort_order, a.id
        ');
        $stmt->execute(['lang' => $lang]);
        return $stmt->fetchAll();
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
        $imgs = $pdo->prepare('SELECT filename FROM gallery_images WHERE album_id = ? ORDER BY sort_order, id');
        $imgs->execute([$album['id']]);
        $album['images'] = $imgs->fetchAll(\PDO::FETCH_COLUMN);
        return $album;
    }

    public static function allAlbums(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query('SELECT * FROM gallery_albums ORDER BY sort_order, id')->fetchAll();
    }

    public static function findAlbumById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM gallery_albums WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $album = $stmt->fetch();
        if (!$album) return null;
        $imgs = $pdo->prepare('SELECT * FROM gallery_images WHERE album_id = ? ORDER BY sort_order, id');
        $imgs->execute([$id]);
        $album['images'] = $imgs->fetchAll();
        return $album;
    }

    public static function createAlbum(array $data): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO gallery_albums (slug, sort_order) VALUES (?, ?)');
        $stmt->execute([$data['slug'], (int) ($data['sort_order'] ?? 0)]);
        return (int) $pdo->lastInsertId();
    }

    public static function updateAlbum(int $id, array $data): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE gallery_albums SET slug = ?, sort_order = ? WHERE id = ?');
        $stmt->execute([$data['slug'], (int) ($data['sort_order'] ?? 0), $id]);
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

    public static function addImage(int $albumId, string $filename): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('INSERT INTO gallery_images (album_id, filename, sort_order) VALUES (?, ?, 0)')->execute([$albumId, $filename]);
    }

    public static function deleteImage(int $imageId): ?string
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT filename FROM gallery_images WHERE id = ?');
        $stmt->execute([$imageId]);
        $row  = $stmt->fetch();
        if (!$row) return null;
        $pdo->prepare('DELETE FROM gallery_images WHERE id = ?')->execute([$imageId]);
        return $row['filename'];
    }
}
