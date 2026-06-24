<?php
namespace App\Models;

class AdminUserModel
{
    public static function findByEmail(string $email): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id, email, password_hash, role FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, email, role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function count(): int
    {
        $pdo = Database::getConnection();
        return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public static function create(string $email, string $passwordHash, string $role = 'admin'): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)");
        $stmt->execute([$email, $passwordHash, $role]);
        return (int) $pdo->lastInsertId();
    }

    public static function all(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query('SELECT id, email, role, created_at FROM users ORDER BY id')->fetchAll();
    }

    public static function updatePassword(int $id, string $passwordHash): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$passwordHash, $id]);
    }

    public static function delete(int $id): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
    }
}
