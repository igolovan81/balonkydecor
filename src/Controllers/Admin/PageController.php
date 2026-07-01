<?php
namespace App\Controllers\Admin;

use App\Models\PageModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PageController extends AdminBaseController
{
    private const LANGS = ['cs', 'en', 'ru', 'uk', 'sk'];
    private const SLUGS = ['home', 'services', 'contact'];

    public function index(Request $request, Response $response, array $args): Response
    {
        return $this->renderAdmin($request, $response, 'admin/pages/index.twig', ['slugs' => self::SLUGS]);
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'];
        if (!in_array($slug, self::SLUGS, true)) return $response->withStatus(404);
        $translations = PageModel::allTranslations($slug);
        return $this->renderAdmin($request, $response, 'admin/pages/form.twig', [
            'slug'         => $slug,
            'translations' => $translations,
            'langs'        => self::LANGS,
        ]);
    }

    public function editSubmit(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'];
        if (!in_array($slug, self::SLUGS, true)) return $response->withStatus(404);
        $body = (array) $request->getParsedBody();
        foreach (self::LANGS as $lang) {
            $t = $body['t'][$lang] ?? [];
            PageModel::upsert($slug, $lang, $t['title'] ?? '', $t['body'] ?? '', $t['meta_title'] ?? null, $t['meta_desc'] ?? null);
        }
        $this->flash('success', 'Stránka uložena.');
        return $this->redirect($response, '/admin/pages');
    }
}
