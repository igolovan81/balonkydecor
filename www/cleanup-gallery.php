<?php
// Token-protected cleanup of gallery DB rows whose files are missing on disk.
// Usage: GET /cleanup-gallery.php?token=YOUR_TOKEN            — dry run, reports orphans
//        GET /cleanup-gallery.php?token=YOUR_TOKEN&confirm=1  — delete rows / clear covers
// Returns JSON.

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

use App\Models\GalleryModel;

$uploadsDir = __DIR__ . '/assets/uploads/gallery';

try {
    if (($_GET['confirm'] ?? '') === '1') {
        $report = GalleryModel::cleanupOrphans($uploadsDir);
        $result = [
            'mode'           => 'cleanup',
            'deleted_images' => $report['deleted_images'],
            'cleared_covers' => $report['cleared_covers'],
            'count'          => count($report['deleted_images']) + count($report['cleared_covers']),
        ];
    } else {
        $orphans = GalleryModel::orphanedMedia($uploadsDir);
        $result  = [
            'mode'          => 'dry_run',
            'orphan_images' => $orphans['images'],
            'stale_covers'  => $orphans['covers'],
            'count'         => count($orphans['images']) + count($orphans['covers']),
            'hint'          => 'Re-run with &confirm=1 to delete these rows.',
        ];
    }
    header('Content-Type: application/json');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
