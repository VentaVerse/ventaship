<?php

namespace App\Couriers;

use App\Models\CourierStatusLabelSet;

/**
 * The words a courier status is shown in, anywhere a person reads it: the labels last refreshed from the courier
 * (courier_status_labels), then a courier's own pre-known vocabulary (statusLabels(), where a courier has one), then
 * the raw code with its underscores spaced and its first letter raised. The raw code itself stays on the shipment
 * (courier_status) for the API.
 */
final class CourierStatusLabel
{
    /** @var array<string, array<string, string>> */
    private static array $labels = [];

    public static function for(string $courierKey, string $status): string
    {
        $labels = self::labels($courierKey);

        if (isset($labels[$status]) && is_string($labels[$status]) && $labels[$status] !== '') {
            return $labels[$status];
        }

        return ucfirst(str_replace(['_', '-'], ' ', $status));
    }

    /** "{Courier}: {label}", the sentence a history row carries. */
    public static function sentence(CourierOrder $order, string $status): string
    {
        $key = (string) $order->courier_provider;
        $name = CourierRegistry::has($key) ? CourierRegistry::describe($key)->getName() : ucfirst($key);

        return $name . ': ' . self::for($key, $status);
    }

    /** @return array<string, string> */
    private static function labels(string $courierKey): array
    {
        if (isset(self::$labels[$courierKey])) {
            return self::$labels[$courierKey];
        }

        $cached = CourierStatusLabelSet::query()->where('courier', $courierKey)->value('labels');
        $cached = is_string($cached) ? json_decode($cached, true) : $cached;
        $cached = is_array($cached) ? $cached : [];

        $courier = CourierRegistry::has($courierKey) ? CourierRegistry::describe($courierKey) : null;
        $known = ($courier && method_exists($courier, 'statusLabels')) ? (array) $courier->statusLabels() : [];

        return self::$labels[$courierKey] = $cached + $known;
    }

    public static function forget(): void
    {
        self::$labels = [];
    }
}
