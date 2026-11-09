<?php

namespace App\Webhooks\Outbound;

use App\Http\Resources\ShipmentResource;
use App\Models\ClientApp;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Support\AfterResponse;
use App\Webhooks\Outbound\Jobs\DeliverEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Writes an event for a client app's webhook. Always called inside the caller's transaction, so the event exists
 * exactly when the change it describes does. Once that transaction commits, delivery is tried in this process after
 * the response (DeliverEvent, run by AfterResponse); a rolled-back change sends nothing. ventaship:events-deliver
 * stays the retry path for whatever is still pending.
 */
final class Outbox
{
    /**
     * With a shipment, `data.shipment` is always that shipment as ShipmentResource renders it (never its parties),
     * taken after the caller's changes, so a webhook carries exactly what GET /shipments/{id} would answer.
     *
     * @param  array<string, mixed>  $data  e.g. ['event' => array|null]
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

        if ($s !== null) {
            $data = ['shipment' => (new ShipmentResource($s))->resolve()] + $data;
        }

        $event = OutgoingEvent::create([
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

        $id = $event->id;
        $appId = $app->id;
        DB::afterCommit(fn () => AfterResponse::run("deliver event {$id}", fn () => self::deliver($id, $appId)));

        return $event;
    }

    /**
     * One delivery try, under DeliverEvent's own rules (lease, due time, earlier sequence still pending). An app whose
     * URL just failed to connect is left to ventaship:events-deliver, as that command leaves it, so no attempt is spent.
     */
    private static function deliver(int $eventId, int $appId): void
    {
        if (Cache::has(DeliverEvent::unreachableKey($appId))) {
            return;
        }

        (new DeliverEvent($eventId))->handle();
    }
}
