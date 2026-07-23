<?php
namespace App\Controllers\Admin;

use App\Models\CustomerModel;
use App\Models\OrderModel;
use App\Models\ProductModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardController extends AdminBaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        return $this->renderAdmin($request, $response, 'admin/dashboard.twig', [
            'orders_today'    => OrderModel::dashboardStats()['orders_today'],
            'products_active' => ProductModel::dashboardStats()['active_count'],
            'customers_total' => CustomerModel::dashboardStats()['total'],
        ]);
    }
}
