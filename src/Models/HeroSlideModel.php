<?php
namespace App\Models;

class HeroSlideModel
{
    public static function active(string $lang): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT s.id, s.image, s.cta_url, t.title, t.subtitle, t.cta_label
             FROM hero_slides s
             JOIN hero_slide_t t ON t.slide_id = s.id AND t.lang_code = ?
             WHERE s.is_active = 1
             ORDER BY s.sort_order, s.id'
        );
        $stmt->execute([$lang]);
        return $stmt->fetchAll();
    }

    public static function all(string $lang): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT s.*, t.title,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM hero_slides s
             LEFT JOIN hero_slide_t t ON t.slide_id = s.id AND t.lang_code = :lang
             LEFT JOIN users creator ON creator.id = s.created_by
             LEFT JOIN users updater ON updater.id = s.updated_by
             ORDER BY s.sort_order, s.id'
        );
        $stmt->execute(['lang' => $lang]);
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT s.*,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM hero_slides s
             LEFT JOIN users creator ON creator.id = s.created_by
             LEFT JOIN users updater ON updater.id = s.updated_by
             WHERE s.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data, int $userId): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO hero_slides (image, cta_url, is_active, sort_order, created_by, updated_by)
             VALUES (:image, :cta_url, :is_active, :sort_order, :created_by, :updated_by)'
        );
        $stmt->execute([
            'image'      => $data['image'] ?? null,
            'cta_url'    => trim((string) ($data['cta_url'] ?? '')) ?: '/shop',
            'is_active'  => (int) ($data['is_active'] ?? 1),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data, int $userId): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE hero_slides SET image = :image, cta_url = :cta_url, is_active = :is_active,
                                     sort_order = :sort_order, updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'image'      => $data['image'] ?? null,
            'cta_url'    => trim((string) ($data['cta_url'] ?? '')) ?: '/shop',
            'is_active'  => (int) ($data['is_active'] ?? 1),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'updated_by' => $userId,
            'id'         => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM hero_slides WHERE id = ?')->execute([$id]);
    }

    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, title, subtitle, cta_label FROM hero_slide_t WHERE slide_id = ?');
        $stmt->execute([$id]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['lang_code']] = $row;
        }
        return $result;
    }

    public static function setTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO hero_slide_t (slide_id, lang_code, title, subtitle, cta_label)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), subtitle = VALUES(subtitle), cta_label = VALUES(cta_label)'
        );
        foreach ($translations as $lang => $fields) {
            $stmt->execute([
                $id,
                $lang,
                trim((string) ($fields['title'] ?? '')),
                trim((string) ($fields['subtitle'] ?? '')) ?: null,
                trim((string) ($fields['cta_label'] ?? '')),
            ]);
        }
    }
}
