<?php
namespace Tests\Unit\Models;

use App\Models\Database;
use App\Models\NotificationModel;
use PHPUnit\Framework\TestCase;

class NotificationModelTest extends TestCase
{
    private static int $actorId;
    private static int $recipientId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('notif-actor@example.com', 'x', 'editor')");
        self::$actorId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='notif-actor@example.com'"
        )->fetch()['id'];

        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('notif-recipient@example.com', 'x', 'admin')");
        self::$recipientId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='notif-recipient@example.com'"
        )->fetch()['id'];
    }

    public function test_create_notifies_every_user_except_the_actor(): void
    {
        $label = 'Test Category ' . uniqid();
        NotificationModel::create('category', 999, $label, 'created', self::$actorId, 'notif-actor@example.com');

        $rows  = NotificationModel::recentAndMarkRead(self::$recipientId, 50);
        $match = array_values(array_filter($rows, fn($r) => $r['entity_label'] === $label));

        $this->assertNotEmpty($match);
        $this->assertSame('category', $match[0]['entity_type']);
        $this->assertSame(999, (int) $match[0]['entity_id']);
        $this->assertSame('created', $match[0]['action']);
        $this->assertSame('notif-actor@example.com', $match[0]['actor_label']);
    }

    public function test_create_does_not_notify_the_actor(): void
    {
        $label = 'Self Test ' . uniqid();
        NotificationModel::create('product', 999, $label, 'updated', self::$actorId, 'notif-actor@example.com');

        $rows  = NotificationModel::recentAndMarkRead(self::$actorId, 50);
        $match = array_filter($rows, fn($r) => $r['entity_label'] === $label);

        $this->assertEmpty($match);
    }

    public function test_recent_and_mark_read_zeroes_the_unread_count(): void
    {
        $label = 'Unread Test ' . uniqid();
        NotificationModel::create('service', 999, $label, 'deleted', self::$actorId, 'notif-actor@example.com');

        $this->assertGreaterThan(0, NotificationModel::unreadCount(self::$recipientId));

        NotificationModel::recentAndMarkRead(self::$recipientId, 50);

        $this->assertSame(0, NotificationModel::unreadCount(self::$recipientId));
    }

    public function test_recent_and_mark_read_orders_newest_first(): void
    {
        $first  = 'Order Test A ' . uniqid();
        $second = 'Order Test B ' . uniqid();
        NotificationModel::create('category', 1001, $first, 'created', self::$actorId, 'notif-actor@example.com');
        NotificationModel::create('category', 1002, $second, 'created', self::$actorId, 'notif-actor@example.com');

        $labels      = array_column(NotificationModel::recentAndMarkRead(self::$recipientId, 50), 'entity_label');
        $secondIndex = array_search($second, $labels, true);
        $firstIndex  = array_search($first, $labels, true);

        $this->assertLessThan($firstIndex, $secondIndex);
    }

    public function test_for_user_paginates(): void
    {
        for ($i = 0; $i < 3; $i++) {
            NotificationModel::create(
                'product', 2000 + $i, 'Page Test ' . uniqid(), 'updated', self::$actorId, 'notif-actor@example.com'
            );
        }

        $page1 = NotificationModel::forUser(self::$recipientId, 1, 2);

        $this->assertCount(2, $page1['items']);
        $this->assertGreaterThanOrEqual(3, $page1['total']);
        $this->assertGreaterThanOrEqual(2, $page1['pages']);
    }
}
