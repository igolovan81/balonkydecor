<?php
namespace App\Controllers;

use App\Services\Seo;
use App\Services\Sitemap;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SeoController extends BaseController
{
    public function robots(Request $request, Response $response, array $args): Response
    {
        $body = "User-agent: *\n"
              . "Disallow: /admin/\n"
              . "Disallow: /*/cart\n"
              . "Disallow: /*/checkout\n"
              . "Disallow: /*/order/\n"
              . "Disallow: /*/payment/\n"
              . "\n"
              . "Sitemap: " . Seo::BASE_URL . "/sitemap.xml\n";
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/plain');
    }

    public function sitemap(Request $request, Response $response, array $args): Response
    {
        $xml = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
            . 'xmlns:xhtml="http://www.w3.org/1999/xhtml"/>'
        );
        foreach (Sitemap::entries() as $entry) {
            $url = $xml->addChild('url');
            $url->addChild('loc', htmlspecialchars($entry['loc']));
            foreach ($entry['alternates'] as $alt) {
                $link = $url->addChild('xhtml:link', null, 'http://www.w3.org/1999/xhtml');
                $link->addAttribute('rel', 'alternate');
                $link->addAttribute('hreflang', $alt['lang']);
                $link->addAttribute('href', $alt['url']);
            }
        }
        $response->getBody()->write($xml->asXML());
        return $response->withHeader('Content-Type', 'application/xml');
    }
}
