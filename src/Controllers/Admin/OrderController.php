<?php
namespace App\Controllers\Admin;

use App\Models\OrderModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OrderController extends AdminBaseController
{
    private const STATUSES = ['pending', 'paid', 'ready', 'completed', 'cancelled'];

    public function index(Request $request, Response $response, array $args): Response
    {
        $params = $request->getQueryParams();
        $page   = max(1, (int) ($params['page'] ?? 1));
        $status = $params['status'] ?? '';

        $data = OrderModel::adminList($page, 20, $status);
        return $this->renderAdmin($request, $response, 'admin/orders/index.twig', [
            'orders'   => $data['orders'],
            'pages'    => $data['pages'],
            'page'     => $page,
            'status'   => $status,
            'statuses' => self::STATUSES,
        ]);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $order = OrderModel::findByNumber($args['number']);
        if (!$order) return $response->withStatus(404);
        return $this->renderAdmin($request, $response, 'admin/orders/detail.twig', [
            'order'    => $order,
            'statuses' => self::STATUSES,
        ]);
    }

    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $body   = (array) $request->getParsedBody();
        $status = $body['status'] ?? '';
        if (in_array($status, self::STATUSES, true)) {
            OrderModel::updateStatus($args['number'], $status);
            $this->flash('success', 'Status objednávky změněn.');
        }
        return $this->redirect($response, '/admin/orders/' . $args['number']);
    }
}
