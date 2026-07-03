<?php
namespace App\Services;

use App\Models\GalleryModel;
use App\Models\ProductModel;

class Sitemap
{
    public static function paths(): array
    {
        $paths = ['/', '/shop', '/services', '/services/archive', '/contact', '/shipping-payment'];

        foreach (ProductModel::allActive(Seo::DEFAULT_LANG) as $product) {
            $paths[] = '/shop/' . $product['sku'];
        }
        foreach (GalleryModel::albums(Seo::DEFAULT_LANG) as $album) {
            $paths[] = '/services/archive/' . $album['slug'];
        }

        return $paths;
    }

    public static function entries(): array
    {
        $entries = [];
        foreach (self::paths() as $path) {
            foreach (Seo::LANGUAGES as $lang) {
                $entries[] = [
                    'loc'        => Seo::canonicalUrl($lang, $path),
                    'alternates' => Seo::alternateUrls($path),
                ];
            }
        }
        return $entries;
    }
}
