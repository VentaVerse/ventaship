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

    public function __construct(private readonly StatusRecorder $recorder, private readonly Canceller $canceller) {}

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
     * A courier `attention_reason` (LBC did not confirm the pickup) flags the shipment; its `notice` is stored.
     *
     * @param  array<string, mixed>  $meta  the courier's booking meta
     */
    public function booked(
        int $shipmentId,
        ?string $trackingNumber,
        ?string $status,
        array $meta,
        ?string $fee,
        ?string $notice = null,
        ?string $attentionReason = null,
    ): void {
        $creating = $status === 'creating';

        $cancel = DB::transaction(function () use ($shipmentId, $trackingNumber, $status, $meta, $fee, $creating, $notice, $attentionReason) {
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
                'notice' => $notice ?? $s->notice,
                'error_code' => null,
                'error_message' => null,
                'needs_attention' => $attentionReason !== null,
                'attention_reason' => $attentionReason !== null ? mb_substr($attentionReason, 0, 255) : null,
            ])->save();

            if ($creating) {
                return false;
            }

            // Status first, so the booked webhook's snapshot already carries courier_status and phase.
            if ($status !== null && $status !== '') {
                $this->recorder->record($s, $status, now(), 'system');
            }

            Outbox::record($s->clientApp, 'shipment.booked', ['event' => null], $s);

            return $s->cancel_state === 'requested';
        });

        if ($cancel) {
            CancelAtCourier::dispatch($shipmentId);
        }
    }

    /**
     * An SPX booking left `creating` now has its tracking number (the poller's resolvePendingCreation, or a webhook
     * matched by courier_ref). Only a shipment still `booking` + `creating` changes, so the poller and a webhook
     * resolving it at once book it once. `creating` is cleared so the cancel rules see a fresh booking; a requested
     * cancel is sent to the courier. False when the shipment was no longer waiting.
     *
     * @param  array<string, mixed>  $meta
     */
    public function creationResolved(int $shipmentId, string $trackingNumber, array $meta = []): bool
    {
        $cancel = DB::transaction(function () use ($shipmentId, $trackingNumber, $meta) {
            $s = $this->lock($shipmentId);
            if ($s === null || $s->state !== 'booking' || $s->courier_status !== 'creating') {
                return null;
            }

            $current = is_array($s->meta) ? $s->meta : [];
            unset($current['pending']);

            $s->forceFill([
                'state' => 'booked',
                'tracking_number' => $trackingNumber,
                'courier_status' => null,
                'meta' => array_replace($current, $meta),
                'booked_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ])->save();

            Outbox::record($s->clientApp, 'shipment.booked', ['event' => null], $s);

            return $s->cancel_state === 'requested';
        });

        if ($cancel === true) {
            CancelAtCourier::dispatch($shipmentId);
        }

        return $cancel !== null;
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

            if ($s->cancel_state === 'requested') {
                // Nothing exists at the courier, so the requested cancel is already done; the state stays failed.
                $this->canceller->settleNeverBooked($s);
            }

            return $s;
        });
    }

    /**
     * The outcome is not known: `unknown` plus attention, reference kept. Never retried; an operator resolves it.
     * With $staleBefore (the sweep), only a booking started before then and not waiting on SPX `creating`.
     * A tracking number and meta the courier did return (its answer could not be saved) are kept on the row.
     *
     * @param  array<string, mixed>  $meta
     */
    public function unknown(int $shipmentId, ?CarbonInterface $staleBefore = null, ?string $trackingNumber = null, array $meta = []): ?Shipment
    {
        return DB::transaction(function () use ($shipmentId, $staleBefore, $trackingNumber, $meta) {
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
                'tracking_number' => $trackingNumber ?? $s->tracking_number,
                'meta' => $meta === [] ? $s->meta : array_replace(is_array($s->meta) ? $s->meta : [], $meta),
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

            if ($s->state === 'failed' && $s->cancel_state === 'requested') {
                $this->canceller->settleNeverBooked($s); // the operator found no booking: the requested cancel is done
            }

            return $s;
        });
    }

    private function lock(int $shipmentId): ?Shipment
    {
        return Shipment::query()->whereKey($shipmentId)->lockForUpdate()->first();
    }
}
