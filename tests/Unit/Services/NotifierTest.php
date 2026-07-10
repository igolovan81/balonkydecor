<?php
namespace Tests\Unit\Services;

use App\Models\Database;
use App\Models\NotificationModel;
use App\Services\Notifier;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase
{
    private static int $actorId;
    private static int $recipientId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('notifier-actor@example.com', 'x', 'editor')");
        self::$actorId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='notifier-actor@example.com'"
        )->fetch()['id'];

        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('notifier-recipient@example.com', 'x', 'admin')");
        self::$recipientId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='notifier-recipient@example.com'"
        )->fetch()['id'];
    }

    public function test_notify_creates_a_notification_with_the_given_fields(): void
    {
        $label = 'Notifier Test ' . uniqid();
        Notifier::notify('product', 4242, $label, 'updated', self::$actorId, 'notifier-actor@example.com');

        $rows  = NotificationModel::recentAndMarkRead(self::$recipientId, 50);
        $match = array_values(array_filter($rows, fn($r) => $r['entity_label'] === $label));

        $this->assertNotEmpty($match);
        $this->assertSame('product', $match[0]['entity_type']);
        $this->assertSame(4242, (int) $match[0]['entity_id']);
        $this->assertSame('updated', $match[0]['action']);
        $this->assertSame('notifier-actor@example.com', $match[0]['actor_label']);
    }
}
