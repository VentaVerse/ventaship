<?php

namespace App\Fulfillment;

use App\Models\Shipment;

/** Badge labels and tones shared by the list rows and the detail page, so both always agree. */
final class ShipmentBadges
{
    private const PHASE_TONES = [
        'draft' => 'mute', 'booking' => 'cool', 'attention' => 'attn', 'to_handover' => 'cool', 'in_transit' => 'cool',
        'delivered' => 'good', 'failed' => 'bad', 'lost_damaged' => 'bad', 'returning' => 'attn', 'cancelled' => 'mute',
    ];

    public static function tone(string $phase): string
    {
        return self::PHASE_TONES[$phase] ?? 'mute';
    }

    /** A refused booking reads bad; every other row by its display phase. */
    public static function statusTone(Shipment $s): string
    {
        $phase = DisplayPhase::of($s);

        return $phase === 'attention' && $s->state === 'failed' ? 'bad' : self::tone($phase);
    }

    /**
     * A shipment with no payment state yet (stored before it was tracked) reads from its payment method: COD is
     * unpaid until the courier reports it collected.
     *
     * @return array{0: string, 1: string} label and tone
     */
    public static function payment(Shipment $s): array
    {
        return match ($s->payment_state ?? ($s->payment_method === 'cod' ? 'cod_unpaid' : 'prepaid')) {
            'cod_unpaid' => ['COD · Unpaid', 'attn'],
            'cod_collected' => ['COD · Collected', 'cool'],
            'cod_remitted' => ['COD · Remitted', 'good'],
            'cod_void' => ['COD · Void', 'mute'],
            default => ['Prepaid', 'mute'],
        };
    }

    /** Cash on delivery, by its payment state, or by its payment method when it has no state yet. */
    public static function isCod(Shipment $s): bool
    {
        return $s->payment_state !== null
            ? str_starts_with((string) $s->payment_state, 'cod_')
            : $s->payment_method === 'cod';
    }
}
