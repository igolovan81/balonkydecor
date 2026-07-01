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

    public static function allSlugs(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query('SELECT slug FROM pages ORDER BY slug')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public static function allTranslations(string $slug): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT pt.lang_code, pt.title, pt.body, pt.meta_title, pt.meta_desc
             FROM pages p
             LEFT JOIN page_t pt ON pt.page_id = p.id
             WHERE p.slug = ?'
        );
        $stmt->execute([$slug]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['lang_code']) {
                $result[$row['lang_code']] = $row;
            }
        }
        return $result;
    }

    public static function upsert(string $slug, string $lang, string $title, string $body, ?string $metaTitle = null, ?string $metaDesc = null): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id FROM pages WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $page = $stmt->fetch();
        if (!$page) {
            $pdo->prepare('INSERT INTO pages (slug) VALUES (?)')->execute([$slug]);
            $pageId = (int) $pdo->lastInsertId();
        } else {
            $pageId = (int) $page['id'];
        }
        $pdo->prepare(
            'INSERT INTO page_t (page_id, lang_code, title, body, meta_title, meta_desc) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body),
                                     meta_title = VALUES(meta_title), meta_desc = VALUES(meta_desc)'
        )->execute([$pageId, $lang, $title, $body, $metaTitle, $metaDesc]);
    }
}
