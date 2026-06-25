<?php
// Token-protected migration runner.
// Usage: GET /migrate?token=YOUR_TOKEN
// Returns JSON: {"applied":["V001__..."],"count":1}

$settings   = require __DIR__ . '/../config/settings.php';
$prodConfig = __DIR__ . '/../config/settings.prod.php';
if (file_exists($prodConfig)) {
    $settings = array_replace_recursive($settings, require $prodConfig);
}

$token = $settings['migrate_token'] ?? '';
if ($token === '' || ($_GET['token'] ?? '') !== $token) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use App\Models\Database;
use App\Services\Migrator;

try {
    $migrator = new Migrator(
        Database::getConnection(),
        __DIR__ . '/../database/migrations'
    );

    $action = $_GET['action'] ?? 'migrate';

    if ($action === 'status') {
        $result = ['status' => $migrator->status()];
    } else {
        $applied = $migrator->run();
        $result  = ['applied' => $applied, 'count' => count($applied)];
    }

    header('Content-Type: application/json');
    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
