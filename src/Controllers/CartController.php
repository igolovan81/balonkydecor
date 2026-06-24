<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
class CartController {
    public function __construct(private Twig $twig) {}
    public function index(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Cart — coming soon'); return $res;
    }
    public function add(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Added'); return $res;
    }
    public function remove(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Removed'); return $res;
    }
}
