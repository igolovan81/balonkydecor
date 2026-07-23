<?php
namespace App\Models;

class CustomerModel
{
    public static function findByEmail(string $email): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(string $email, string $passwordHash, string $notificationLang = 'cs'): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO customers (email, password_hash, notification_lang) VALUES (?, ?, ?)');
        $stmt->execute([$email, $passwordHash, $notificationLang]);
        return (int) $pdo->lastInsertId();
    }

    public static function setResetToken(int $id, string $token, string $expiresAt): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE customers SET reset_token = ?, reset_token_expires = ? WHERE id = ?');
        $stmt->execute([$token, $expiresAt, $id]);
    }

    public static function findByValidResetToken(string $token): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE reset_token = ? AND reset_token_expires > NOW()');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function updatePasswordAndClearToken(int $id, string $passwordHash): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE customers SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?');
        $stmt->execute([$passwordHash, $id]);
    }

    public static function updateProfile(int $id, string $name, string $phone, string $notificationLang): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE customers SET name = ?, phone = ?, notification_lang = ? WHERE id = ?');
        $stmt->execute([$name, $phone, $notificationLang, $id]);
    }

    public static function updateEmail(int $id, string $email): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE customers SET email = ? WHERE id = ?');
        $stmt->execute([$email, $id]);
    }

    public static function delete(int $id): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE customers SET deleted_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function restore(int $id): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE customers SET deleted_at = NULL WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function dashboardStats(): array
    {
        $pdo = Database::getConnection();
        return [
            'total'          => (int) $pdo->query('SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL')->fetchColumn(),
            'new_this_week'  => (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL AND created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn(),
            'new_this_month' => (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL AND created_at >= NOW() - INTERVAL 30 DAY")->fetchColumn(),
        ];
    }

    public static function signupsByDay(int $days = 30): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT DATE(created_at) AS day, COUNT(*) AS c
             FROM customers
             WHERE deleted_at IS NULL AND created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             GROUP BY DATE(created_at)'
        );
        $stmt->bindValue(':days', $days - 1, \PDO::PARAM_INT);
        $stmt->execute();
        $byDay = [];
        foreach ($stmt->fetchAll() as $row) {
            $byDay[$row['day']] = (int) $row['c'];
        }

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date     = date('Y-m-d', strtotime("-{$i} days"));
            $result[] = ['date' => $date, 'count' => $byDay[$date] ?? 0];
        }
        return $result;
    }

    public static function recent(int $limit = 10): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, email, name, phone, created_at FROM customers
             WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
