<?php

namespace App\Fulfillment;

use App\Models\Shipment;

/**
 * The phase the admin UI shows. `phase` stays null until the courier reports its first status (docs/api.md §8.2),
 * so until then it is derived from `state`. A state not listed here reads as attention.
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

        return self::FROM_STATE[$s->state] ?? self::FALLBACK;
    }

    /** Portable (SQLite and MySQL) SQL for the display phase; the values are constants, never input. */
    public static function sql(string $table = 'shipments'): string
    {
        $cases = '';
        foreach (self::FROM_STATE as $state => $phase) {
            $cases .= " WHEN '{$state}' THEN '{$phase}'";
        }

        return "COALESCE(NULLIF({$table}.phase, ''), CASE {$table}.state{$cases} ELSE '".self::FALLBACK."' END)";
    }
}
