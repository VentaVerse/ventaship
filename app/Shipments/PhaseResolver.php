<?php

namespace App\Shipments;

use App\Couriers\CourierRegistry;
use App\Couriers\CourierStatusLabel;
use App\Models\StatusMapping;
use InvalidArgumentException;

/**
 * Raw courier status to the shared phase spine: the install's override (status_mappings), then the courier's own
 * statusPhase(), then in_transit for a non-empty status nobody knows. Overrides only change the words and the bucket;
 * terminal and cancelable decisions stay with the courier (see StatusRecorder).
 */
final class PhaseResolver
{
    public const SPINE = ['draft', 'booking', 'attention', 'to_handover', 'in_transit', 'delivered', 'failed', 'lost_damaged', 'returning', 'cancelled'];

    public function phase(string $courier, ?string $status): string
    {
        $status = trim((string) $status);

        if ($status !== '') {
            $override = StatusMapping::query()
                ->where('courier', $courier)
                ->where('courier_status', $status)
                ->value('phase');

            if (is_string($override) && in_array($override, self::SPINE, true)) {
                return $override;
            }
        }

        try {
            $phase = CourierRegistry::describe($courier)->statusPhase($status === '' ? null : $status)['phase'] ?? null;
        } catch (InvalidArgumentException) {
            $phase = null;
        }

        if (is_string($phase) && in_array($phase, self::SPINE, true)) {
            return $phase;
        }

        return $status === '' ? 'attention' : 'in_transit';
    }

    public function label(string $courier, string $status): string
    {
        $override = StatusMapping::query()
            ->where('courier', $courier)
            ->where('courier_status', $status)
            ->value('label');

        return is_string($override) && $override !== '' ? $override : CourierStatusLabel::for($courier, $status);
    }
}
