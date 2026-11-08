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
}
