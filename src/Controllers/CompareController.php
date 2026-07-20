<?php
namespace App\Controllers;

use App\Services\Compare;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CompareController extends BaseController
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $lang  = $request->getAttribute('lang');
        $items = Compare::items($lang);

        $attributes = [];
        foreach ($items as $product) {
            foreach ($product['specs'] as $spec) {
                if (!in_array($spec['attribute_name'], $attributes, true)) {
                    $attributes[] = $spec['attribute_name'];
                }
            }
        }

        return $this->render($request, $response, 'public/compare.twig', [
            'items'      => $items,
            'attributes' => $attributes,
        ]);
    }

    public function toggle(Request $request, Response $response, array $args): Response
    {
        $lang   = $request->getAttribute('lang');
        $body   = (array) $request->getParsedBody();
        $sku    = trim($body['sku'] ?? '');
        $return = (string) ($body['return'] ?? '');

        if ($sku !== '') {
            $result = Compare::toggle($sku);
            if ($result['full']) {
                $this->flash('error', 'compare.full');
            }
        }

        $target = preg_match('#^/[a-z]{2}/#', $return) ? $return : "/{$lang}/compare";

        return $response->withHeader('Location', $target)->withStatus(302);
    }

    public function clear(Request $request, Response $response, array $args): Response
    {
        $lang = $request->getAttribute('lang');
        Compare::clear();

        return $response->withHeader('Location', "/{$lang}/compare")->withStatus(302);
    }
}
