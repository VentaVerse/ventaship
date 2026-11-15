<?php

namespace App\Fulfillment;

class Couriers
{
    private const NAMES = ['quadx' => 'QuadX', 'spx' => 'SPX', 'lbc' => 'LBC'];

    private const CODES = ['quadx' => 'QX', 'spx' => 'SPX', 'lbc' => 'LBC'];

    public static function name(string $courier): string
    {
        return self::NAMES[$courier] ?? $courier;
    }

    public static function code(string $courier): string
    {
        return self::CODES[$courier] ?? strtoupper($courier);
    }

    /** The courier's logo under public/, or null when there is none (the tile then shows its code). */
    public static function logo(string $courier): ?string
    {
        $path = 'brand/couriers/'.$courier.'.png';

        return preg_match('/^[a-z0-9]+$/', $courier) === 1 && is_file(public_path($path)) ? $path : null;
    }
}
