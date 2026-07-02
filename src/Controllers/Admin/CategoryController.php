<?php
namespace App\Controllers\Admin;

use App\Models\CategoryModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CategoryController extends AdminBaseController
{
    private const LANGS               = ['cs', 'sk', 'en', 'uk', 'ru'];
    private const TRANSLATABLE_FIELDS = ['name', 'description'];

    public function index(Request $request, Response $response, array $args): Response
    {
        $categories = CategoryModel::all();
        return $this->renderAdmin($request, $response, 'admin/categories/index.twig', compact('categories'));
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        return $this->renderAdmin($request, $response, 'admin/categories/form.twig', [
            'category'     => null,
            'translations' => [],
            'langs'        => self::LANGS,
        ]);
    }

    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $id   = CategoryModel::create(['slug' => trim($body['slug'] ?? ''), 'sort_order' => (int) ($body['sort_order'] ?? 0)]);
        $translations = \App\Services\Translator::autoFill(
            $body['t'] ?? [],
            $request->getAttribute('admin_lang', 'cs'),
            self::LANGS,
            self::TRANSLATABLE_FIELDS
        );
        CategoryModel::setTranslations($id, $translations);
        $this->flash('success', 'categories.flash.created');
        return $this->redirect($response, '/admin/categories');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $category = CategoryModel::findById((int) $args['id']);
        if (!$category) return $response->withStatus(404);
        $translations = CategoryModel::getTranslations((int) $args['id']);
        return $this->renderAdmin($request, $response, 'admin/categories/form.twig', [
            'category'     => $category,
            'translations' => $translations,
            'langs'        => self::LANGS,
        ]);
    }

    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $body = (array) $request->getParsedBody();
        CategoryModel::update($id, ['slug' => trim($body['slug'] ?? ''), 'sort_order' => (int) ($body['sort_order'] ?? 0)]);
        CategoryModel::setTranslations($id, $body['t'] ?? []);
        $this->flash('success', 'categories.flash.updated');
        return $this->redirect($response, '/admin/categories');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if (CategoryModel::hasProducts($id)) {
            $this->flash('error', 'categories.flash.delete_blocked');
            return $this->redirect($response, '/admin/categories');
        }
        CategoryModel::delete($id);
        $this->flash('success', 'categories.flash.deleted');
        return $this->redirect($response, '/admin/categories');
    }
}
