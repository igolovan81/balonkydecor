<?php
namespace App\Models;

class ServiceModel
{
    public static function allWithTranslation(string $lang): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT s.id, s.price_from, s.sort_order,
                   COALESCE(t.name, cs.name)               AS name,
                   COALESCE(t.description, cs.description) AS description,
                   COALESCE(t.features, cs.features)       AS features
            FROM services s
            LEFT JOIN service_t t  ON t.service_id  = s.id AND t.lang_code  = :lang
            LEFT JOIN service_t cs ON cs.service_id = s.id AND cs.lang_code = \'cs\'
            ORDER BY s.sort_order, s.id
        ');
        $stmt->execute(['lang' => $lang]);
        return $stmt->fetchAll();
    }

    public static function all(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query(
            'SELECT s.*, st.name AS name,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM services s
             LEFT JOIN service_t st ON st.service_id = s.id AND st.lang_code = \'cs\'
             LEFT JOIN users creator ON creator.id = s.created_by
             LEFT JOIN users updater ON updater.id = s.updated_by
             ORDER BY s.sort_order, s.id'
        )->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT s.*,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM services s
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
        $stmt = $pdo->prepare('INSERT INTO services (price_from, sort_order, created_by, updated_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $data['price_from'] !== null && $data['price_from'] !== '' ? (int) $data['price_from'] : null,
            (int) ($data['sort_order'] ?? 0),
            $userId,
            $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data, int $userId): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE services SET price_from = ?, sort_order = ?, updated_by = ? WHERE id = ?');
        $stmt->execute([
            $data['price_from'] !== null && $data['price_from'] !== '' ? (int) $data['price_from'] : null,
            (int) ($data['sort_order'] ?? 0),
            $userId,
            $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([$id]);
    }

    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, name, description, features FROM service_t WHERE service_id = ?');
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
            'INSERT INTO service_t (service_id, lang_code, name, description, features)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), features = VALUES(features)'
        );
        foreach ($translations as $lang => $t) {
            if (empty($t['name'])) continue;
            $stmt->execute([$id, $lang, $t['name'], $t['description'] ?? '', $t['features'] ?? '']);
        }
    }
}
