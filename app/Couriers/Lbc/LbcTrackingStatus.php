<?php

namespace App\Couriers\Lbc;

use Carbon\CarbonImmutable;

/**
 * LBC's Track and Trace events turned into this module's status words.
 *
 * Track and Trace V2 (Table 6.0) gives every event a StatusId and a text,
 * but many different events share StatusId 0: picked up, received at a
 * branch, forwarded, ready for pickup, and the COD remittance. So an event
 * is read by its id first and by its text whenever the id alone does not
 * say what happened. An id this class does not know is read by its text,
 * and anything still unrecognised counts as in transit, never as delivered.
 *
 * The words are this module's own (stored on orders.courier_status and used
 * as the keys of the status map), so they never change once shipped.
 */
final class LbcTrackingStatus
{
    /** A pickup request LBC saved; no parcel has been scanned yet. */
    public const BOOKED = 'booked';

    /** LBC did not say whether it saved the pickup request. */
    public const UNCONFIRMED = 'unconfirmed';

    public const PICKED_UP = 'picked_up';
    public const ENCODED = 'encoded';
    public const RECEIVED = 'received';
    public const FORWARDED = 'forwarded';
    public const IN_TRANSIT = 'in_transit';
    public const OUT_FOR_DELIVERY = 'out_for_delivery';
    public const READY_FOR_PICKUP = 'ready_for_pickup';
    public const DELAYED = 'delayed';
    public const FAILED_ATTEMPT = 'failed_attempt';
    public const NEEDS_ATTENTION = 'needs_attention';
    public const DAMAGED = 'damaged';
    public const RETURNING = 'returning';
    public const RETURNED = 'returned';
    public const DELIVERED = 'delivered';
    public const COD_REMITTED = 'cod_remitted';

    /** Written by a local cancel; LBC itself has no cancel. */
    public const CANCELED = 'canceled';

    /** StatusId => status, for the ids whose meaning does not depend on the text. */
    public const BY_ID = [
        1 => self::DELIVERED,
        5 => self::DELIVERED,
        15 => self::DELIVERED,
        8001 => self::ENCODED,
        8810 => self::RECEIVED,
        1004 => self::RECEIVED,
        8820 => self::FORWARDED,
        8002 => self::OUT_FOR_DELIVERY,
        // Failed delivery without an attempt: LBC delivers later.
        19 => self::DELAYED, 28 => self::DELAYED, 22 => self::DELAYED, 17 => self::DELAYED,
        13 => self::DELAYED, 30 => self::DELAYED, 53 => self::DELAYED, 12 => self::DELAYED,
        14 => self::DELAYED, 11 => self::DELAYED,
        // A rider tried and LBC re-delivers.
        29 => self::FAILED_ATTEMPT, 3 => self::FAILED_ATTEMPT, 6 => self::FAILED_ATTEMPT,
        24 => self::FAILED_ATTEMPT, 48 => self::FAILED_ATTEMPT, 4 => self::FAILED_ATTEMPT,
        2 => self::FAILED_ATTEMPT,
        // LBC asks the shipper to get in touch.
        23 => self::NEEDS_ATTENTION, 10 => self::NEEDS_ATTENTION, 54 => self::NEEDS_ATTENTION,
        59 => self::NEEDS_ATTENTION, 9 => self::NEEDS_ATTENTION, 8 => self::NEEDS_ATTENTION,
        7 => self::NEEDS_ATTENTION,
        18 => self::DAMAGED,
        27 => self::RETURNING,
        57 => self::RETURNING,
        25 => self::RETURNED,
        51 => self::RETURNED,
    ];

    /** Text fragments (upper case) => status, checked in this order. */
    private const BY_TEXT = [
        'DEPOSITED TO ACCOUNT' => self::COD_REMITTED,
        'REMITTED' => self::COD_REMITTED,
        'RETURNED TO THE SENDER' => self::RETURNED,
        'RETURNED TO SHIPPER' => self::RETURNED,
        'PICKED UP BY LBC' => self::PICKED_UP,
        'READY FOR PICK' => self::READY_FOR_PICKUP,
        // Before any "delivered" match: "was not delivered" must never read
        // as a delivery.
        'TRIED TO DELIVER' => self::FAILED_ATTEMPT,
        'NOT DELIVERED' => self::DELAYED,
        'UNDELIVERED' => self::DELAYED,
        'RELEASED TO' => self::DELIVERED,
        'CLAIMED BY' => self::DELIVERED,
        'RECEIVED BY' => self::DELIVERED,
        'DELIVERED' => self::DELIVERED,
        'EXPECT DELIVERY' => self::OUT_FOR_DELIVERY,
        'OUT FOR DEL' => self::OUT_FOR_DELIVERY,
        'EN ROUTE' => self::FORWARDED,
        'ENROUTE' => self::FORWARDED,
        'FORWARDED' => self::FORWARDED,
        'RECEIVED AT' => self::RECEIVED,
        'ENCODED' => self::ENCODED,
    ];

    /** Display words, in the order the parcel's journey is read. */
    public const LABELS = [
        self::BOOKED => 'Pickup requested',
        self::UNCONFIRMED => 'Pickup request not confirmed',
        self::PICKED_UP => 'Picked up by LBC',
        self::ENCODED => 'Encoded by LBC',
        self::RECEIVED => 'Received at an LBC branch',
        self::FORWARDED => 'On the way to the next branch',
        self::IN_TRANSIT => 'In transit',
        self::READY_FOR_PICKUP => 'Ready for pickup at a branch',
        self::OUT_FOR_DELIVERY => 'Out for delivery',
        self::DELAYED => 'Delivery delayed',
        self::FAILED_ATTEMPT => 'Delivery attempt failed',
        self::NEEDS_ATTENTION => 'Needs attention',
        self::DAMAGED => 'Damaged',
        self::RETURNING => 'Returning to sender',
        self::RETURNED => 'Returned to sender',
        self::DELIVERED => 'Delivered',
        self::COD_REMITTED => 'COD remitted',
        self::CANCELED => 'Canceled',
    ];

    /** The status one event stands for. */
    public static function forEvent(string|int|null $statusId, ?string $text): string
    {
        $id = is_numeric($statusId) ? (int) $statusId : null;

        if ($id !== null && $id !== 0 && isset(self::BY_ID[$id])) {
            return self::BY_ID[$id];
        }

        $upper = strtoupper(preg_replace('/\s+/u', ' ', (string) $text));
        foreach (self::BY_TEXT as $needle => $status) {
            if (str_contains($upper, $needle)) {
                return $status;
            }
        }

        return self::IN_TRANSIT;
    }

    /**
     * The history as a chronological timeline, and the latest status.
     *
     * @param array<int, array{status_id:string, date:string, status:string}> $history from LbcClient::track()
     * @return array{status: ?string, timeline: array<int, array{status:string, at:int, detail:string}>}
     */
    public static function fromHistory(array $history): array
    {
        $events = [];
        foreach (array_values($history) as $position => $event) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) ($event['status'] ?? '')));
            $at = self::timestamp((string) ($event['date'] ?? ''));
            if ($at === null) {
                continue;
            }
            $events[] = [
                'status' => self::forEvent($event['status_id'] ?? null, $text),
                'at' => $at,
                'detail' => mb_substr($text, 0, 255),
                'position' => $position,
            ];
        }

        // Chronological, and in LBC's own order where two share a second.
        usort($events, fn (array $a, array $b) => [$a['at'], $a['position']] <=> [$b['at'], $b['position']]);

        $timeline = array_map(fn (array $e) => ['status' => $e['status'], 'at' => $e['at'], 'detail' => $e['detail']], $events);

        return [
            'status' => $timeline === [] ? null : $timeline[array_key_last($timeline)]['status'],
            'timeline' => $timeline,
        ];
    }

    /** "05/09/2023 03:36:37 PM", Manila time, as a Unix timestamp. */
    public static function timestamp(string $posted): ?int
    {
        $posted = trim(preg_replace('/\s+/', ' ', $posted));
        if ($posted === '') {
            return null;
        }

        foreach (['m/d/Y h:i:s A', 'm/d/Y H:i:s', 'm/d/Y h:i A', 'm/d/Y'] as $format) {
            try {
                $at = CarbonImmutable::createFromFormat('!' . $format, $posted, LbcPickupRequestBuilder::TIMEZONE);
            } catch (\Throwable) {
                continue;
            }
            if ($at !== false && $at !== null) {
                return $at->getTimestamp();
            }
        }

        return null;
    }

    /**
     * The shared spine bucket and the legacy stepper for one status. See
     * Courier::statusPhase().
     *
     * @return array{phase:string, step:int, failed:bool}
     */
    public static function phase(?string $status): array
    {
        return match ((string) $status) {
            self::DELIVERED, self::COD_REMITTED => ['phase' => 'delivered', 'step' => 3, 'failed' => false],
            self::PICKED_UP, self::ENCODED, self::RECEIVED, self::FORWARDED, self::IN_TRANSIT,
            self::OUT_FOR_DELIVERY, self::READY_FOR_PICKUP, self::DELAYED => ['phase' => 'in_transit', 'step' => 2, 'failed' => false],
            self::FAILED_ATTEMPT, self::NEEDS_ATTENTION, self::DAMAGED => ['phase' => 'failed', 'step' => 2, 'failed' => true],
            self::RETURNING, self::RETURNED => ['phase' => 'returning', 'step' => 2, 'failed' => true],
            self::CANCELED, 'cancelled' => ['phase' => 'cancelled', 'step' => 1, 'failed' => true],
            // Booked, unconfirmed, or nothing yet: waiting for the rider.
            default => ['phase' => 'to_handover', 'step' => 1, 'failed' => false],
        };
    }
}
