<?php
namespace App\Services;

use App\Models\ProductModel;

class Wishlist
{
    private static function boot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['wishlist'])) {
            $_SESSION['wishlist'] = [];
        }
    }

    public static function toggle(string $sku): bool
    {
        self::boot();
        $key = array_search($sku, $_SESSION['wishlist'], true);
        if ($key !== false) {
            unset($_SESSION['wishlist'][$key]);
            $_SESSION['wishlist'] = array_values($_SESSION['wishlist']);
            return false;
        }
        $_SESSION['wishlist'][] = $sku;
        return true;
    }

    public static function has(string $sku): bool
    {
        self::boot();
        return in_array($sku, $_SESSION['wishlist'], true);
    }

    public static function skus(): array
    {
        self::boot();
        return $_SESSION['wishlist'];
    }

    public static function count(): int
    {
        self::boot();
        return count($_SESSION['wishlist']);
    }

    public static function items(string $lang): array
    {
        self::boot();
        $items = [];
        foreach ($_SESSION['wishlist'] as $sku) {
            $product = ProductModel::findBySku($sku, $lang);
            if ($product !== null) {
                $items[] = $product;
            }
        }
        return $items;
    }
}
