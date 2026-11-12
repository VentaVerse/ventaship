<?php

namespace App\Shipments;

use App\Models\Shipment;
use Closure;
use RuntimeException;

/**
 * The reference every courier receives for a new shipment (SPX order_id, QuadX reference_id, LBC ReferenceNoOne):
 * `YYYYMMDD-XXXXXX`, the date in the app's time zone and 6 random capitals or digits. Unique within the install; a
 * shipment keeps it for life (edits, Retry and rebooking reuse it). Shipments made before keep the one they have.
 */
final class CourierReference
{
    public const CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    public const PATTERN = '/^\d{8}-[A-Z0-9]{6}$/';

    private const LENGTH = 6;

    private const ATTEMPTS = 20;

    /** @var (Closure(): string)|null tests: the 6 characters to use instead of random ones */
    private static ?Closure $suffixes = null;

    public static function generate(): string
    {
        $date = now(config('app.timezone'))->format('Ymd');
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $reference = $date.'-'.self::suffix();
            if (! Shipment::query()->where('courier_ref', $reference)->exists()) {
                return $reference;
            }
        }

        throw new RuntimeException('No free courier reference after '.self::ATTEMPTS.' attempts.');
    }

    /** @param  (Closure(): string)|null  $suffixes */
    public static function fakeSuffixes(?Closure $suffixes): void
    {
        self::$suffixes = $suffixes;
    }

    private static function suffix(): string
    {
        if (self::$suffixes !== null) {
            return (self::$suffixes)();
        }

        $suffix = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $suffix .= self::CHARS[random_int(0, strlen(self::CHARS) - 1)];
        }

        return $suffix;
    }
}
