<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

class Navigation
{
    /**
     * @return array<int, array{label: ?string, items: array<int, array{label: string, route: string, icon: string, badge?: int, match: array<int, string>}>}>
     */
    public static function groups(): array
    {
        $definition = [
            [null, [
                ['Shipments', 'fulfillment', 'truck', ['fulfillment*']],
                ['Add shipment', 'shipments.create', 'plus'],
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
            $entries = array_map(fn (array $e) => $e + [3 => []], $entries);
            $items = [];

            foreach ($entries as [$text, $route, $icon, $match]) {
                if (Route::has($route)) {
                    $items[] = ['label' => $text, 'route' => $route, 'icon' => $icon, 'match' => $match];
                }
            }

            if ($items !== []) {
                $groups[] = ['label' => $label, 'items' => $items];
            }
        }

        return $groups;
    }

    /**
     * @param  array{route: string, match?: array<int, string>}  $item
     */
    public static function isActive(array $item): bool
    {
        return request()->routeIs($item['route'], ...($item['match'] ?? []));
    }
}
