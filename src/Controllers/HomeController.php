<?php
namespace App\Controllers;

use App\Models\PageModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HomeController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        return $this->render($request, $response, 'public/home.twig', [
            'page' => PageModel::find('home', $lang),
        ]);
    }
}
