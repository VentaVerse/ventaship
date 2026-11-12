<?php

namespace App\Couriers\Spx;

use App\Couriers\Support\CourierRefusal;

/**
 * SPX's refusals in plain words. SPX answers the fee check (2.1, ret_code 13051) and the create (1.4/1.5, 13101)
 * with the same parcel rules, worded for its engineers:
 *
 *   "check rule|7 |ruleName| MaxLengthExtremumRule failed, 35.000000 exceed max value 25.000000"
 *   "parcel weight should less than or equal to 10 kg"
 *
 * and, for the rest, with an outer code from portal/guide/08 (10001 to 10086). Each becomes a title and a detail a
 * seller can act on; anything else is one generic sentence. SPX's own text is kept by the caller, never shown here.
 */
final class SpxRefusals
{
    public const GENERIC_TITLE = "SPX couldn't book this shipment";

    public const GENERIC_DETAIL = 'Check the details and try again.';

    /** Size rules: the word in SPX's rule name, then [too much, too little, the side's name, the unit]. */
    private const SIDES = [
        'length' => ['Too long for SPX', 'Too short for SPX', 'Length', 'cm'],
        'width' => ['Too wide for SPX', 'Too narrow for SPX', 'Width', 'cm'],
        'height' => ['Too tall for SPX', 'Too flat for SPX', 'Height', 'cm'],
        'weight' => ['Too heavy for SPX', 'Too light for SPX', 'Weight', 'kg'],
    ];

    /** Outer codes a seller can act on (portal/guide/08), as [title, detail]. */
    private const CODES = [
        10001 => ['Too heavy for SPX', 'Check the weight.'],
        10003 => ['COD too high for SPX', 'Lower the COD amount.'],
        10004 => ['COD too low for SPX', 'Raise the COD amount.'],
        10011 => ['No SPX service for this parcel', 'Check the parcel details.'],
        10012 => ['COD amount not accepted by SPX', 'Check the COD amount.'],
        10013 => ['Pickup time not available', 'Choose another pickup time.'],
        10014 => ["SPX doesn't pick up here", 'Check the pickup address.'],
        10015 => ["SPX doesn't pick up COD here", 'Check the pickup address.'],
        10016 => ["SPX doesn't deliver here", 'Check the delivery address.'],
        10017 => ["SPX doesn't collect COD here", 'Check the delivery address.'],
        10018 => ["SPX doesn't pick up here", 'Check the pickup address.'],
        10019 => ["SPX doesn't deliver here", 'Check the delivery address.'],
        10021 => ['Too far for SPX', 'The delivery address is too far from the pickup address.'],
        10022 => ['Too close for SPX', 'The delivery address is too close to the pickup address.'],
        10023 => ["SPX doesn't serve this route", 'Check the pickup and delivery addresses.'],
        10024 => ['Pickup not available', 'Choose Drop off myself.'],
        10025 => ["SPX doesn't pick up here", 'Check the pickup address.'],
        10026 => ["SPX doesn't deliver here", 'Check the delivery address.'],
        10027 => ['Pickup postcode not valid', 'Check the pickup address.'],
        10028 => ['Postcode not valid', 'Check the delivery address.'],
        10029 => ["SPX doesn't pick up COD here", 'Check the pickup address.'],
        10030 => ["SPX doesn't collect COD here", 'Check the delivery address.'],
        10031 => ["SPX doesn't pick up COD here", 'Check the pickup address.'],
        10032 => ["SPX doesn't collect COD here", 'Check the delivery address.'],
        10033 => ["Outside SPX's area", 'Check the delivery address.'],
        10038 => ['Pickup time not available', 'Choose another pickup time.'],
        10039 => ['Pickup time not available', 'Choose another pickup time.'],
        10040 => ['Pickup time not available', 'Choose another pickup time.'],
        10043 => ['COD amount not accepted by SPX', 'Check the COD amount.'],
        10050 => ['Item category not accepted by SPX', 'Choose another item category.'],
        10051 => ['Recipient details not accepted by SPX', 'Check the name and phone.'],
        10054 => ["SPX doesn't pick up here", 'Check the pickup address.'],
        10055 => ["SPX doesn't deliver here", 'Check the delivery address.'],
        10056 => ['Pickup time not available', 'Choose another pickup time.'],
        10059 => ["SPX doesn't deliver here", 'Check the delivery address.'],
        10062 => ["SPX doesn't serve this route", 'Check the pickup and delivery addresses.'],
        10064 => ['Weight not accepted by SPX', 'Check the weight.'],
        10065 => ["SPX doesn't serve these addresses", 'Check the pickup and delivery addresses.'],
        10066 => ["SPX doesn't deliver here", 'Check the delivery address.'],
        10067 => ["SPX doesn't deliver here", 'Check the delivery address.'],
        10068 => ["SPX doesn't deliver here", 'Check the delivery address.'],
        10069 => ["SPX doesn't serve this route", 'Check the pickup and delivery addresses.'],
        10070 => ["SPX doesn't pick up here", 'Check the pickup address.'],
        10071 => ["SPX doesn't deliver here", 'Check the delivery address.'],
        10074 => ["SPX doesn't pick up here", 'Check the pickup address.'],
        10075 => ["SPX doesn't deliver here", 'Check the delivery address.'],
        10077 => ["SPX can't find the pickup address", 'Check the pickup address.'],
        10078 => ["SPX can't find the delivery address", 'Check the delivery address.'],
        10079 => ['Recipient details not accepted by SPX', 'Check the name and phone.'],
        10080 => ['Pickup time has passed', 'Choose another pickup time.'],
        10081 => ['Pickup time not available', 'Choose another pickup time.'],
        10082 => ['Too light for SPX', 'Check the weight and size.'],
        10083 => ['Too heavy for SPX', 'Check the weight and size.'],
        10084 => ['Too long for SPX', 'Check the length.'],
        10085 => ['Too wide for SPX', 'Check the width.'],
        10086 => ['Too tall for SPX', 'Check the height.'],
        // Seen on the fee check of a COD order from an account without one: create refuses it the same way.
        12054 => ['COD not set up with SPX', 'Add a bank account with SPX to send COD parcels.'],
    ];

    /** Codes for the parcel's own sides: with the typed figure known, the detail names it. */
    private const SIDE_CODES = [10084 => 'length', 10085 => 'width', 10086 => 'height', 10001 => 'weight'];

    /**
     * SPX-side failures (an outage, a rate table it could not match): not the seller's to fix, so never a refusal.
     * The auth (1xxx) and internal (99xxx) families are not refusals either; see isRefusal().
     */
    private const THEIR_SIDE = [10020, 10044, 10045, 10049, 10052, 10057, 10060, 10061, 10076];

    /**
     * Whether an SPX answer with this code means SPX will not take the shipment as it stands: a business rule
     * (10xxx), a parameter it refused (11001), a failed fee check or create (13051, 13101), or COD on an account
     * with no bank account (12054). Everything else is an answer about SPX or the account, not about this shipment.
     */
    public static function isRefusal(?int $code): bool
    {
        if ($code === null) {
            return false;
        }
        if (in_array($code, self::THEIR_SIDE, true)) {
            return false;
        }

        return ($code >= 10000 && $code <= 10999) || in_array($code, [11001, 12054, 13051, 13101], true);
    }

    /**
     * @param  array<string, mixed>  $context  the shipment's own figures: weight_kg, length_cm, width_cm, height_cm
     */
    public static function explain(?int $code, ?string $text, array $context = []): CourierRefusal
    {
        $text = trim((string) $text);

        if (($rule = self::fromRule($text)) !== null) {
            return $rule;
        }

        if (preg_match('/weight should (?:be )?less than or equal to\s*(\d+(?:\.\d+)?)\s*kg/i', $text, $m) === 1) {
            return self::side('weight', true, self::figure($context, 'weight'), $m[1]);
        }

        if ($code !== null && isset(self::SIDE_CODES[$code]) && ($figure = self::figure($context, self::SIDE_CODES[$code])) !== null) {
            [, , $name, $unit] = self::SIDES[self::SIDE_CODES[$code]];

            return new CourierRefusal(self::CODES[$code][0], "{$name} is {$figure} {$unit}.");
        }

        if ($code !== null && isset(self::CODES[$code])) {
            return new CourierRefusal(...self::CODES[$code]);
        }

        if ($code === 11001 && preg_match('/phone/i', $text) === 1) {
            return new CourierRefusal('Phone number not accepted by SPX', 'Check the phone number.');
        }

        return self::generic();
    }

    /** The API code for each side a parcel is too big on. */
    public const PARCEL_CODES = [
        'length' => 'parcel_too_long',
        'width' => 'parcel_too_wide',
        'height' => 'parcel_too_tall',
        'weight' => 'parcel_too_heavy',
    ];

    private const SIDE_OUTER_CODES = [10001 => 'weight', 10083 => 'weight', 10084 => 'length', 10085 => 'width', 10086 => 'height'];

    /**
     * `parcel_too_long`, `parcel_too_wide`, `parcel_too_tall` or `parcel_too_heavy` when SPX refused the parcel for
     * being over a limit (its Max...ExtremumRule and weight messages, or outer codes 10001 and 10083 to 10086);
     * null for anything else, a minimum included.
     */
    public static function parcelCode(?int $code, ?string $text): ?string
    {
        $text = trim((string) $text);
        if (preg_match('/([A-Za-z]+)ExtremumRule failed,?\s*\d+(?:\.\d+)?\s*exceeds?\s+(?:the\s+)?max/i', $text, $m) === 1) {
            $rule = strtolower($m[1]);
            foreach (self::PARCEL_CODES as $side => $parcelCode) {
                if (str_contains($rule, $side)) {
                    return $parcelCode;
                }
            }

            return null;
        }
        if (preg_match('/weight should (?:be )?less than or equal to/i', $text) === 1) {
            return self::PARCEL_CODES['weight'];
        }

        return $code !== null && isset(self::SIDE_OUTER_CODES[$code]) ? self::PARCEL_CODES[self::SIDE_OUTER_CODES[$code]] : null;
    }

    public static function generic(): CourierRefusal
    {
        return new CourierRefusal(self::GENERIC_TITLE, self::GENERIC_DETAIL);
    }

    /** "MaxLengthExtremumRule failed, 35.000000 exceed max value 25.000000", and its "less than min value" twin. */
    private static function fromRule(string $text): ?CourierRefusal
    {
        $pattern = '/([A-Za-z]+)ExtremumRule failed,?\s*(\d+(?:\.\d+)?)\s*(exceeds?\s+(?:the\s+)?max|(?:is\s+)?less\s+than\s+(?:the\s+)?min)\s*value\s*(\d+(?:\.\d+)?)/i';
        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }

        $over = stripos($m[3], 'max') !== false;
        $rule = strtolower($m[1]);
        foreach (array_keys(self::SIDES) as $side) {
            if (str_contains($rule, $side)) {
                return self::side($side, $over, CourierRefusal::number($m[2]), $m[4]);
            }
        }

        return $over
            ? new CourierRefusal('Too big for SPX', 'Check the size and weight.')
            : new CourierRefusal('Too small for SPX', 'Check the size and weight.');
    }

    private static function side(string $side, bool $over, ?string $figure, string $limit): CourierRefusal
    {
        [$tooMuch, $tooLittle, $name, $unit] = self::SIDES[$side];
        $limit = CourierRefusal::number($limit);
        $bound = $over ? "SPX takes up to {$limit} {$unit}." : "SPX takes at least {$limit} {$unit}.";

        return new CourierRefusal($over ? $tooMuch : $tooLittle, $figure === null ? $bound : "{$name} is {$figure} {$unit}. {$bound}");
    }

    /** The shipment's own figure for a side, as people write it; null when it is not known. */
    private static function figure(array $context, string $side): ?string
    {
        $key = $side === 'weight' ? 'weight_kg' : "{$side}_cm";
        $value = $context[$key] ?? null;

        return is_numeric($value) && (float) $value > 0 ? CourierRefusal::number(self::plain($value)) : null;
    }

    /** A float or numeric string as a plain decimal (no exponent) for number(). */
    private static function plain(mixed $value): string
    {
        return is_float($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : trim((string) $value);
    }
}
