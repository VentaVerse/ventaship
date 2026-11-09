<?php

namespace App\Couriers;

use App\Couriers\Contracts\CancelsLocallyOnly;
use App\Couriers\Contracts\Courier;

/**
 * What one courier can do, as the client API reports it (GET /couriers, and each courier account).
 *
 * `cancel` is read off the courier (a CancelsLocallyOnly courier cannot cancel at the courier). The rest are facts
 * about each courier's API that the Courier contract cannot express (every courier implements getPickupSlots(),
 * but QuadX's always answers []), so they are declared here, one row per registered courier. A courier with no row
 * reports nothing optional.
 */
final class CourierCapabilities
{
    private const DECLARED = [
        'quadx' => ['methods' => ['pickup'], 'pickup_slots' => false, 'quotes' => true, 'coverage' => true, 'webhooks' => true, 'label_formats' => ['zebra', 'fuji']],
        'spx' => ['methods' => ['pickup', 'dropoff'], 'pickup_slots' => true, 'quotes' => true, 'coverage' => true, 'webhooks' => true, 'label_formats' => ['pdf']],
        'lbc' => ['methods' => ['pickup'], 'pickup_slots' => true, 'quotes' => true, 'coverage' => true, 'webhooks' => false, 'label_formats' => ['pdf']],
    ];

    private const NONE = ['methods' => ['pickup'], 'pickup_slots' => false, 'quotes' => false, 'coverage' => false, 'webhooks' => false, 'label_formats' => []];

    /** The short form, for a courier account. */
    public static function flags(string $key): array
    {
        $declared = self::DECLARED[$key] ?? self::NONE;
        $courier = CourierRegistry::describe($key);

        return [
            'methods' => $declared['methods'],
            'cancel' => ! $courier instanceof CancelsLocallyOnly,
            'pickup_slots' => $declared['pickup_slots'],
            'quotes' => $declared['quotes'],
            'coverage' => $declared['coverage'],
            'webhooks' => $declared['webhooks'],
            'poll_only' => ! $declared['webhooks'],
            'label_formats' => $declared['label_formats'],
        ];
    }

    /** The full description, for GET /couriers: the flags plus the courier's own option lists. */
    public static function describe(string $key): array
    {
        $courier = CourierRegistry::describe($key);

        return ['key' => $key, 'name' => $courier->getName()]
            + self::flags($key)
            + [
                'service_types' => self::options($courier, 'getServiceTypes'),
                'parcel_options' => self::options($courier, 'getParcelOptions'),
                'shipping_payment_options' => self::options($courier, 'getShippingPaymentOptions'),
            ];
    }

    /** @return list<array{value: string, label: string}> */
    private static function options(Courier $courier, string $method): array
    {
        return array_values(array_map(
            fn (array $o) => ['value' => (string) $o['value'], 'label' => (string) $o['label']],
            array_filter($courier->{$method}(), fn ($o) => is_array($o) && isset($o['value'], $o['label']) && empty($o['disabled'])),
        ));
    }
}
