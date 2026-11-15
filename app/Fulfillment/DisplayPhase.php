<?php

namespace App\Fulfillment;

use App\Models\Shipment;
use App\Shipments\PhaseResolver;

/**
 * The phase the admin UI shows. `phase` stays null until the courier reports its first status (docs/api.md §8.2),
 * so until then it is derived from `state`. A state not listed here reads as attention. A courier status with no
 * shipment status (its status disabled or deleted, or mapped to none) reads as unmapped.
 */
final class DisplayPhase
{
    public const FROM_STATE = [
        'draft' => 'draft',
        'queued' => 'booking',
        'booking' => 'booking',
        'booked' => 'to_handover',
        'failed' => 'attention',
        'unknown' => 'attention',
        'cancelled' => 'cancelled',
    ];

    public const FALLBACK = 'attention';

    public static function of(Shipment $s): string
    {
        if (is_string($s->phase) && $s->phase !== '') {
            return $s->phase;
        }
        if (is_string($s->courier_status) && trim($s->courier_status) !== '') {
            return PhaseResolver::UNMAPPED;
        }

        return self::FROM_STATE[$s->state] ?? self::FALLBACK;
    }

    /** Portable (SQLite and MySQL) SQL for the display phase; the values are constants, never input. */
    public static function sql(string $table = 'shipments'): string
    {
        $cases = '';
        foreach (self::FROM_STATE as $state => $phase) {
            $cases .= " WHEN '{$state}' THEN '{$phase}'";
        }

        $unmapped = PhaseResolver::UNMAPPED;

        return "COALESCE(NULLIF({$table}.phase, ''), CASE WHEN TRIM(COALESCE({$table}.courier_status, '')) <> '' THEN '{$unmapped}' END,"
            ." CASE {$table}.state{$cases} ELSE '".self::FALLBACK."' END)";
    }
}
