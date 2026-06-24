<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
class BlogController {
    public function __construct(private Twig $twig) {}
    public function index(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Blog — coming soon'); return $res;
    }
    public function post(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Post — coming soon'); return $res;
    }
}
