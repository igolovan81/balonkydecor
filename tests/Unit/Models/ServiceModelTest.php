<?php
namespace Tests\Unit\Models;

use App\Models\ServiceModel;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

class ServiceModelTest extends TestCase
{
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('service-audit-test@example.com', 'x', 'editor')");
        self::$userId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='service-audit-test@example.com'"
        )->fetch()['id'];
    }

    public function test_create_find_update_delete(): void
    {
        $id = ServiceModel::create(['price_from' => 500, 'sort_order' => 99], self::$userId);
        $this->assertGreaterThan(0, $id);

        $service = ServiceModel::findById($id);
        $this->assertSame(500, (int) $service['price_from']);
        $this->assertSame(99, (int) $service['sort_order']);

        ServiceModel::update($id, ['price_from' => null, 'sort_order' => 98], self::$userId);
        $service = ServiceModel::findById($id);
        $this->assertNull($service['price_from']);
        $this->assertSame(98, (int) $service['sort_order']);

        ServiceModel::delete($id);
        $this->assertNull(ServiceModel::findById($id));
    }

    public function test_set_and_get_translations_upsert(): void
    {
        $id = ServiceModel::create(['price_from' => null, 'sort_order' => 99], self::$userId);
        ServiceModel::setTranslations($id, [
            'en' => ['name' => 'Test Service', 'description' => 'Desc', 'features' => "One\nTwo"],
        ]);
        ServiceModel::setTranslations($id, [
            'en' => ['name' => 'Test Service v2', 'description' => 'Desc v2', 'features' => "One\nTwo\nThree"],
        ]);

        $translations = ServiceModel::getTranslations($id);
        $this->assertSame('Test Service v2', $translations['en']['name']);
        $this->assertSame("One\nTwo\nThree", $translations['en']['features']);

        ServiceModel::delete($id);
    }

    public function test_all_with_translation_falls_back_to_cs(): void
    {
        $id = ServiceModel::create(['price_from' => 750, 'sort_order' => 99], self::$userId);
        ServiceModel::setTranslations($id, [
            'cs' => ['name' => 'Jen česky', 'description' => 'Popis', 'features' => 'A'],
        ]);

        $row = $this->findService(ServiceModel::allWithTranslation('en'), $id);
        $this->assertSame('Jen česky', $row['name']);

        ServiceModel::delete($id);
    }

    public function test_all_with_translation_orders_by_sort_order(): void
    {
        $first  = ServiceModel::create(['price_from' => null, 'sort_order' => 97], self::$userId);
        $second = ServiceModel::create(['price_from' => null, 'sort_order' => 96], self::$userId);
        ServiceModel::setTranslations($first, ['cs' => ['name' => 'First', 'description' => '', 'features' => '']]);
        ServiceModel::setTranslations($second, ['cs' => ['name' => 'Second', 'description' => '', 'features' => '']]);

        $ids = array_column(ServiceModel::allWithTranslation('cs'), 'id');
        $this->assertLessThan(array_search($first, $ids), array_search($second, $ids));

        ServiceModel::delete($first);
        ServiceModel::delete($second);
    }

    public function test_delete_cascades_translations(): void
    {
        $id = ServiceModel::create(['price_from' => null, 'sort_order' => 99], self::$userId);
        ServiceModel::setTranslations($id, ['cs' => ['name' => 'X', 'description' => '', 'features' => '']]);
        ServiceModel::delete($id);

        $stmt = Database::getConnection()->prepare('SELECT COUNT(*) FROM service_t WHERE service_id = ?');
        $stmt->execute([$id]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function test_create_records_creator_and_updater(): void
    {
        $id = ServiceModel::create(['price_from' => 500, 'sort_order' => 99], self::$userId);
        $service = ServiceModel::findById($id);
        $this->assertSame(self::$userId, (int) $service['created_by']);
        $this->assertSame(self::$userId, (int) $service['updated_by']);
        $this->assertSame('service-audit-test@example.com', $service['created_by_email']);
        $this->assertSame('service-audit-test@example.com', $service['updated_by_email']);
        $this->assertNotEmpty($service['created_at']);
        $this->assertNotEmpty($service['updated_at']);

        ServiceModel::delete($id);
    }

    public function test_update_changes_updated_by_but_not_created_by(): void
    {
        $id = ServiceModel::create(['price_from' => 500, 'sort_order' => 99], self::$userId);

        $pdo = Database::getConnection();
        $pdo->exec("INSERT IGNORE INTO users (email, password_hash, role)
                    VALUES ('service-audit-editor2@example.com', 'x', 'editor')");
        $secondUserId = (int) $pdo->query(
            "SELECT id FROM users WHERE email='service-audit-editor2@example.com'"
        )->fetch()['id'];

        ServiceModel::update($id, ['price_from' => 600, 'sort_order' => 98], $secondUserId);

        $service = ServiceModel::findById($id);
        $this->assertSame(self::$userId, (int) $service['created_by']);
        $this->assertSame($secondUserId, (int) $service['updated_by']);

        ServiceModel::delete($id);
    }

    public function test_all_includes_audit_columns(): void
    {
        $id   = ServiceModel::create(['price_from' => 500, 'sort_order' => 99], self::$userId);
        $rows = ServiceModel::all();
        $this->assertNotEmpty($rows);
        foreach (['created_by_email', 'created_at', 'updated_by_email', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $rows[0]);
        }
        ServiceModel::delete($id);
    }

    private function findService(array $services, int $id): array
    {
        foreach ($services as $service) {
            if ((int) $service['id'] === $id) {
                return $service;
            }
        }
        $this->fail("Service {$id} not returned");
    }
}
