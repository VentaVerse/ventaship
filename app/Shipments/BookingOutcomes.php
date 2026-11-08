<?php

namespace App\Shipments;

use App\Models\Shipment;
use App\Shipments\Jobs\CancelAtCourier;
use App\Webhooks\Outbound\Outbox;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Every booking state change: the job's start and outcomes, the sweep and the operator's resolution.
 * Each change is one transaction with the shipment row locked, re-checks the state it expects, and writes its
 * outbox event in the same transaction. A change whose expected state is gone does nothing and answers null.
 */
final class BookingOutcomes
{
    public const UNKNOWN_REASON = 'Booking outcome unknown — check the courier portal before retrying';

    private const MESSAGE_MAX = 1000;

    public function __construct(private readonly StatusRecorder $recorder) {}

    /** queued → booking, committed before the courier is called. Null when the shipment is not (or no longer) queued. */
    public function start(int $shipmentId): ?Shipment
    {
        return DB::transaction(function () use ($shipmentId) {
            $s = $this->lock($shipmentId);
            if ($s === null || $s->state !== 'queued' || $s->cancel_state === 'requested') {
                return null;
            }

            $s->forceFill(['state' => 'booking', 'booking_started_at' => now()])->save();

            return $s;
        });
    }

    /**
     * The courier booked it. SPX may answer `creating` with no tracking number yet: the shipment stays `booking`
     * (no attention) and the poller finishes it. A requested cancel is sent to the courier once booked.
     *
     * @param  array<string, mixed>  $meta  the courier's booking meta
     */
    public function booked(int $shipmentId, ?string $trackingNumber, ?string $status, array $meta, ?string $fee): void
    {
        $creating = $status === 'creating';

        $cancel = DB::transaction(function () use ($shipmentId, $trackingNumber, $status, $meta, $fee, $creating) {
            $s = $this->lock($shipmentId);
            if ($s === null) {
                return false;
            }

            if (! in_array($s->state, ['booking', 'unknown'], true)) {
                // Resolved by someone else while the courier call ran: keep the courier's answer, flag it.
                Log::critical('Courier booked a shipment that was already resolved', [
                    'shipment' => $s->public_id, 'state' => $s->state, 'tracking_number' => $trackingNumber,
                ]);
                $s->forceFill([
                    'needs_attention' => true,
                    'attention_reason' => mb_substr("The courier booked this shipment after it was marked {$s->state}"
                        .($trackingNumber ? " (tracking {$trackingNumber})" : '').' — check the courier portal', 0, 255),
                ])->save();

                return false;
            }

            $s->forceFill([
                'state' => $creating ? 'booking' : 'booked',
                'tracking_number' => $trackingNumber ?: $s->tracking_number,
                'courier_status' => $creating ? 'creating' : $s->courier_status,
                'meta' => array_replace(is_array($s->meta) ? $s->meta : [], $meta),
                'fee_amount' => $fee ?? $s->fee_amount,
                'fee_currency' => $fee !== null ? 'PHP' : $s->fee_currency,
                'booked_at' => $creating ? null : now(),
                'error_code' => null,
                'error_message' => null,
                'needs_attention' => false,
                'attention_reason' => null,
            ])->save();

            if ($creating) {
                return false;
            }

            Outbox::record($s->clientApp, 'shipment.booked', ['event' => null], $s);

            if ($status !== null && $status !== '') {
                $this->recorder->record($s, $status, now(), 'system');
            }

            return $s->cancel_state === 'requested';
        });

        if ($cancel) {
            CancelAtCourier::dispatch($shipmentId);
        }
    }

    /**
     * Nothing was booked: `failed`, and the client reference is free again.
     *
     * @param  list<string>  $from  the states this refusal may replace
     */
    public function failed(int $shipmentId, string $code, string $message, array $from = ['booking']): ?Shipment
    {
        return DB::transaction(function () use ($shipmentId, $code, $message, $from) {
            $s = $this->lock($shipmentId);
            if ($s === null || ! in_array($s->state, $from, true)) {
                return null;
            }

            $s->forceFill([
                'state' => 'failed',
                'active_reference' => null,
                'error_code' => $code,
                'error_message' => mb_substr($message, 0, self::MESSAGE_MAX),
                'needs_attention' => false,
                'attention_reason' => null,
            ])->save();

            Outbox::record($s->clientApp, 'shipment.booking_failed', ['event' => null], $s);

            return $s;
        });
    }

    /**
     * The outcome is not known: `unknown` plus attention, reference kept. Never retried; an operator resolves it.
     * With $staleBefore (the sweep), only a booking started before then and not waiting on SPX `creating`.
     */
    public function unknown(int $shipmentId, ?CarbonInterface $staleBefore = null): ?Shipment
    {
        return DB::transaction(function () use ($shipmentId, $staleBefore) {
            $s = $this->lock($shipmentId);
            if ($s === null || $s->state !== 'booking') {
                return null;
            }

            if ($staleBefore !== null && ($s->courier_status === 'creating'
                || ($s->booking_started_at !== null && $s->booking_started_at->greaterThanOrEqualTo($staleBefore)))) {
                return null;
            }

            $s->forceFill([
                'state' => 'unknown',
                'needs_attention' => true,
                'attention_reason' => self::UNKNOWN_REASON,
            ])->save();

            Outbox::record($s->clientApp, 'shipment.unknown', ['event' => null], $s);

            return $s;
        });
    }

    /** Operator: the courier portal shows no booking. */
    public function resolveNotBooked(int $shipmentId): ?Shipment
    {
        return $this->resolve($shipmentId, [
            'state' => 'failed',
            'active_reference' => null,
            'error_code' => 'not_booked',
            'error_message' => 'The courier did not book this shipment.',
        ]);
    }

    /** Operator: the courier portal shows this tracking number. */
    public function resolveBooked(int $shipmentId, string $trackingNumber): ?Shipment
    {
        $s = $this->resolve($shipmentId, [
            'state' => 'booked',
            'tracking_number' => $trackingNumber,
            'booked_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ]);

        if ($s !== null && $s->cancel_state === 'requested') {
            CancelAtCourier::dispatch($s->id);
        }

        return $s;
    }

    /** @param  array<string, mixed>  $changes */
    private function resolve(int $shipmentId, array $changes): ?Shipment
    {
        return DB::transaction(function () use ($shipmentId, $changes) {
            $s = $this->lock($shipmentId);
            if ($s === null || $s->state !== 'unknown') {
                return null;
            }

            $s->forceFill($changes + ['needs_attention' => false, 'attention_reason' => null])->save();

            Outbox::record($s->clientApp, 'shipment.resolved', ['event' => null], $s);

            return $s;
        });
    }

    private function lock(int $shipmentId): ?Shipment
    {
        return Shipment::query()->whereKey($shipmentId)->lockForUpdate()->first();
    }
}
