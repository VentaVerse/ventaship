<?php

namespace App\Shipments;

use App\Couriers\Contracts\CancelsLocallyOnly;
use App\Couriers\Contracts\Courier;
use App\Couriers\CourierRegistry;
use App\Models\Shipment;
use App\Shipments\Jobs\CancelAtCourier;
use App\Webhooks\Outbound\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every cancel state change (base plan §2 "Cancel rules"). Each is one transaction with the shipment row locked,
 * re-checks the state it expects and writes its outbox event in the same transaction. The courier is never called
 * here: a booked shipment is marked `requested` and CancelAtCourier makes the call outside any transaction.
 */
final class Canceller
{
    public const OUTCOME_UNKNOWN_REASON = 'Cancel outcome unknown — check the courier portal';

    private const REASON_MAX = 255;

    /**
     * The client asks to cancel. Idempotent by state: a shipment that is already cancelled, or already has a cancel
     * in progress or settled (`cancel_state` set), is returned unchanged with no second event. A `failed` shipment
     * has nothing to cancel and is returned unchanged too.
     */
    public function request(Shipment $s): Shipment
    {
        $dispatch = false;

        $row = DB::transaction(function () use ($s, &$dispatch) {
            $row = $this->lock($s->getKey());
            if ($row === null) {
                return $s;
            }

            if ($row->cancel_state !== null) {
                return $row;
            }

            switch ($row->state) {
                case 'draft':
                case 'queued':
                    $this->finish($row);
                    break;

                case 'booking':
                    $row->forceFill(['cancel_state' => 'requested'])->save();
                    break;

                case 'unknown':
                    $row->forceFill([
                        'cancel_state' => 'requested',
                        'needs_attention' => true,
                        'attention_reason' => 'Cancel requested while the booking outcome is unknown — resolve the booking first',
                    ])->save();
                    break;

                case 'booked':
                    $dispatch = $this->requestAtCourier($row);
                    break;
            }

            return $row;
        });

        if ($dispatch) {
            CancelAtCourier::dispatch($row->id);
        }

        return $row->load('courierAccount');
    }

    /** The courier cancelled it. */
    public function cancelled(int $shipmentId): ?Shipment
    {
        return $this->whenRequested($shipmentId, function (Shipment $s) {
            $this->finish($s);
        });
    }

    /** The courier will not cancel it (or the status no longer allows it). */
    public function refused(int $shipmentId, string $reason): ?Shipment
    {
        return $this->whenRequested($shipmentId, fn (Shipment $s) => $this->cancelFailed($s, 'refused', $reason));
    }

    /** The courier has no cancel endpoint: the operator tells it some other way. */
    public function unsupported(int $shipmentId, string $notice): ?Shipment
    {
        return $this->whenRequested($shipmentId, fn (Shipment $s) => $this->cancelFailed($s, 'unsupported', $notice));
    }

    /** The courier call threw or timed out: it may or may not have cancelled. Never retried. */
    public function callFailed(int $shipmentId): ?Shipment
    {
        return $this->whenRequested($shipmentId, fn (Shipment $s) => $this->cancelFailed($s, 'failed', self::OUTCOME_UNKNOWN_REASON));
    }

    /** Whether the booked shipment's courier status still allows a cancel. A null status counts as cancelable. */
    public static function cancelableAtCourier(Shipment $s, Courier $courier): bool
    {
        if ($s->courier_status === null || $s->courier_status === '') {
            return true;
        }

        return in_array($s->courier_status, $courier->cancelableStatuses(), true);
    }

    /**
     * Inside the caller's transaction, shipment row locked: a booking that was refused while a cancel was requested.
     * Nothing exists at the courier, so the state stays as the caller set it and the cancel is simply done.
     */
    public function settleNeverBooked(Shipment $s): void
    {
        $this->finish($s, keepState: true);
    }

    /** @return bool true when CancelAtCourier must be dispatched */
    private function requestAtCourier(Shipment $s): bool
    {
        try {
            $account = $s->courierAccount;
            $courier = $account === null ? null : CourierRegistry::for($account);
            if ($courier === null) {
                $this->cancelFailed($s, 'refused', 'This shipment\'s courier account no longer exists — cancel it at the courier portal');

                return false;
            }

            if ($courier instanceof CancelsLocallyOnly) {
                $this->cancelFailed($s, 'unsupported', $courier->localCancelNotice());

                return false;
            }

            if (! self::cancelableAtCourier($s, $courier)) {
                $this->cancelFailed($s, 'refused', "This shipment can no longer be cancelled (courier status: {$s->courier_status})");

                return false;
            }
        } catch (Throwable $e) {
            Log::error('Cancel could not be prepared', ['shipment' => $s->public_id, 'exception' => $e::class]);
            $this->cancelFailed($s, 'refused', 'The courier could not be reached to cancel — check the courier portal');

            return false;
        }

        $s->forceFill(['cancel_state' => 'requested'])->save();

        return true;
    }

    /** state → cancelled, reference freed, COD voided; the one place a cancel succeeds. */
    private function finish(Shipment $s, bool $keepState = false): void
    {
        $changes = [
            'cancel_state' => 'cancelled',
            'cancelled_at' => now(),
            'active_reference' => null,
            'payment_state' => PaymentState::after($s, 'cancelled'),
            'needs_attention' => false,
            'attention_reason' => null,
        ];

        if (! $keepState) {
            $changes += ['state' => 'cancelled', 'phase' => 'cancelled'];
        }

        $s->forceFill($changes)->save();

        Outbox::record($s->clientApp, 'shipment.cancelled', ['event' => null], $s);
    }

    private function cancelFailed(Shipment $s, string $cancelState, string $reason): void
    {
        $s->forceFill([
            'cancel_state' => $cancelState,
            'needs_attention' => true,
            'attention_reason' => mb_substr($reason, 0, self::REASON_MAX),
        ])->save();

        Outbox::record($s->clientApp, 'shipment.cancel_failed', ['event' => null], $s);
    }

    /** Runs $change on a booked shipment whose cancel is requested; null when it is no longer. */
    private function whenRequested(int $shipmentId, callable $change): ?Shipment
    {
        return DB::transaction(function () use ($shipmentId, $change) {
            $s = $this->lock($shipmentId);
            if ($s === null || $s->state !== 'booked' || $s->cancel_state !== 'requested') {
                return null;
            }

            $change($s);

            return $s;
        });
    }

    private function lock(int|string $id): ?Shipment
    {
        return Shipment::query()->whereKey($id)->lockForUpdate()->first();
    }
}
