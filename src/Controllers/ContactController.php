<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
class ContactController {
    public function __construct(private Twig $twig) {}
    public function index(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Contact — coming soon'); return $res;
    }
    public function send(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Sent — coming soon'); return $res;
    }
}
