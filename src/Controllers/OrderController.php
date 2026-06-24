<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
class OrderController {
    public function __construct(private Twig $twig) {}
    public function status(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Order status — coming soon'); return $res;
    }
}
