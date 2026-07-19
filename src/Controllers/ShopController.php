<?php
namespace App\Controllers;

use App\Models\CategoryModel;
use App\Models\ProductModel;
use App\Services\Wishlist;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ShopController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang       = $request->getAttribute('lang');
        $params     = $request->getQueryParams();
        $categoryId = isset($params['category']) ? (int) $params['category'] : null;

        return $this->render($request, $response, 'public/shop/index.twig', [
            'categories'    => CategoryModel::allWithTranslation($lang),
            'products'      => ProductModel::allActive($lang, $categoryId),
            'active_cat'    => $categoryId,
            'wishlist_skus' => Wishlist::skus(),
        ]);
    }

    public function product(Request $request, Response $response, array $args): Response
    {
        $lang    = $request->getAttribute('lang');
        $product = ProductModel::findBySku($args['slug'], $lang);

        if (!$product) {
            return $response->withStatus(404);
        }

        $subtypePrices = array_column($product['subtypes'], 'price');

        return $this->render($request, $response, 'public/shop/product.twig', [
            'product'           => $product,
            'min_subtype_price' => $subtypePrices ? min($subtypePrices) : null,
            'max_subtype_price' => $subtypePrices ? max($subtypePrices) : null,
            'in_wishlist'       => Wishlist::has($product['sku']),
        ]);
    }
}
