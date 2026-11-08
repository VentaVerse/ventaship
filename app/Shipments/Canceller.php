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

    public const UNKNOWN_BOOKING_REASON = 'Cancel requested while the booking outcome is unknown — resolve the booking first';

    public const NOT_PREPARED_REASON = 'Cancel could not be prepared — check the account settings';

    /** Set by the sweep on a booked shipment whose requested cancel never reached the courier. */
    public const STUCK_REASON = 'Cancel request stuck — check and retry';

    private const REASON_MAX = 255;

    /**
     * The client asks to cancel. Idempotent by state: a shipment that is already cancelled, or already has a cancel
     * in progress or settled (`cancel_state` set), is returned unchanged with no second event. A `failed` shipment
     * has nothing to cancel and is returned unchanged too. With $now (dashboard requests) the courier is called in this
     * request and the shipment comes back with the cancel's outcome.
     */
    public function request(Shipment $s, bool $now = false): Shipment
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
                    $row->forceFill(['cancel_state' => 'requested', 'cancel_requested_at' => now()])->save();
                    break;

                case 'unknown':
                    $row->forceFill([
                        'cancel_state' => 'requested', 'cancel_requested_at' => now(),
                        'needs_attention' => true,
                        'attention_reason' => self::UNKNOWN_BOOKING_REASON,
                    ])->save();
                    break;

                case 'booked':
                    $dispatch = $this->requestAtCourier($row);
                    break;
            }

            return $row;
        });

        if ($dispatch && $now) {
            // Never inside a transaction: deferred until a caller's commits. The sync queue calls failed()
            // (cancel_state failed + attention) before rethrowing, as a worker would.
            DB::afterCommit(function () use ($row) {
                try {
                    CancelAtCourier::dispatchSync($row->id);
                } catch (Throwable $e) {
                    Log::error('Synchronous cancel threw', ['shipment' => $row->public_id, 'exception' => $e::class]);
                }
            });

            return Shipment::query()->with('courierAccount')->findOrFail($row->id);
        }

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
     * Whether the shipment's last courier status already means the courier cancelled it (phase `cancelled`). Asking
     * that courier to cancel again would only be refused, so the cancel is settled instead.
     */
    public static function cancelledAtCourier(Shipment $s): bool
    {
        if ($s->courier_status === null || trim($s->courier_status) === '') {
            return false;
        }

        return app(PhaseResolver::class)->phase((string) $s->courier, $s->courier_status) === 'cancelled';
    }

    /**
     * Inside the caller's transaction, shipment row locked: the courier reported a booked shipment cancelled, whether or
     * not anyone asked it to. A cancellation at the courier is final, so the shipment is settled whatever cancel_state
     * it had (none, requested, refused, failed). Anything not `booked` is left alone, so it never settles twice.
     */
    public function settleCourierCancelled(Shipment $s): void
    {
        if ($s->state !== 'booked') {
            return;
        }

        $this->finish($s);
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
            // The courier already reports it cancelled: there is nothing left to ask it, the cancel is done.
            if (self::cancelledAtCourier($s)) {
                $this->finish($s);

                return false;
            }

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
            $this->cancelFailed($s, 'failed', self::NOT_PREPARED_REASON);

            return false;
        }

        $s->forceFill(['cancel_state' => 'requested', 'cancel_requested_at' => now()])->save();

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
        ];

        // Only attention the cancel flow itself raised is cleared; an unrelated reason stays for the operator. A refused,
        // unsupported or failed cancel is always the last thing to have set the reason on a booked shipment.
        $raisedByCancel = in_array($s->attention_reason, [self::OUTCOME_UNKNOWN_REASON, self::UNKNOWN_BOOKING_REASON, self::NOT_PREPARED_REASON, self::STUCK_REASON], true)
            || ($s->needs_attention && in_array($s->cancel_state, ['refused', 'unsupported', 'failed'], true));
        if ($raisedByCancel) {
            $changes += ['needs_attention' => false, 'attention_reason' => null];
        }

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
