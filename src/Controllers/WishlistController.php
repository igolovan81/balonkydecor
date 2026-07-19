<?php
namespace App\Controllers;

use App\Services\Wishlist;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class WishlistController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');

        return $this->render($request, $response, 'public/wishlist.twig', [
            'items' => Wishlist::items($lang),
        ]);
    }

    public function toggle(Request $request, Response $response, array $args): Response
    {
        $lang   = $request->getAttribute('lang');
        $body   = (array) $request->getParsedBody();
        $sku    = trim($body['sku'] ?? '');
        $return = (string) ($body['return'] ?? '');

        if ($sku !== '') {
            Wishlist::toggle($sku);
        }

        $target = preg_match('#^/[a-z]{2}/#', $return) ? $return : "/{$lang}/wishlist";

        return $response->withHeader('Location', $target)->withStatus(302);
    }
}
