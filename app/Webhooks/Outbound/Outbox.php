<?php

namespace App\Webhooks\Outbound;

use App\Models\ClientApp;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Writes an event for a client app's webhook. Always called inside the caller's transaction, so the event exists
 * exactly when the change it describes does. Delivery is a separate job.
 */
final class Outbox
{
    /**
     * @param  array<string, mixed>  $data  ['shipment' => array, 'event' => array|null]
     * @return OutgoingEvent|null null when there is nobody to tell (a manual or CSV shipment has no client app)
     */
    public static function record(?ClientApp $app, string $type, array $data, ?Shipment $s = null): ?OutgoingEvent
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Outbox::record() must run inside a transaction.');
        }

        if ($s !== null && $s->client_app_id === null) {
            return null;
        }

        if ($app === null) {
            return null;
        }

        $sequence = 0;
        if ($s !== null) {
            $s->increment('event_sequence'); // atomic UPDATE; the caller holds the row lock
            $sequence = (int) $s->event_sequence;
        }

        return OutgoingEvent::create([
            'client_app_id' => $app->id,
            'type' => $type,
            'shipment_id' => $s?->id,
            'sequence' => $sequence,
            'payload' => $data,
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => now(),
            'window_started_at' => now(),
        ]);
    }
}
