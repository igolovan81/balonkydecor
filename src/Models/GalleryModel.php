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
                   COALESCE(t.name, a.slug) AS name, t.description
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
}
