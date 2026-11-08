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
}
