<?php
namespace App\Services;

class Cart
{
    private static function boot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
    }

    public static function add(
        string $sku, int $qty, string $name, string $price,
        ?int $subtypeId = null, ?string $subtypeName = null
    ): void {
        self::boot();
        $key = $subtypeId !== null ? $sku . ':' . $subtypeId : $sku;
        if (isset($_SESSION['cart'][$key])) {
            $_SESSION['cart'][$key]['qty'] += $qty;
        } else {
            $_SESSION['cart'][$key] = [
                'qty'          => $qty,
                'name'         => $name,
                'price'        => $price,
                'sku'          => $sku,
                'subtype_id'   => $subtypeId,
                'subtype_name' => $subtypeName,
            ];
        }
    }

    public static function remove(string $sku): void
    {
        self::boot();
        unset($_SESSION['cart'][$sku]);
    }

    public static function update(string $sku, int $qty): void
    {
        self::boot();
        if ($qty <= 0) {
            unset($_SESSION['cart'][$sku]);
        } else {
            $_SESSION['cart'][$sku]['qty'] = $qty;
        }
    }

    public static function items(): array
    {
        self::boot();
        $items = [];
        foreach ($_SESSION['cart'] as $sku => $item) {
            $subtotal    = number_format((float) $item['price'] * $item['qty'], 2, '.', '');
            $items[$sku] = array_merge($item, ['subtotal' => $subtotal]);
        }
        return $items;
    }

    public static function count(): int
    {
        self::boot();
        return array_sum(array_column($_SESSION['cart'], 'qty'));
    }

    public static function total(): string
    {
        self::boot();
        $sum = 0.0;
        foreach ($_SESSION['cart'] as $item) {
            $sum += (float) $item['price'] * $item['qty'];
        }
        return number_format($sum, 2, '.', '');
    }

    public static function clear(): void
    {
        self::boot();
        $_SESSION['cart'] = [];
    }

    public static function isEmpty(): bool
    {
        self::boot();
        return empty($_SESSION['cart']);
    }
}
