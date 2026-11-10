<?php

namespace App\Shipments;

use App\Models\Shipment;

/**
 * Where the cash on a shipment stands. Moves forward only: cod_unpaid < cod_collected < cod_remitted, and
 * cod_void is reachable from cod_unpaid alone. Ventaship records the state; it never holds the money.
 */
final class PaymentState
{
    private const ORDER = ['cod_unpaid' => 0, 'cod_collected' => 1, 'cod_remitted' => 2];

    /** Phases in which a COD parcel will never be paid for. */
    private const VOID_PHASES = ['cancelled', 'returning', 'lost_damaged'];

    public static function after(Shipment $s, string $phase): string
    {
        if ($s->payment_method !== 'cod') {
            return 'prepaid';
        }

        $current = $s->payment_state;
        if (! is_string($current) || $current === 'prepaid') {
            $current = 'cod_unpaid';
        }

        if (! array_key_exists($current, self::ORDER)) {
            return $current; // cod_void or anything unrecognised is final
        }

        if ($phase === 'delivered') {
            return self::ORDER[$current] < self::ORDER['cod_collected'] ? 'cod_collected' : $current;
        }

        if ($current === 'cod_unpaid' && in_array($phase, self::VOID_PHASES, true)) {
            return 'cod_void';
        }

        return $current;
    }
}
