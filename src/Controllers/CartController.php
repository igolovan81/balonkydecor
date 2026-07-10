<?php
namespace App\Controllers;

use App\Models\ProductModel;
use App\Services\Cart;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CartController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        return $this->render($request, $response, 'public/cart.twig', [
            'items' => Cart::items(),
            'total' => Cart::total(),
        ]);
    }

    public function add(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        $body = (array) $request->getParsedBody();
        $sku  = trim($body['sku'] ?? '');
        $qty  = max(1, (int) ($body['qty'] ?? 1));

        if ($sku) {
            $product = ProductModel::findBySku($sku, $lang);
            if ($product) {
                if (!empty($product['subtypes'])) {
                    $subtypeId = isset($body['subtype_id']) && $body['subtype_id'] !== ''
                        ? (int) $body['subtype_id'] : null;
                    $subtype = null;
                    foreach ($product['subtypes'] as $st) {
                        if ((int) $st['id'] === $subtypeId) {
                            $subtype = $st;
                            break;
                        }
                    }
                    if ($subtype) {
                        Cart::add(
                            $sku, $qty,
                            $product['name'] . ' — ' . $subtype['name'],
                            (string) $subtype['price'],
                            (int) $subtype['id'], $subtype['name']
                        );
                    }
                    // no valid subtype_id posted → do nothing, cart unchanged
                } else {
                    Cart::add($sku, $qty, $product['name'], (string) $product['price']);
                }
            }
        }

        return $response->withHeader('Location', "/{$lang}/cart")->withStatus(302);
    }

    public function remove(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        $body = (array) $request->getParsedBody();
        $sku  = trim($body['sku'] ?? '');
        if ($sku) {
            Cart::remove($sku);
        }
        return $response->withHeader('Location', "/{$lang}/cart")->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $lang  = $request->getAttribute('lang');
        $body  = (array) $request->getParsedBody();
        $items = $body['items'] ?? [];
        foreach ($items as $sku => $qty) {
            Cart::update($sku, (int) $qty);
        }
        return $response->withHeader('Location', "/{$lang}/cart")->withStatus(302);
    }
}
