<?php
namespace App\Controllers\Admin;

use App\Models\OrderModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OrderDashboardController extends AdminBaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $stats  = OrderModel::dashboardStats();
        $status = OrderModel::statusBreakdown();

        $revenue = OrderModel::revenueByDay(30);
        $max     = 0.0;
        foreach ($revenue as $day) {
            $max = max($max, $day['total']);
        }
        foreach ($revenue as &$day) {
            $day['pct'] = $max > 0 ? (int) round(($day['total'] / $max) * 100) : 0;
        }
        unset($day);

        $gopayRate = $stats['orders_total'] > 0
            ? (int) round(100 * $stats['gopay_count'] / $stats['orders_total'])
            : 0;

        return $this->renderAdmin($request, $response, 'admin/dashboard-orders.twig', [
            'stats'      => $stats,
            'gopay_rate' => $gopayRate,
            'status'     => $status,
            'revenue'    => $revenue,
            'recent'     => OrderModel::adminList(1, 10)['orders'],
        ]);
    }
}
