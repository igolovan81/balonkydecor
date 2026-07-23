<?php
namespace App\Models;

class OrderModel
{
    public static function create(array $customer, array $cartItems, string $total, ?int $customerId = null): string
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('
            INSERT INTO orders
                (customer_id, order_number, status, customer_name, customer_email,
                 customer_phone, pickup_date, total_amount, notes)
            VALUES (?, ?, \'pending\', ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $customerId,
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
            INSERT INTO order_items
                (order_id, product_id, subtype_id, quantity, unit_price, product_name_snapshot, subtype_name_snapshot)
            VALUES (?, (SELECT id FROM products WHERE sku = ? LIMIT 1), ?, ?, ?, ?, ?)
        ');
        foreach ($cartItems as $key => $item) {
            $sku = $item['sku'] ?? $key;
            $itemStmt->execute([
                $id, $sku, $item['subtype_id'] ?? null,
                $item['qty'], $item['price'], $item['name'], $item['subtype_name'] ?? null,
            ]);
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

    public static function adminList(int $page = 1, int $perPage = 20, string $status = ''): array
    {
        $pdo    = Database::getConnection();
        $where  = $status ? 'WHERE status = ' . $pdo->quote($status) : '';
        $total  = (int) $pdo->query("SELECT COUNT(*) FROM orders {$where}")->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt   = $pdo->prepare(
            "SELECT order_number, customer_name, customer_email, total_amount, status, created_at
             FROM orders {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();
        return ['orders' => $stmt->fetchAll(), 'total' => $total, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public static function forCustomer(int $customerId): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT order_number, status, total_amount, created_at
             FROM orders WHERE customer_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$customerId]);
        return $stmt->fetchAll();
    }

    public static function dashboardStats(): array
    {
        $pdo = Database::getConnection();
        return [
            'orders_today'   => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
            'orders_pending' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn(),
            'orders_total'   => (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
            'gopay_count'    => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE gopay_payment_id IS NOT NULL")->fetchColumn(),
        ];
    }

    public static function statusBreakdown(): array
    {
        $pdo    = Database::getConnection();
        $counts = array_fill_keys(['pending', 'paid', 'ready', 'completed', 'cancelled'], 0);
        $stmt   = $pdo->query('SELECT status, COUNT(*) AS c FROM orders GROUP BY status');
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    public static function revenueByDay(int $days = 30): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT DATE(created_at) AS day, SUM(total_amount) AS total
             FROM orders
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             GROUP BY DATE(created_at)'
        );
        $stmt->bindValue(':days', $days - 1, \PDO::PARAM_INT);
        $stmt->execute();
        $byDay = [];
        foreach ($stmt->fetchAll() as $row) {
            $byDay[$row['day']] = (float) $row['total'];
        }

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date     = date('Y-m-d', strtotime("-{$i} days"));
            $result[] = ['date' => $date, 'total' => $byDay[$date] ?? 0.0];
        }
        return $result;
    }
}
