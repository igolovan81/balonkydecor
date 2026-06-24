<?php
namespace App\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
class PaymentController {
    public function __construct(private Twig $twig) {}
    public function initiate(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Payment — coming soon'); return $res;
    }
    public function paymentReturn(Request $req, Response $res, array $args): Response {
        $res->getBody()->write('Payment return — coming soon'); return $res;
    }
    public function notify(Request $req, Response $res, array $args): Response {
        return $res->withStatus(200);
    }
}
