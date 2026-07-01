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

    public static function adminList(int $page = 1, int $perPage = 20): array
    {
        $pdo    = Database::getConnection();
        $total  = (int) $pdo->query('SELECT COUNT(*) FROM blog_posts')->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt   = $pdo->prepare(
            'SELECT id, slug, status, published_at, created_at FROM blog_posts ORDER BY created_at DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();
        return ['posts' => $stmt->fetchAll(), 'total' => $total, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM blog_posts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data): int
    {
        $pdo    = Database::getConnection();
        $status = ($data['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $pubAt  = ($status === 'published') ? ($data['published_at'] ?: date('Y-m-d H:i:s')) : null;
        $stmt   = $pdo->prepare(
            'INSERT INTO blog_posts (slug, status, published_at) VALUES (:slug, :status, :published_at)'
        );
        $stmt->execute(['slug' => $data['slug'], 'status' => $status, 'published_at' => $pubAt]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $pdo    = Database::getConnection();
        $status = ($data['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $pubAt  = ($status === 'published') ? ($data['published_at'] ?: date('Y-m-d H:i:s')) : null;
        $stmt   = $pdo->prepare(
            'UPDATE blog_posts SET slug = :slug, status = :status, published_at = :published_at WHERE id = :id'
        );
        $stmt->execute(['slug' => $data['slug'], 'status' => $status, 'published_at' => $pubAt, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM blog_posts WHERE id = ?')->execute([$id]);
    }

    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, title, body, meta_title, meta_desc FROM blog_post_t WHERE post_id = ?');
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
            'INSERT INTO blog_post_t (post_id, lang_code, title, body, meta_title, meta_desc)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body),
                                     meta_title = VALUES(meta_title), meta_desc = VALUES(meta_desc)'
        );
        foreach ($translations as $lang => $t) {
            if (empty($t['title'])) continue;
            $stmt->execute([$id, $lang, $t['title'], $t['body'] ?? '', $t['meta_title'] ?? null, $t['meta_desc'] ?? null]);
        }
    }
}
