<?php
namespace App\Models;

class NotificationModel
{
    public static function create(
        string $entityType,
        int $entityId,
        string $entityLabel,
        string $action,
        int $actorId,
        string $actorLabel
    ): void {
        $pdo = Database::getConnection();

        $recipients = $pdo->prepare('SELECT id FROM users WHERE id != ?');
        $recipients->execute([$actorId]);
        $recipientIds = $recipients->fetchAll(\PDO::FETCH_COLUMN);

        if (!$recipientIds) return;

        $stmt = $pdo->prepare(
            'INSERT INTO notifications (recipient_id, actor_id, actor_label, entity_type, entity_id, entity_label, action)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($recipientIds as $recipientId) {
            $stmt->execute([$recipientId, $actorId, $actorLabel, $entityType, $entityId, $entityLabel, $action]);
        }
    }

    public static function unreadCount(int $userId): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public static function recentAndMarkRead(int $userId, int $limit = 20): array
    {
        $pdo = Database::getConnection();

        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE recipient_id = ? AND is_read = 0')
            ->execute([$userId]);

        $stmt = $pdo->prepare(
            'SELECT * FROM notifications WHERE recipient_id = :uid ORDER BY created_at DESC, id DESC LIMIT :limit'
        );
        $stmt->bindValue(':uid', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function forUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        $pdo = Database::getConnection();

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_id = ?');
        $countStmt->execute([$userId]);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt   = $pdo->prepare(
            'SELECT * FROM notifications WHERE recipient_id = :uid
             ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':uid', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }
}
