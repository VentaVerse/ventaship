<?php

namespace App\Couriers\Support;

/**
 * Tone map for the common fulfillment status spine
 * (Courier::statusPhase()'s 'phase' bucket, plus an
 * `unpaid` bucket for the order-status side of the queue).
 *
 * Every courier names its statuses differently (QuadX's `for_pickup` says
 * the same physical thing as SPX's own vocabulary will), and every one of
 * those raw strings is unfit to show a packer. The spine gives them one
 * shared bucket; this class gives each bucket one shared colour, so the
 * queue reads as ONE system no matter which courier a row belongs to.
 *
 * TONE PHILOSOPHY, one rule, applied per phase, adapted verbatim from the
 * ERP's own App\Support\ChannelStatusTone (same rule, same wording, ported
 * from "per channel" to "per phase" now that Venta has a single spine
 * instead of one status vocabulary per marketplace):
 *
 *   success  the operator's move, right now. This is the only tone that
 *            resolves to the accent colour, so it is the one thing the eye
 *            lands on when a hundred rows are on screen. Spend it nowhere
 *            else, only `to_pack` (go pack/book the shipment) earns it.
 *   warning  waiting on someone the seller CAN chase, a parcel on its way
 *            back (`returning`), or a buyer who hasn't paid yet (`unpaid`:
 *            the seller can follow up on a manual/reseller order sitting
 *            unpaid, the same "chaseable" reasoning as returning).
 *   info     waiting on someone the seller CANNOT chase, a courier
 *            creating, holding, or carrying the shipment (`booking`,
 *            `to_handover`, `in_transit`).
 *   neutral  settled. A finished order should recede, which is why
 *            `delivered` is neutral and not the success tone: a page where
 *            "pack this now" and "delivered weeks ago" are the same colour
 *            has no signal left.
 *   danger   ended badly, cancellations, failed/lost/damaged deliveries
 *            (`failed`, `cancelled`).
 *
 * An unrecognised phase resolves to neutral, unremarkable, never
 * accidentally alarming or reassuring.
 */
class PhaseTone
{
    /** The spine buckets, in their natural stage-first-then-happy-path-then-terminal order. */
    public const PHASES = [
        'unpaid',
        'to_pack',
        'booking',
        'to_handover',
        'in_transit',
        'delivered',
        'failed',
        'cancelled',
        'returning',
    ];

    /** @var array<string, string> */
    private const TONES = [
        'unpaid'      => 'warning',
        'to_pack'     => 'success',
        'booking'     => 'info',
        'to_handover' => 'info',
        'in_transit'  => 'info',
        'delivered'   => 'neutral',
        'failed'      => 'danger',
        'cancelled'   => 'danger',
        'returning'   => 'warning',
    ];

    /**
     * DISPLAY-ONLY overrides for phases whose internal name is not what an
     * operator should read.
     *
     * `in_transit` is the courier-side spine vocabulary and stays exactly
     * that everywhere it matters (statusPhase() contracts, the tone map
     * above, every courier adapter). The QUEUE, however, renamed its own tab
     * to Shipping, and a row chip that said "In Transit" while the tab above
     * it said "Shipping" was the last place the retired word survived. This
     * map exists solely so the chip reads the tab's word; nothing internal
     * ever consults it.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'in_transit' => 'Shipping',
    ];

    /** Badge tone for a spine phase, or 'neutral' for anything unmapped/null. */
    public static function toneFor(?string $phase): string
    {
        return self::TONES[$phase ?? ''] ?? 'neutral';
    }

    /**
     * Operator-facing label for a spine phase. Unmapped phases keep the
     * humanized form of their own name, so a courier adding a phase never
     * needs an entry here to read acceptably.
     */
    public static function labelFor(?string $phase): string
    {
        $phase = (string) $phase;

        return self::LABELS[$phase] ?? ucwords(str_replace('_', ' ', $phase));
    }
}
