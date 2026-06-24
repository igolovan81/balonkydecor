<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
class ShopController {
    public function __construct(private Twig $twig) {}
    public function index(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Shop — coming soon'); return $res;
    }
    public function product(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Product — coming soon'); return $res;
    }
}
