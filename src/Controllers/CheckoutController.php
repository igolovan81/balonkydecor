<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
class CheckoutController {
    public function __construct(private Twig $twig) {}
    public function index(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Checkout — coming soon'); return $res;
    }
    public function submit(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Submitted'); return $res;
    }
    public function confirm(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Confirm — coming soon'); return $res;
    }
}
