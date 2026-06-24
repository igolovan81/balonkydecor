<?php
namespace App\Controllers;

use App\Models\PageModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PageController extends BaseController
{
    public function services(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        $page = PageModel::find('services', $lang);
        return $this->render($request, $response, 'public/services.twig', [
            'page' => $page,
        ]);
    }
}
