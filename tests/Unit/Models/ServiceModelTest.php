<?php
namespace Tests\Unit\Models;

use App\Models\ServiceModel;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

class ServiceModelTest extends TestCase
{
    public function test_create_find_update_delete(): void
    {
        $id = ServiceModel::create(['price_from' => 500, 'sort_order' => 99]);
        $this->assertGreaterThan(0, $id);

        $service = ServiceModel::findById($id);
        $this->assertSame(500, (int) $service['price_from']);
        $this->assertSame(99, (int) $service['sort_order']);

        ServiceModel::update($id, ['price_from' => null, 'sort_order' => 98]);
        $service = ServiceModel::findById($id);
        $this->assertNull($service['price_from']);
        $this->assertSame(98, (int) $service['sort_order']);

        ServiceModel::delete($id);
        $this->assertNull(ServiceModel::findById($id));
    }

    public function test_set_and_get_translations_upsert(): void
    {
        $id = ServiceModel::create(['price_from' => null, 'sort_order' => 99]);
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
        $id = ServiceModel::create(['price_from' => 750, 'sort_order' => 99]);
        ServiceModel::setTranslations($id, [
            'cs' => ['name' => 'Jen česky', 'description' => 'Popis', 'features' => 'A'],
        ]);

        $row = $this->findService(ServiceModel::allWithTranslation('en'), $id);
        $this->assertSame('Jen česky', $row['name']);

        ServiceModel::delete($id);
    }

    public function test_all_with_translation_orders_by_sort_order(): void
    {
        $first  = ServiceModel::create(['price_from' => null, 'sort_order' => 97]);
        $second = ServiceModel::create(['price_from' => null, 'sort_order' => 96]);
        ServiceModel::setTranslations($first, ['cs' => ['name' => 'First', 'description' => '', 'features' => '']]);
        ServiceModel::setTranslations($second, ['cs' => ['name' => 'Second', 'description' => '', 'features' => '']]);

        $ids = array_column(ServiceModel::allWithTranslation('cs'), 'id');
        $this->assertLessThan(array_search($first, $ids), array_search($second, $ids));

        ServiceModel::delete($first);
        ServiceModel::delete($second);
    }

    public function test_delete_cascades_translations(): void
    {
        $id = ServiceModel::create(['price_from' => null, 'sort_order' => 99]);
        ServiceModel::setTranslations($id, ['cs' => ['name' => 'X', 'description' => '', 'features' => '']]);
        ServiceModel::delete($id);

        $stmt = Database::getConnection()->prepare('SELECT COUNT(*) FROM service_t WHERE service_id = ?');
        $stmt->execute([$id]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
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
