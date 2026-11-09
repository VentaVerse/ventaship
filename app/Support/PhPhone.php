<?php

namespace App\Support;

final class PhPhone
{
    /** The phone as people read it, "0917 123 4567"; anything that is not an 11-digit 09 number is shown as stored. */
    public static function shown(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return null;
        }
        $digits = (string) preg_replace('/\D/', '', $phone);

        return strlen($digits) === 11 && str_starts_with($digits, '09')
            ? substr($digits, 0, 4).' '.substr($digits, 4, 3).' '.substr($digits, 7)
            : $phone;
    }
}
