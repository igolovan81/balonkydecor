<?php
namespace App\Controllers;

use App\Models\Database;
use App\Models\PageModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PageController extends BaseController
{
    public function services(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        $page = PageModel::find('services', $lang);
        return $this->render($request, $response, 'public/services.twig', [
            'page'     => $page,
            'services' => \App\Models\ServiceModel::allWithTranslation($lang),
        ]);
    }

    public function shippingPayment(Request $request, Response $response, array $args): Response
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('shipping_address', 'shipping_map_url')");
        $stmt->execute();
        $settings = array_column($stmt->fetchAll(), 'value', 'key');

        return $this->render($request, $response, 'public/shipping.twig', [
            'shipping_address' => $settings['shipping_address'] ?? '',
            'shipping_map_url' => $settings['shipping_map_url'] ?? '',
        ]);
    }
}
