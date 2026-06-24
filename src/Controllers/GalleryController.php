<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
class GalleryController {
    public function __construct(private Twig $twig) {}
    public function index(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Gallery — coming soon'); return $res;
    }
    public function album(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Album — coming soon'); return $res;
    }
}
