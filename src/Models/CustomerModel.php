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

    public static function create(string $email, string $passwordHash): int
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO customers (email, password_hash) VALUES (?, ?)');
        $stmt->execute([$email, $passwordHash]);
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

    public static function updateProfile(int $id, string $name, string $phone): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE customers SET name = ?, phone = ? WHERE id = ?');
        $stmt->execute([$name, $phone, $id]);
    }

    public static function updateEmail(int $id, string $email): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE customers SET email = ? WHERE id = ?');
        $stmt->execute([$email, $id]);
    }
}
