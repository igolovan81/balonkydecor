<?php
namespace App\Controllers\Admin;

use App\Models\ProductModel;
use App\Models\CategoryModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProductDashboardController extends AdminBaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang = (string) $request->getAttribute('admin_lang', 'cs');

        return $this->renderAdmin($request, $response, 'admin/dashboard-products.twig', [
            'stats'      => ProductModel::dashboardStats(),
            'categories' => CategoryModel::withProductCounts($lang),
            'sellers'    => ProductModel::topSellers(10),
            'recent'     => ProductModel::recentActivity($lang, 10),
        ]);
    }
}
