<?php

namespace App\Shipments;

use App\Couriers\Contracts\ProvidesPublicTrackingUrl;
use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Models\Shipment;
use Illuminate\Support\Facades\Log;
use Throwable;

/** The tracking link shown for a shipment, in API answers and in the admin screens. */
class TrackingLink
{
    /** The courier's public tracker wins over its API links (owner ruling 2026-09-03); never fatal to the answer. */
    public static function for(Shipment $s): ?string
    {
        if ($s->tracking_number === null || $s->courier === null || ! CourierRegistry::has($s->courier)) {
            return null;
        }

        try {
            $courier = CourierRegistry::describe($s->courier);
            if ($courier instanceof ProvidesPublicTrackingUrl) {
                $url = $courier->publicTrackingUrl($s->tracking_number, is_array($s->meta) ? $s->meta : []);
                if ($url !== null) {
                    return $url;
                }
            }

            return $courier->trackingLinks(CourierOrder::fromApi($s))[0]['url'] ?? null;
        } catch (Throwable $e) {
            Log::warning('Tracking URL could not be built', ['shipment' => $s->public_id, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
