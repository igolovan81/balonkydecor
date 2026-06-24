<?php
namespace App\Controllers;

use App\Models\BlogModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BlogController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang   = $request->getAttribute('lang');
        $params = $request->getQueryParams();
        $page   = max(1, (int) ($params['page'] ?? 1));

        return $this->render($request, $response, 'public/blog/index.twig',
            BlogModel::published($lang, $page)
        );
    }

    public function post(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        $post = BlogModel::findBySlug($args['slug'], $lang);
        if (!$post) {
            return $response->withStatus(404);
        }
        return $this->render($request, $response, 'public/blog/post.twig', [
            'post' => $post,
        ]);
    }
}
