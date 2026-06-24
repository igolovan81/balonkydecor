<?php
namespace App\Controllers\Admin;

use App\Models\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardController extends AdminBaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $pdo   = Database::getConnection();
        $stats = [
            'orders_today'    => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
            'orders_pending'  => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn(),
            'orders_total'    => (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
            'products_active' => (int) $pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 1")->fetchColumn(),
        ];

        $recent = $pdo->query(
            "SELECT order_number, customer_name, total_amount, status, created_at
             FROM orders ORDER BY created_at DESC LIMIT 10"
        )->fetchAll();

        return $this->renderAdmin($request, $response, 'admin/dashboard.twig', [
            'stats'  => $stats,
            'recent' => $recent,
        ]);
    }
}
