<?php
namespace App\Controllers\Admin;

use App\Models\CustomerModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CustomerDashboardController extends AdminBaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $signups = CustomerModel::signupsByDay(30);
        $max     = 0;
        foreach ($signups as $day) {
            $max = max($max, $day['count']);
        }
        foreach ($signups as &$day) {
            $day['pct'] = $max > 0 ? (int) round(($day['count'] / $max) * 100) : 0;
        }
        unset($day);

        return $this->renderAdmin($request, $response, 'admin/dashboard-customers.twig', [
            'stats'   => CustomerModel::dashboardStats(),
            'signups' => $signups,
            'recent'  => CustomerModel::recent(10),
        ]);
    }
}
