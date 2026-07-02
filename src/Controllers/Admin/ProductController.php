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
    private const TRANSLATABLE_FIELDS  = ['name', 'description', 'meta_title', 'meta_desc'];
    private const UPLOAD_DIR           = __DIR__ . '/../../../www/assets/uploads/products';

    public function index(Request $request, Response $response, array $args): Response
    {
        $products = ProductModel::all();
        return $this->renderAdmin($request, $response, 'admin/products/index.twig', compact('products'));
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        $categories = CategoryModel::allWithTranslation('cs');
        return $this->renderAdmin($request, $response, 'admin/products/form.twig', [
            'product'      => null,
            'translations' => [],
            'categories'   => $categories,
            'langs'        => self::LANGS,
        ]);
    }

    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $id   = ProductModel::create([
            'sku'         => trim($body['sku'] ?? ''),
            'price'       => $body['price'] ?? '0.00',
            'category_id' => $body['category_id'] ?? 1,
            'is_active'   => isset($body['is_active']) ? 1 : 0,
            'stock_type'  => $body['stock_type'] ?? 'unlimited',
            'stock_qty'   => $body['stock_qty'] ?? 0,
        ]);
        $translations = \App\Services\Translator::autoFill(
            $body['t'] ?? [],
            $request->getAttribute('admin_lang', 'cs'),
            self::LANGS,
            self::TRANSLATABLE_FIELDS
        );
        ProductModel::setTranslations($id, $translations);
        $this->handleImageUpload($request, $id, true);
        $this->flash('success', 'Produkt vytvořen.');
        return $this->redirect($response, '/admin/products');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $product = ProductModel::findById((int) $args['id']);
        if (!$product) return $response->withStatus(404);
        $translations = ProductModel::getTranslations((int) $args['id']);
        $categories   = CategoryModel::allWithTranslation('cs');
        return $this->renderAdmin($request, $response, 'admin/products/form.twig', [
            'product'      => $product,
            'translations' => $translations,
            'categories'   => $categories,
            'langs'        => self::LANGS,
        ]);
    }

    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $body = (array) $request->getParsedBody();
        ProductModel::update($id, [
            'sku'         => trim($body['sku'] ?? ''),
            'price'       => $body['price'] ?? '0.00',
            'category_id' => $body['category_id'] ?? 1,
            'is_active'   => isset($body['is_active']) ? 1 : 0,
            'stock_type'  => $body['stock_type'] ?? 'unlimited',
            'stock_qty'   => $body['stock_qty'] ?? 0,
        ]);
        ProductModel::setTranslations($id, $body['t'] ?? []);
        $this->handleImageUpload($request, $id, false);
        $this->flash('success', 'Produkt uložen.');
        return $this->redirect($response, '/admin/products');
    }

    public function deleteImage(Request $request, Response $response, array $args): Response
    {
        $filename = ProductModel::deleteImage((int) $args['image_id']);
        if ($filename) {
            @unlink(self::UPLOAD_DIR . '/' . $filename);
            @unlink(self::UPLOAD_DIR . '/thumb_' . $filename);
        }
        $this->flash('success', 'Obrázek smazán.');
        return $this->redirect($response, '/admin/products/' . $args['id'] . '/edit');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $product = ProductModel::findById((int) $args['id']);
        if ($product) {
            foreach ($product['images'] as $img) {
                @unlink(self::UPLOAD_DIR . '/' . $img['filename']);
                @unlink(self::UPLOAD_DIR . '/thumb_' . $img['filename']);
            }
            ProductModel::delete((int) $args['id']);
        }
        $this->flash('success', 'Produkt smazán.');
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
}
