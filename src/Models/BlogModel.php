<?php
namespace App\Models;

class BlogModel
{
    public static function published(string $lang, int $page = 1, int $perPage = 10): array
    {
        $pdo    = Database::getConnection();
        $offset = ($page - 1) * $perPage;

        $count = $pdo->prepare('SELECT COUNT(*) FROM blog_posts WHERE status = ?');
        $count->execute(['published']);
        $total = (int) $count->fetchColumn();

        $stmt = $pdo->prepare('
            SELECT p.id, p.slug, p.published_at,
                   COALESCE(t.title, p.slug) AS title,
                   t.meta_desc
            FROM blog_posts p
            LEFT JOIN blog_post_t t ON t.post_id = p.id AND t.lang_code = :lang
            WHERE p.status = \'published\'
            ORDER BY p.published_at DESC
            LIMIT :limit OFFSET :offset
        ');
        $stmt->bindValue('lang',   $lang,    \PDO::PARAM_STR);
        $stmt->bindValue('limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'posts' => $stmt->fetchAll(),
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ];
    }

    public static function findBySlug(string $slug, string $lang): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT p.id, p.slug, p.published_at,
                   COALESCE(t.title, p.slug) AS title,
                   t.body, t.meta_title, t.meta_desc
            FROM blog_posts p
            LEFT JOIN blog_post_t t ON t.post_id = p.id AND t.lang_code = :lang
            WHERE p.slug = :slug AND p.status = \'published\'
        ');
        $stmt->execute(['slug' => $slug, 'lang' => $lang]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
