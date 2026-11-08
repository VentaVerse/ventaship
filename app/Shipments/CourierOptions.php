<?php

namespace App\Shipments;

use App\Couriers\PickupPoint;
use App\Models\Shipment;

/**
 * A shipment's booking options in the shape the courier port reads (`$opts` of createShipment() and estimateFee()):
 * flat parcel fields, the opaque pickup slot, the shipping payment and the pickup point.
 */
final class CourierOptions
{
    /** @return array<string, mixed> */
    public static function for(Shipment $s): array
    {
        $options = is_array($s->options) ? $s->options : [];
        $parcel = is_array($options['parcel'] ?? null) ? $options['parcel'] : [];

        return array_filter([
            'service' => $s->service,
            'parcel' => $parcel['type'] ?? null,
            'weight_kg' => $parcel['weight_kg'] ?? null,
            'length_cm' => $parcel['length_cm'] ?? null,
            'width_cm' => $parcel['width_cm'] ?? null,
            'height_cm' => $parcel['height_cm'] ?? null,
            'pickup_slot' => $options['pickup_slot'] ?? null,
            'pickup_date' => $options['pickup_date'] ?? null,
            'shipping_payment' => $options['shipping_payment'] ?? null,
            'buyer_payment' => $s->payment_method,
            'pickup' => is_array($s->pickup) ? PickupPoint::fromArray($s->pickup) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
