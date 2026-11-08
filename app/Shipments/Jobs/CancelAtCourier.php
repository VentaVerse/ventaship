<?php

namespace App\Shipments\Jobs;

use App\Couriers\Contracts\CancelsLocallyOnly;
use App\Couriers\CourierRegistry;
use App\Couriers\LiveBookingBlocked;
use App\Models\Shipment;
use App\Shipments\Canceller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cancels one booked shipment at its courier. Dispatched by the Canceller (a booked shipment) and by BookingOutcomes
 * (a cancel requested while the booking was in flight).
 *
 * NEVER retried: a cancel whose outcome is in doubt (an exception, a timeout, a worker that died) ends as
 * cancel_state `failed` with attention, for an operator to check at the courier portal.
 */
class CancelAtCourier implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    private const LOCK_SECONDS = 300;

    public const LIVE_LOCKED_REASON = 'Live cancels are locked for this account — nothing was sent to the courier';

    public function __construct(public int $shipmentId)
    {
        $this->onConnection('couriers');
        $this->onQueue('couriers');
    }

    public function handle(Canceller $canceller): void
    {
        // Not the booking job's lock: that one is still held while BookShipment dispatches this job.
        $lock = Cache::lock("shipment-cancel:{$this->shipmentId}", self::LOCK_SECONDS);
        if (! $lock->get()) {
            Log::warning('Cancel skipped: another worker holds the shipment lock', ['shipment_id' => $this->shipmentId]);

            return;
        }

        try {
            $this->cancel($canceller);
        } finally {
            $lock->release();
        }
    }

    /** A timeout or a worker killed mid-call: the courier may have cancelled. */
    public function failed(?Throwable $e): void
    {
        Log::error('Cancel job failed; outcome unknown', [
            'shipment_id' => $this->shipmentId,
            'exception' => $e === null ? null : $e::class,
        ]);

        app(Canceller::class)->callFailed($this->shipmentId);
    }

    private function cancel(Canceller $canceller): void
    {
        $s = Shipment::query()->with('courierAccount')->find($this->shipmentId);
        if ($s === null || $s->state !== 'booked' || $s->cancel_state !== 'requested') {
            return; // already settled, or no longer a booked shipment: nothing to do
        }

        // The courier already reports it cancelled: nothing to ask, the cancel is done.
        if (Canceller::cancelledAtCourier($s)) {
            $canceller->cancelled($s->id);

            return;
        }

        $account = $s->courierAccount;
        if ($account === null || $account->trashed() || $s->tracking_number === null) {
            $canceller->refused($s->id, 'This shipment cannot be cancelled from here — cancel it at the courier portal');

            return;
        }

        try {
            $courier = CourierRegistry::for($account);

            if ($courier instanceof CancelsLocallyOnly) {
                $canceller->unsupported($s->id, $courier->localCancelNotice());

                return;
            }

            if (! Canceller::cancelableAtCourier($s, $courier)) {
                $canceller->refused($s->id, "This shipment can no longer be cancelled (courier status: {$s->courier_status})");

                return;
            }

            // No transaction is held here. The context is scoped so it cannot leak into later jobs or HTTP calls.
            $result = Context::scope(
                fn () => $courier->cancelShipment($s->tracking_number),
                ['account_id' => $s->courier_account_id, 'shipment_id' => $s->id, 'operation' => 'cancel_shipment'],
            );
        } catch (LiveBookingBlocked $e) {
            // A live lock is off: the request was never sent, so the outcome is known. Refused, not unknown.
            Log::warning('Cancel blocked by a live lock', ['shipment' => $s->public_id, 'reason' => $e->getMessage()]);
            $canceller->refused($s->id, self::LIVE_LOCKED_REASON);

            return;
        } catch (Throwable $e) {
            Log::error('Cancel outcome unknown', ['shipment' => $s->public_id, 'exception' => $e::class]);
            $canceller->callFailed($s->id);

            return;
        }

        if (is_array($result) && ($result['success'] ?? false) === true) {
            try {
                $canceller->cancelled($s->id);
            } catch (Throwable $e) {
                // Cancelled at the courier but not written here.
                Log::critical('Courier cancelled the shipment but the result could not be saved', [
                    'shipment' => $s->public_id, 'exception' => $e::class,
                ]);
                $canceller->callFailed($s->id);
            }

            return;
        }

        $error = is_array($result) && is_string($result['error'] ?? null) && trim($result['error']) !== ''
            ? trim($result['error']) : 'The courier refused to cancel this shipment.';
        $canceller->refused($s->id, $error);
    }
}
