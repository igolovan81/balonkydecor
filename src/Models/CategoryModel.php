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
}
