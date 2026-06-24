<?php
namespace App\Controllers;

use App\Models\OrderModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OrderController extends BaseController
{
    public function status(Request $request, Response $response, array $args): Response
    {
        $lang  = $request->getAttribute('lang');
        $order = OrderModel::findByNumber($args['number']);
        if (!$order) {
            return $response->withStatus(404);
        }
        return $this->render($request, $response, 'public/order/status.twig', [
            'order' => $order,
        ]);
    }
}
