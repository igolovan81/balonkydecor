<?php
namespace App\Models;

class CategoryModel
{
    public static function allWithTranslation(string $lang): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT c.id, c.slug, c.image, c.sort_order,
                   COALESCE(t.name, c.slug) AS name,
                   t.description
            FROM categories c
            LEFT JOIN category_t t ON t.category_id = c.id AND t.lang_code = :lang
            ORDER BY c.sort_order, c.id
        ');
        $stmt->execute(['lang' => $lang]);
        return $stmt->fetchAll();
    }

    public static function all(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query('SELECT * FROM categories ORDER BY sort_order, id')->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO categories (slug, sort_order) VALUES (?, ?)');
        $stmt->execute([$data['slug'], (int) ($data['sort_order'] ?? 0)]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE categories SET slug = ?, sort_order = ? WHERE id = ?');
        $stmt->execute([$data['slug'], (int) ($data['sort_order'] ?? 0), $id]);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
    }

    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, name, description FROM category_t WHERE category_id = ?');
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
            'INSERT INTO category_t (category_id, lang_code, name, description)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)'
        );
        foreach ($translations as $lang => $t) {
            if (empty($t['name'])) continue;
            $stmt->execute([$id, $lang, $t['name'], $t['description'] ?? '']);
        }
    }
}
