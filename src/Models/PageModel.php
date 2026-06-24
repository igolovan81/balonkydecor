<?php
namespace App\Models;

class PageModel
{
    public static function find(string $slug, string $lang): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT t.title, t.body, t.meta_title, t.meta_desc
            FROM pages p
            JOIN page_t t ON t.page_id = p.id AND t.lang_code = :lang
            WHERE p.slug = :slug
        ');
        $stmt->execute(['slug' => $slug, 'lang' => $lang]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
