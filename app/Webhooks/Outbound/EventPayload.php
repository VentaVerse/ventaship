<?php

namespace App\Webhooks\Outbound;

use App\Models\OutgoingEvent;

/**
 * The JSON envelope of an outgoing webhook:
 * {"id":"evt_…","type":"…","created_at":"…+08:00","sequence":7,"app":"app_…","data":{…}}.
 * `data` is the payload stored when the event was recorded (for shipment events, the shipment snapshot and the event).
 */
final class EventPayload
{
    public static function for(OutgoingEvent $e): string
    {
        $data = $e->payload;

        return json_encode([
            'id' => $e->public_id,
            'type' => $e->type,
            'created_at' => $e->created_at?->copy()->setTimezone(config('app.timezone'))->toIso8601String(),
            'sequence' => (int) $e->sequence,
            'app' => $e->clientApp?->public_id,
            'data' => is_array($data) && $data !== [] ? $data : new \stdClass,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
