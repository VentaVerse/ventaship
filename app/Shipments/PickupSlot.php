<?php

namespace App\Shipments;

use App\Couriers\Lbc\LbcFulfillment;
use App\Couriers\Spx\SpxFulfillment;
use App\Models\Shipment;
use Carbon\CarbonImmutable;

/**
 * A shipment's stored `pickup_slot` as people read it: the label the UI and POST /pickup-slots show, and its day.
 * SPX slots are SPX's JSON blob; LBC slots are a date (Y-m-d). Other couriers have no slots, and an SPX drop-off
 * ignores its slot, so both answer null there.
 */
final class PickupSlot
{
    /** "Tue, Nov 10 · 08:00-12:00" (SPX) or "Tue, Nov 10" (LBC), Manila; null when there is no usable slot. */
    public static function label(Shipment $s): ?string
    {
        $slot = self::raw($s);

        return match ($s->courier) {
            'spx' => SpxFulfillment::describeSlot($slot),
            'lbc' => ($date = self::lbcDate($slot)) === null ? null : LbcFulfillment::dateLabel($date),
            default => null,
        };
    }

    /** The slot's day, Y-m-d in Manila; null when there is no usable slot. */
    public static function date(Shipment $s): ?string
    {
        $slot = self::raw($s);

        return match ($s->courier) {
            'spx' => SpxFulfillment::slotDay($slot),
            'lbc' => self::lbcDate($slot)?->format('Y-m-d'),
            default => null,
        };
    }

    private static function raw(Shipment $s): ?string
    {
        $slot = data_get($s->options, 'pickup_slot');
        if (! is_string($slot) || $slot === '') {
            return null;
        }

        return $s->courier === 'spx' && $s->method === 'dropoff' ? null : $slot;
    }

    /** An LBC slot is a calendar date; anything that is not exactly a real Y-m-d date is no slot. */
    private static function lbcDate(?string $slot): ?CarbonImmutable
    {
        if ($slot === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $slot, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $slot, config('app.timezone')) ?: null;
    }
}
