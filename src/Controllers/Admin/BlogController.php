<?php
namespace App\Controllers\Admin;

use App\Models\BlogModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BlogController extends AdminBaseController
{
    private const LANGS = ['cs', 'en', 'ru', 'uk', 'sk'];

    public function index(Request $request, Response $response, array $args): Response
    {
        $params = $request->getQueryParams();
        $page   = max(1, (int) ($params['page'] ?? 1));
        $data   = BlogModel::adminList($page, 20);
        return $this->renderAdmin($request, $response, 'admin/blog/index.twig', [
            'posts' => $data['posts'],
            'pages' => $data['pages'],
            'page'  => $page,
        ]);
    }

    public function createForm(Request $request, Response $response, array $args): Response
    {
        return $this->renderAdmin($request, $response, 'admin/blog/form.twig', [
            'post'         => null,
            'translations' => [],
            'langs'        => self::LANGS,
        ]);
    }

    public function createSubmit(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $id   = BlogModel::create([
            'slug'         => trim($body['slug'] ?? ''),
            'status'       => $body['status'] ?? 'draft',
            'published_at' => $body['published_at'] ?? null,
        ]);
        BlogModel::setTranslations($id, $body['t'] ?? []);
        $this->flash('success', 'Příspěvek vytvořen.');
        return $this->redirect($response, '/admin/blog');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $post = BlogModel::findById((int) $args['id']);
        if (!$post) return $response->withStatus(404);
        $translations = BlogModel::getTranslations((int) $args['id']);
        return $this->renderAdmin($request, $response, 'admin/blog/form.twig', [
            'post'         => $post,
            'translations' => $translations,
            'langs'        => self::LANGS,
        ]);
    }

    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $body = (array) $request->getParsedBody();
        BlogModel::update($id, [
            'slug'         => trim($body['slug'] ?? ''),
            'status'       => $body['status'] ?? 'draft',
            'published_at' => $body['published_at'] ?? null,
        ]);
        BlogModel::setTranslations($id, $body['t'] ?? []);
        $this->flash('success', 'Příspěvek uložen.');
        return $this->redirect($response, '/admin/blog');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        BlogModel::delete((int) $args['id']);
        $this->flash('success', 'Příspěvek smazán.');
        return $this->redirect($response, '/admin/blog');
    }
}
