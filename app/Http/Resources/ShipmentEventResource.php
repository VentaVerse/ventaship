<?php

namespace App\Http\Resources;

use App\Models\ShipmentEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One timeline entry. The courier's raw payload is never included: it can carry personal data.
 *
 * @mixin ShipmentEvent
 */
class ShipmentEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(?Request $request = null): array
    {
        /** @var ShipmentEvent $e */
        $e = $this->resource;

        return [
            'courier_status' => $e->courier_status,
            'label' => $e->label,
            'phase' => $e->phase,
            'occurred_at' => $e->occurred_at?->copy()->setTimezone(config('app.timezone'))->toIso8601String(),
            'remarks' => $e->remarks,
            'source' => $e->source,
        ];
    }
}
