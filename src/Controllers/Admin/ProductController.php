<?php
namespace App\Controllers\Admin;

use App\Models\CategoryModel;
use App\Models\ProductModel;
use App\Services\ImageUploader;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProductController extends AdminBaseController
{
    private const LANGS                = ['cs', 'en', 'ru', 'uk', 'sk'];
    private const TRANSLATABLE_FIELDS  = ['name', 'description', 'meta_title', 'meta_desc', 'legal_notice'];
    private const UPLOAD_DIR           = __DIR__ . '/../../../www/assets/uploads/products';

    public function index(Request $request, Response $response, array $args): Response
    {
        $products = ProductModel::all($request->getAttribute('admin_lang', 'cs'));
        return $this->renderAdmin($request, $response, 'admin/products/index.twig', compact('products'));
    }

    public function bulkUpdate(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $action = $body['action'] ?? '';
        if (!in_array($action, ['activate', 'deactivate'], true)) {
            return $response->withStatus(400);
        }

        $ids = $body['ids'] ?? [];
        if (!is_array($ids) || !$ids) {
            $this->flash('error', 'products.flash.bulk_none_selected');
            return $this->redirect($response, '/admin/products');
        }

        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        ProductModel::bulkSetActive($ids, $action === 'activate', $userId);

        $this->flash('success', $action === 'activate' ? 'products.flash.bulk_activated' : 'products.flash.bulk_deactivated');
        return $this->redirect($response, '/admin/products');
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        $categories = CategoryModel::allWithTranslation($request->getAttribute('admin_lang', 'cs'));
        return $this->renderAdmin($request, $response, 'admin/products/form.twig', [
            'product'      => null,
            'translations' => [],
            'categories'   => $categories,
            'langs'        => self::LANGS,
        ]);
    }

    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $sku    = trim($body['sku'] ?? '');
        if ($sku === '') {
            $nameForSku = trim($body['t']['en']['name'] ?? '');
            if ($nameForSku === '') {
                foreach (self::LANGS as $lang) {
                    $candidate = trim($body['t'][$lang]['name'] ?? '');
                    if ($candidate !== '') {
                        $nameForSku = $candidate;
                        break;
                    }
                }
            }
            $sku = ProductModel::slugify($nameForSku);
        }
        $sku = ProductModel::uniqueSku($sku);
        $id  = ProductModel::create([
            'sku'         => $sku,
            'price'       => $body['price'] ?? '0.00',
            'category_id' => $body['category_id'] ?? 1,
            'is_active'   => isset($body['is_active']) ? 1 : 0,
            'stock_type'  => $body['stock_type'] ?? 'unlimited',
            'stock_qty'   => $body['stock_qty'] ?? 0,
        ], $userId);
        $translations = \App\Services\Translator::autoFill(
            $body['t'] ?? [],
            $request->getAttribute('admin_lang', 'cs'),
            self::LANGS,
            self::TRANSLATABLE_FIELDS
        );
        ProductModel::setTranslations($id, $translations);
        ProductModel::setSubtypes($id, $this->buildSubtypes(
            $body['subtypes'] ?? [], $request->getAttribute('admin_lang', 'cs')
        ));
        ProductModel::setSpecs($id, $this->buildSpecs(
            $body['specs'] ?? [], $request->getAttribute('admin_lang', 'cs')
        ));
        $this->handleImageUpload($request, $id, true);
        \App\Services\Notifier::notify(
            'product', $id, $sku, 'created', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'products.flash.created');
        return $this->redirect($response, '/admin/products');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $product = ProductModel::findById((int) $args['id']);
        if (!$product) return $response->withStatus(404);
        $translations = ProductModel::getTranslations((int) $args['id']);
        $categories   = CategoryModel::allWithTranslation($request->getAttribute('admin_lang', 'cs'));
        return $this->renderAdmin($request, $response, 'admin/products/form.twig', [
            'product'      => $product,
            'translations' => $translations,
            'categories'   => $categories,
            'langs'        => self::LANGS,
        ]);
    }

    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id     = (int) $args['id'];
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $sku    = trim($body['sku'] ?? '');
        ProductModel::update($id, [
            'sku'         => $sku,
            'price'       => $body['price'] ?? '0.00',
            'category_id' => $body['category_id'] ?? 1,
            'is_active'   => isset($body['is_active']) ? 1 : 0,
            'stock_type'  => $body['stock_type'] ?? 'unlimited',
            'stock_qty'   => $body['stock_qty'] ?? 0,
        ], $userId);
        ProductModel::setTranslations($id, $body['t'] ?? []);
        ProductModel::setSubtypes($id, $this->buildSubtypes(
            $body['subtypes'] ?? [], $request->getAttribute('admin_lang', 'cs')
        ));
        ProductModel::setSpecs($id, $this->buildSpecs(
            $body['specs'] ?? [], $request->getAttribute('admin_lang', 'cs')
        ));
        $this->handleImageUpload($request, $id, false);
        \App\Services\Notifier::notify(
            'product', $id, $sku, 'updated', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'products.flash.updated');
        return $this->redirect($response, '/admin/products');
    }

    public function clone(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $newId  = ProductModel::clone((int) $args['id'], $userId);
        if ($newId === null) {
            return $response->withStatus(404);
        }
        $clone = ProductModel::findById($newId);
        \App\Services\Notifier::notify(
            'product', $newId, $clone['sku'], 'created', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'products.flash.cloned');
        return $this->redirect($response, '/admin/products/' . $newId . '/edit');
    }

    public function cloneWithImage(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $newId  = ProductModel::clone((int) $args['id'], $userId, (int) $args['image_id']);
        if ($newId === null) {
            return $response->withStatus(404);
        }
        $clone = ProductModel::findById($newId);
        \App\Services\Notifier::notify(
            'product', $newId, $clone['sku'], 'created', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
        $this->flash('success', 'products.flash.split');
        return $this->redirect($response, '/admin/products/' . $newId . '/edit');
    }

    public function deleteImage(Request $request, Response $response, array $args): Response
    {
        $filename = ProductModel::deleteImage((int) $args['image_id']);
        if ($filename) {
            @unlink(self::UPLOAD_DIR . '/' . $filename);
            @unlink(self::UPLOAD_DIR . '/thumb_' . $filename);
        }
        $this->flash('success', 'products.flash.image_deleted');
        return $this->redirect($response, '/admin/products/' . $args['id'] . '/edit');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id      = (int) $args['id'];
        $product = ProductModel::findById($id);
        if ($product) {
            foreach ($product['images'] as $img) {
                @unlink(self::UPLOAD_DIR . '/' . $img['filename']);
                @unlink(self::UPLOAD_DIR . '/thumb_' . $img['filename']);
            }
            ProductModel::delete($id);
            $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
            \App\Services\Notifier::notify(
                'product', $id, $product['sku'], 'deleted', $userId, $_SESSION['admin_user']['email'] ?? ''
            );
        }
        $this->flash('success', 'products.flash.deleted');
        return $this->redirect($response, '/admin/products');
    }

    private function handleImageUpload(Request $request, int $productId, bool $isPrimary): void
    {
        $files = $request->getUploadedFiles();
        $file  = $files['image'] ?? null;
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) return;

        $tmp      = ['tmp_name' => $file->getStream()->getMetadata('uri'), 'error' => $file->getError()];
        $filename = ImageUploader::upload($tmp, self::UPLOAD_DIR);
        ProductModel::addImage($productId, $filename, $isPrimary);
    }

    private function buildSubtypes(array $rows, string $adminLang): array
    {
        $subtypes = [];
        foreach ($rows as $row) {
            $name = trim($row['name'] ?? '');
            if ($name === '') continue;

            $t = \App\Services\Translator::autoFill(
                [$adminLang => ['name' => $name]],
                $adminLang, self::LANGS, ['name']
            );
            $subtypes[] = [
                'price' => $row['price'] ?? '0.00',
                't'     => array_map(fn ($fields) => $fields['name'] ?? '', $t),
            ];
        }
        return $subtypes;
    }

    private function buildSpecs(array $rows, string $adminLang): array
    {
        $specs = [];
        foreach ($rows as $row) {
            $name = trim($row['name'] ?? '');
            if ($name === '') continue;
            $value = trim($row['value'] ?? '');

            $t = \App\Services\Translator::autoFill(
                [$adminLang => ['name' => $name, 'value' => $value]],
                $adminLang, self::LANGS, ['name', 'value']
            );

            // Translator::autoFill() now isolates field failures from each other, but
            // a field can still end up unset if translation fails outright — fall
            // back to the admin's own text so a row is never missing/blank.
            foreach (self::LANGS as $lang) {
                $t[$lang]['name']  = $t[$lang]['name']  ?? $name;
                $t[$lang]['value'] = $t[$lang]['value'] ?? $value;
            }

            $specs[] = ['t' => $t];
        }
        return $specs;
    }
}
