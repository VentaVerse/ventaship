<?php

namespace App\Couriers\Support;

/**
 * The module's single PH mobile-number rule.
 *
 * Lifted verbatim out of SpxFulfillment::normalizePhPhone() (which now
 * delegates here) so the pickup-address form validates a number by exactly
 * the rule the courier payload will later be built with. A second, looser
 * rule in the form would let an operator save a number SPX then refuses at
 * booking time, which is the failure this shares one implementation to avoid.
 *
 * SPX states the format (docs/spx/api.md §1.4, sender_phone/deliver_phone):
 * "11 digits starting with 0, or 12 digits starting with 63". Everything but
 * digits is stripped first, so the shapes actually seen in the dev DB all
 * resolve: "+63 917 123 4567" and "639171234567" pass through as 12 digits
 * starting with 63; "09171234567" passes through as-is; a bare 10-digit
 * mobile gains its leading zero. Anything else (a landline, a truncated
 * number, free text) is not a PH mobile and returns null.
 */
final class PhPhone
{
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
            return $digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return $digits;
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '0' . $digits;
        }

        return null;
    }

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
