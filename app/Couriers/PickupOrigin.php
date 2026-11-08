<?php

namespace App\Couriers;

/**
 * Where a parcel leaves from. The client sends the pickup address with every shipment, so the caller passes it in
 * `$opts['pickup']` (a PickupPoint, or the API's pickup array) and nothing is guessed.
 */
final class PickupOrigin
{
    /** Every pickup address is in the Philippines. */
    public const COUNTRY = 'PH';

    public static function from(array $opts): ?PickupPoint
    {
        $pickup = $opts['pickup'] ?? null;

        return match (true) {
            $pickup instanceof PickupPoint => $pickup,
            is_array($pickup) && $pickup !== [] => PickupPoint::fromArray($pickup),
            default => null,
        };
    }
}
