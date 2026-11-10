<?php

namespace App\Support;

class Money
{
    /** "1240.5" to "₱1,240.50". String maths only; money is never a float. */
    public static function peso(string $amount): string
    {
        $amount = trim($amount);
        $negative = str_starts_with($amount, '-');
        [$whole, $frac] = array_pad(explode('.', ltrim($amount, '+-'), 2), 2, '');
        $whole = ltrim($whole, '0') === '' ? '0' : ltrim($whole, '0');
        $frac = str_pad(substr($frac, 0, 2), 2, '0');
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        return ($negative ? '-' : '').'₱'.$grouped.'.'.$frac;
    }

    /** The largest amount a decimal(12,2) money column holds, in whole pesos (10 digits). */
    private const MAX_WHOLE_DIGITS = 10;

    /**
     * An amount a courier reported (a JSON int, float or numeric string) as an exact two-decimal string, or null
     * when it is absent, not a plain non-negative number, or too large to store. Never a float in storage: a float
     * from JSON is formatted once, here, and only its first two decimals are kept.
     */
    public static function decimal(mixed $value): ?string
    {
        $text = match (true) {
            is_int($value) => (string) $value,
            is_float($value) && is_finite($value) => sprintf('%.2F', $value),
            is_string($value) => trim($value),
            default => null,
        };

        if ($text === null || preg_match('/^\d+(\.\d+)?$/', $text) !== 1) {
            return null;
        }

        [$whole, $frac] = array_pad(explode('.', $text, 2), 2, '');
        $whole = ltrim($whole, '0') === '' ? '0' : ltrim($whole, '0');
        if (strlen($whole) > self::MAX_WHOLE_DIGITS) {
            return null;
        }

        return $whole.'.'.str_pad(substr($frac, 0, 2), 2, '0');
    }
}
