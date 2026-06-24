<?php
namespace App\Models;

class OrderModel
{
    public static function create(array $customer, array $cartItems, string $total): string
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('
            INSERT INTO orders
                (order_number, status, customer_name, customer_email,
                 customer_phone, pickup_date, total_amount, notes)
            VALUES (?, \'pending\', ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            'PENDING',
            $customer['customer_name'],
            $customer['customer_email'],
            $customer['customer_phone'],
            $customer['pickup_date'] ?: null,
            $total,
            $customer['notes'] ?? '',
        ]);
        $id          = (int) $pdo->lastInsertId();
        $orderNumber = 'BD-' . date('Ymd') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);

        $pdo->prepare('UPDATE orders SET order_number = ? WHERE id = ?')
            ->execute([$orderNumber, $id]);

        $itemStmt = $pdo->prepare('
            INSERT INTO order_items (order_id, product_id, quantity, unit_price, product_name_snapshot)
            VALUES (?, (SELECT id FROM products WHERE sku = ? LIMIT 1), ?, ?, ?)
        ');
        foreach ($cartItems as $sku => $item) {
            $itemStmt->execute([$id, $sku, $item['qty'], $item['price'], $item['name']]);
        }

        $pdo->commit();
        return $orderNumber;
    }

    public static function findByNumber(string $number): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ?');
        $stmt->execute([$number]);
        $order = $stmt->fetch();
        if (!$order) {
            return null;
        }
        $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $items->execute([$order['id']]);
        $order['items'] = $items->fetchAll();
        return $order;
    }

    public static function updateStatus(string $number, string $status, ?string $gopayId = null): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            UPDATE orders SET status = ?, gopay_payment_id = COALESCE(?, gopay_payment_id)
            WHERE order_number = ?
        ');
        $stmt->execute([$status, $gopayId, $number]);
    }

    public static function findByGopayId(string $gopayId): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE gopay_payment_id = ?');
        $stmt->execute([$gopayId]);
        $order = $stmt->fetch();
        return $order ?: null;
    }
}
