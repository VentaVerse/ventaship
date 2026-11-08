<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

class Navigation
{
    /**
     * @return array<int, array{label: ?string, items: array<int, array{label: string, route: string, icon: string, badge?: int}>}>
     */
    public static function groups(): array
    {
        $definition = [
            [null, [
                ['Fulfillment', 'fulfillment', 'truck'],
                ['New shipment', 'shipments.create', 'plus'],
                ['Import CSV', 'imports.create', 'upload'],
            ]],
            ['Setup', [
                ['Courier accounts', 'accounts.index', 'building'],
                ['Pickup addresses', 'pickup.index', 'map-pin'],
                ['Status mapping', 'mapping.index', 'refresh-cw'],
            ]],
            ['Developers', [
                ['Connected platforms', 'apps.index', 'plug'],
                ['Webhook logs', 'webhooks.index', 'receipt'],
            ]],
            ['System', [
                ['Health', 'system.health', 'shield'],
                ['Unmatched webhooks', 'system.unmatched', 'alert-triangle'],
                ['Failed jobs', 'system.failed', 'x'],
            ]],
            [null, [
                ['Settings', 'settings.edit', 'cog'],
            ]],
        ];

        $groups = [];

        foreach ($definition as [$label, $entries]) {
            $items = [];

            foreach ($entries as [$text, $route, $icon]) {
                if (Route::has($route)) {
                    $items[] = ['label' => $text, 'route' => $route, 'icon' => $icon];
                }
            }

            if ($items !== []) {
                $groups[] = ['label' => $label, 'items' => $items];
            }
        }

        return $groups;
    }

    /**
     * @param  array{route: string}  $item
     */
    public static function isActive(array $item): bool
    {
        $prefix = preg_replace('/\.[^.]+$/', '', $item['route']);

        return request()->routeIs($item['route'], $prefix === $item['route'] ? $item['route'] : $prefix.'.*');
    }
}
