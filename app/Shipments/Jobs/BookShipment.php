<?php

namespace App\Shipments\Jobs;

use App\Api\ApiError;
use App\Couriers\AccountResolver;
use App\Couriers\Contracts\Courier;
use App\Couriers\Contracts\ReportsShippingCost;
use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Couriers\LiveBookingBlocked;
use App\Models\Shipment;
use App\Shipments\BookingOutcomes;
use App\Shipments\CourierOptions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Books one queued shipment with its courier (base plan §2 "Booking safety"). Dispatched by ShipmentCreator after
 * the shipment's transaction commits.
 *
 * NEVER retried automatically: a second createShipment can book the parcel twice, and LBC cannot cancel. So
 * tries = 1, maxExceptions = 1, nothing is rethrown after the courier is called, and anything that leaves the
 * outcome in doubt (an exception, a timeout, a worker that died) ends in `unknown` for an operator to resolve.
 */
class BookShipment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    private const LOCK_SECONDS = 600;

    public function __construct(public int $shipmentId)
    {
        $this->onConnection('couriers');
        $this->onQueue('couriers');
    }

    public function handle(BookingOutcomes $outcomes): void
    {
        $lock = Cache::lock("shipment:{$this->shipmentId}", self::LOCK_SECONDS);
        if (! $lock->get()) {
            Log::warning('Booking skipped: another worker holds the shipment lock', ['shipment_id' => $this->shipmentId]);

            return;
        }

        try {
            $this->book($outcomes);
        } finally {
            $lock->release();
        }
    }

    /**
     * Called by the worker when the job fails anyway: a timeout (failOnTimeout), a worker killed mid-call and the
     * job found again past its single try, or an error writing the outcome. The booking may have happened.
     */
    public function failed(?Throwable $e): void
    {
        Log::error('Booking job failed; outcome unknown', [
            'shipment_id' => $this->shipmentId,
            'exception' => $e === null ? null : $e::class,
        ]);

        app(BookingOutcomes::class)->unknown($this->shipmentId);
    }

    private function book(BookingOutcomes $outcomes): void
    {
        $s = $outcomes->start($this->shipmentId);
        if ($s === null) {
            return; // cancelled, already booking/booked, or gone: nothing to do
        }

        try {
            [$courier, $order, $opts] = $this->prepare($s);
        } catch (ApiError $e) {
            // The account cannot book (disabled, deleted, misconfigured). Nothing was sent.
            $outcomes->failed($s->id, $e->errorCode, $e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->logException('Booking could not be prepared', $s, $e);
            $outcomes->unknown($s->id);

            return;
        }

        try {
            // No transaction is held here. The context is scoped so it cannot leak into later jobs or HTTP calls.
            $result = Context::scope(
                fn () => $courier->createShipment($order, $opts),
                ['account_id' => $s->courier_account_id, 'shipment_id' => $s->id, 'operation' => 'create_shipment'],
            );
        } catch (LiveBookingBlocked $e) {
            $outcomes->failed($s->id, 'live_booking_blocked', $e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->logException('Booking outcome unknown', $s, $e);
            $outcomes->unknown($s->id);

            return;
        }

        $this->record($outcomes, $s, $courier, is_array($result) ? $result : []);
    }

    /** @return array{0: Courier, 1: CourierOrder, 2: array<string, mixed>} */
    private function prepare(Shipment $s): array
    {
        $account = $s->courierAccount;
        if ($account === null || $account->trashed()) {
            throw new ApiError(422, 'account_misconfigured', 'This shipment\'s courier account no longer exists.');
        }

        AccountResolver::assertBookable($account);

        return [CourierRegistry::for($account), CourierOrder::fromApi($s), CourierOptions::for($s)];
    }

    /** @param  array<string, mixed>  $result  createShipment()'s answer */
    private function record(BookingOutcomes $outcomes, Shipment $s, Courier $courier, array $result): void
    {
        if (($result['success'] ?? false) !== true && ($result['outcome_unknown'] ?? false) === true) {
            // The request may have reached the courier: not a refusal, so the reference is kept.
            Log::error('Booking outcome unknown: the courier could not confirm', ['shipment' => $s->public_id]);
            $outcomes->unknown($s->id);

            return;
        }

        if (($result['success'] ?? false) !== true) {
            $error = is_string($result['error'] ?? null) && $result['error'] !== '' ? $result['error'] : 'The courier refused the booking.';
            $outcomes->failed($s->id, 'courier_refused', $error);

            return;
        }

        $status = is_string($result['status'] ?? null) ? $result['status'] : null;
        $tracking = is_string($result['tracking_number'] ?? null) && $result['tracking_number'] !== '' ? $result['tracking_number'] : null;
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];

        if ($tracking === null && $status !== 'creating') {
            // "Booked" with nothing to find it by: whether a parcel exists is not known.
            Log::error('Courier answered success without a tracking number', ['shipment' => $s->public_id, 'status' => $status]);
            $outcomes->unknown($s->id);

            return;
        }

        try {
            $outcomes->booked($s->id, $tracking, $status, $meta, $this->fee($courier, $result, $meta, $s),
                $this->text($result['notice'] ?? null), $this->text($result['attention_reason'] ?? null));
        } catch (Throwable $e) {
            // Booked at the courier but not written here: the tracking number must not be lost.
            Log::critical('Courier booked the shipment but the result could not be saved', [
                'shipment' => $s->public_id, 'tracking_number' => $tracking, 'exception' => $e::class,
            ]);
            $outcomes->unknown($s->id, trackingNumber: $tracking, meta: $meta);
        }
    }

    /** The fee the courier reported, as a two-decimal string; null when it said nothing. Never fatal. */
    private function fee(Courier $courier, array $result, array $meta, Shipment $s): ?string
    {
        try {
            $fee = $result['fee'] ?? null;
            if (! is_numeric($fee) && $courier instanceof ReportsShippingCost) {
                $fee = $courier->actualShippingCost($meta);
            }

            return is_numeric($fee) && (float) $fee >= 0 ? sprintf('%.2F', (float) $fee) : null;
        } catch (Throwable $e) {
            $this->logException('Booking fee could not be read', $s, $e);

            return null;
        }
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** Class and shipment only: courier exception messages can carry URLs, credentials or personal data. */
    private function logException(string $message, Shipment $s, Throwable $e): void
    {
        Log::error($message, ['shipment' => $s->public_id, 'exception' => $e::class]);
    }
}
