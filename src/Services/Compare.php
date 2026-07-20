<?php
namespace App\Services;

use App\Models\ProductModel;

class Compare
{
    private const MAX_ITEMS = 4;

    private static function boot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['compare'])) {
            $_SESSION['compare'] = [];
        }
    }

    public static function toggle(string $sku): array
    {
        self::boot();
        $key = array_search($sku, $_SESSION['compare'], true);
        if ($key !== false) {
            unset($_SESSION['compare'][$key]);
            $_SESSION['compare'] = array_values($_SESSION['compare']);
            return ['added' => false, 'full' => false];
        }
        if (count($_SESSION['compare']) >= self::MAX_ITEMS) {
            return ['added' => false, 'full' => true];
        }
        $_SESSION['compare'][] = $sku;
        return ['added' => true, 'full' => false];
    }

    public static function has(string $sku): bool
    {
        self::boot();
        return in_array($sku, $_SESSION['compare'], true);
    }

    public static function skus(): array
    {
        self::boot();
        return $_SESSION['compare'];
    }

    public static function count(): int
    {
        self::boot();
        return count($_SESSION['compare']);
    }

    public static function clear(): void
    {
        self::boot();
        $_SESSION['compare'] = [];
    }

    public static function items(string $lang): array
    {
        self::boot();
        $items = [];
        foreach ($_SESSION['compare'] as $sku) {
            $product = ProductModel::findBySku($sku, $lang);
            if ($product !== null) {
                $items[] = $product;
            }
        }
        return $items;
    }
}
