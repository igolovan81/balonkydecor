<?php
// Token-protected cleanup of gallery/product DB rows whose files are missing on disk.
// Usage: GET /cleanup-media.php?token=YOUR_TOKEN            — dry run, reports orphans
//        GET /cleanup-media.php?token=YOUR_TOKEN&confirm=1  — delete rows / clear covers
// Returns JSON with a "gallery" and a "products" section.

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
use App\Models\ProductModel;

$galleryDir  = __DIR__ . '/assets/uploads/gallery';
$productsDir = __DIR__ . '/assets/uploads/products';

try {
    if (($_GET['confirm'] ?? '') === '1') {
        $gallery  = GalleryModel::cleanupOrphans($galleryDir);
        $products = ProductModel::cleanupOrphans($productsDir);
        $result   = [
            'mode'     => 'cleanup',
            'gallery'  => $gallery,
            'products' => $products,
            'count'    => count($gallery['deleted_images']) + count($gallery['cleared_covers'])
                        + count($products['deleted_images']),
        ];
    } else {
        $gallery  = GalleryModel::orphanedMedia($galleryDir);
        $products = ProductModel::orphanedImages($productsDir);
        $result   = [
            'mode'     => 'dry_run',
            'gallery'  => ['orphan_images' => $gallery['images'], 'stale_covers' => $gallery['covers']],
            'products' => ['orphan_images' => $products],
            'count'    => count($gallery['images']) + count($gallery['covers']) + count($products),
            'hint'     => 'Re-run with &confirm=1 to delete these rows.',
        ];
    }
    header('Content-Type: application/json');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
