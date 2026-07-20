<?php
namespace App\Controllers\Admin;

use App\Models\CategoryModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CategoryController extends AdminBaseController
{
    private const LANGS               = ['cs', 'sk', 'en', 'uk', 'ru'];
    private const TRANSLATABLE_FIELDS = ['name', 'description', 'legal_notice'];

    public function index(Request $request, Response $response, array $args): Response
    {
        $categories = CategoryModel::all($request->getAttribute('admin_lang', 'cs'));
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
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        $slug   = trim($body['slug'] ?? '');
        if ($slug === '') {
            $nameForSlug = trim($body['t']['en']['name'] ?? '');
            if ($nameForSlug === '') {
                foreach (self::LANGS as $lang) {
                    $candidate = trim($body['t'][$lang]['name'] ?? '');
                    if ($candidate !== '') {
                        $nameForSlug = $candidate;
                        break;
                    }
                }
            }
            $slug = CategoryModel::slugify($nameForSlug);
        }
        $slug = CategoryModel::uniqueSlug($slug);
        $id   = CategoryModel::create(
            ['slug' => $slug, 'sort_order' => (int) ($body['sort_order'] ?? 0)],
            $userId
        );
        $translations = \App\Services\Translator::autoFill(
            $body['t'] ?? [],
            $request->getAttribute('admin_lang', 'cs'),
            self::LANGS,
            self::TRANSLATABLE_FIELDS
        );
        CategoryModel::setTranslations($id, $translations);
        \App\Services\Notifier::notify(
            'category', $id, $this->categoryLabel($translations, $body),
            'created', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
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
        $id     = (int) $args['id'];
        $body   = (array) $request->getParsedBody();
        $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
        CategoryModel::update(
            $id,
            ['slug' => trim($body['slug'] ?? ''), 'sort_order' => (int) ($body['sort_order'] ?? 0)],
            $userId
        );
        $translations = $body['t'] ?? [];
        CategoryModel::setTranslations($id, $translations);
        \App\Services\Notifier::notify(
            'category', $id, $this->categoryLabel($translations, $body),
            'updated', $userId, $_SESSION['admin_user']['email'] ?? ''
        );
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
        $category     = CategoryModel::findById($id);
        $translations = CategoryModel::getTranslations($id);
        CategoryModel::delete($id);
        if ($category) {
            $userId = (int) ($_SESSION['admin_user']['id'] ?? 0);
            \App\Services\Notifier::notify(
                'category', $id, $this->categoryLabel($translations, $category),
                'deleted', $userId, $_SESSION['admin_user']['email'] ?? ''
            );
        }
        $this->flash('success', 'categories.flash.deleted');
        return $this->redirect($response, '/admin/categories');
    }

    private function categoryLabel(array $translations, array $data): string
    {
        $name = $translations['cs']['name'] ?? '';
        if ($name !== '') return $name;
        $slug = trim($data['slug'] ?? '');
        return $slug !== '' ? $slug : 'category';
    }
}
