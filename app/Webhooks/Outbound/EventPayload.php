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
    /** @throws \JsonException when the stored payload is not valid JSON */
    public static function for(OutgoingEvent $e): string
    {
        return json_encode([
            'id' => $e->public_id,
            'type' => $e->type,
            'created_at' => $e->created_at?->copy()->setTimezone(config('app.timezone'))->toIso8601String(),
            'sequence' => (int) $e->sequence,
            'app' => $e->clientApp?->public_id,
            'data' => self::data($e),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Decoded from the stored JSON as objects, not through the array cast, so an empty object stays `{}`. */
    private static function data(OutgoingEvent $e): object
    {
        $raw = $e->getRawOriginal('payload');
        $data = is_string($raw) ? json_decode($raw, false, 512, JSON_THROW_ON_ERROR) : null;

        return is_object($data) ? $data : new \stdClass;
    }
}
