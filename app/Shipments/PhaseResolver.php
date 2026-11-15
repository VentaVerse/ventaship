<?php

namespace App\Shipments;

use App\Couriers\CourierRegistry;
use App\Couriers\CourierStatusLabel;
use App\Models\ShipmentStatus;
use App\Models\StatusMapping;
use InvalidArgumentException;

/**
 * Raw courier status to a shipment status (shipments.phase): the owner's mapping (status_mappings), else the courier's
 * own statusPhase(), else in_transit for a non-empty status nobody knows. A status that is disabled or deleted leaves
 * the courier status unmapped (null). Whether the courier cancelled or finished the parcel never follows the mapping:
 * that is courierPhase(), the courier's own answer (see StatusRecorder and Canceller).
 */
final class PhaseResolver
{
    public const SPINE = ['draft', 'booking', 'attention', 'to_handover', 'in_transit', 'delivered', 'failed', 'lost_damaged', 'returning', 'cancelled'];

    /** Stored as a mapping's phase: the owner chose no shipment status for this courier status. */
    public const UNMAPPED = 'unmapped';

    /** Ventaship's own steps before a courier answers; always valid, never in the statuses list. */
    private const INTERNAL = ['draft', 'booking'];

    public function phase(string $courier, ?string $status): ?string
    {
        $status = trim((string) $status);
        $key = $this->courierPhase($courier, $status);

        if ($status !== '') {
            $override = StatusMapping::query()
                ->where('courier', $courier)
                ->where('courier_status', $status)
                ->value('phase');

            if (is_string($override) && $override !== '') {
                $key = $override;
            }
        }

        return $this->usable($key) ? $key : null;
    }

    /** The courier's own bucket for a status, ignoring the owner's mapping. */
    public function courierPhase(string $courier, ?string $status): string
    {
        $status = trim((string) $status);

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

    /** A key a shipment may carry: Ventaship's internal steps, or an enabled shipment status. */
    public function usable(?string $key): bool
    {
        if ($key === null || $key === '' || $key === self::UNMAPPED) {
            return false;
        }

        // Read each time: the queue worker is long-lived and the owner can change statuses at any moment.
        return in_array($key, self::INTERNAL, true) || ShipmentStatus::query()->where('key', $key)->where('enabled', true)->exists();
    }
}
