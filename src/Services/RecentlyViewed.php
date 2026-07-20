<?php
namespace App\Services;

use App\Models\ProductModel;

class RecentlyViewed
{
    private const COOKIE_NAME = 'recently_viewed';
    private const MAX_ITEMS   = 8;
    private const TTL_DAYS    = 90;

    private static function read(): array
    {
        $raw     = $_COOKIE[self::COOKIE_NAME] ?? '';
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private static function write(array $skus): void
    {
        $value = json_encode($skus);
        $_COOKIE[self::COOKIE_NAME] = $value;
        setcookie(self::COOKIE_NAME, $value, [
            'expires'  => time() + self::TTL_DAYS * 86400,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function track(string $sku): void
    {
        $skus = self::read();
        $skus = array_values(array_diff($skus, [$sku]));
        array_unshift($skus, $sku);
        $skus = array_slice($skus, 0, self::MAX_ITEMS);
        self::write($skus);
    }

    public static function skus(?string $exclude = null): array
    {
        $skus = self::read();
        if ($exclude !== null) {
            $skus = array_values(array_diff($skus, [$exclude]));
        }
        return $skus;
    }

    public static function items(string $lang, ?string $exclude = null): array
    {
        $items = [];
        foreach (self::skus($exclude) as $sku) {
            $product = ProductModel::findBySku($sku, $lang);
            if ($product !== null) {
                $items[] = $product;
            }
        }
        return $items;
    }
}
