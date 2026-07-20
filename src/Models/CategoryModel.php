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

    public static function all(string $lang): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT c.*, COALESCE(ct.name, c.slug) AS name,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM categories c
             LEFT JOIN category_t ct ON ct.category_id = c.id AND ct.lang_code = :lang
             LEFT JOIN users creator ON creator.id = c.created_by
             LEFT JOIN users updater ON updater.id = c.updated_by
             ORDER BY c.sort_order, c.id'
        );
        $stmt->execute(['lang' => $lang]);
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT c.*,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM categories c
             LEFT JOIN users creator ON creator.id = c.created_by
             LEFT JOIN users updater ON updater.id = c.updated_by
             WHERE c.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'category';
    }

    public static function uniqueSlug(string $candidate): string
    {
        $pdo    = Database::getConnection();
        $stmt   = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE slug = ?');
        $slug   = $candidate;
        $suffix = 2;
        $stmt->execute([$slug]);
        while ((int) $stmt->fetchColumn() > 0) {
            $slug = $candidate . '-' . $suffix;
            $suffix++;
            $stmt->execute([$slug]);
        }
        return $slug;
    }

    public static function create(array $data, int $userId): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO categories (slug, sort_order, created_by, updated_by) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$data['slug'], (int) ($data['sort_order'] ?? 0), $userId, $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data, int $userId): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE categories SET slug = ?, sort_order = ?, updated_by = ? WHERE id = ?'
        );
        $stmt->execute([$data['slug'], (int) ($data['sort_order'] ?? 0), $userId, $id]);
    }

    public static function hasProducts(int $id): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
    }

    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, name, description, legal_notice FROM category_t WHERE category_id = ?');
        $stmt->execute([$id]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['lang_code']] = $row;
        }
        return $result;
    }

    public static function legalNoticesByCategory(): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->query('SELECT category_id, lang_code, legal_notice FROM category_t');
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['category_id']][$row['lang_code']] = $row['legal_notice'] ?? '';
        }
        return $result;
    }

    public static function setTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO category_t (category_id, lang_code, name, description, legal_notice)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
                                     legal_notice = VALUES(legal_notice)'
        );
        foreach ($translations as $lang => $t) {
            if (empty($t['name'])) continue;
            $stmt->execute([$id, $lang, $t['name'], $t['description'] ?? '', $t['legal_notice'] ?? null]);
        }
    }
}
