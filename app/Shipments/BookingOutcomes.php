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
    public const UNKNOWN_REASON = 'Booking outcome unknown. Check the courier portal before retrying';

    /** Set by the sweep on an SPX booking still `creating` after 6 hours; cleared when SPX confirms it. */
    public const CREATING_STALE_REASON = 'SPX has not confirmed the booking after 6 hours. Check the SPX portal';

    /** A tracking number an operator may record: letters, digits, - and _, 3 to 64 characters, not starting with - or _. */
    public const TRACKING_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{2,63}$/';

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
     * $pickupSlot is the slot the courier booked when it chose or moved it (SPX), stored as options.pickup_slot.
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
        ?string $pickupSlot = null,
    ): void {
        $creating = $status === 'creating';

        $cancel = DB::transaction(function () use ($shipmentId, $trackingNumber, $status, $meta, $fee, $creating, $notice, $attentionReason, $pickupSlot) {
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
                        .($trackingNumber ? " (tracking {$trackingNumber})" : '').'. Check the courier portal', 0, 255),
                ])->save();

                return false;
            }

            if ($pickupSlot !== null) {
                $s->forceFill(['options' => array_replace(is_array($s->options) ? $s->options : [], ['pickup_slot' => $pickupSlot])]);
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
     * $meta and $fee are the booked data the immediate path stores (the courier's booking answer and its fee), so a
     * booking that settled late reads the same as one that settled at once; a null fee keeps what is stored.
     *
     * @param  array<string, mixed>  $meta
     */
    public function creationResolved(int $shipmentId, string $trackingNumber, array $meta = [], ?string $fee = null): bool
    {
        $cancel = DB::transaction(function () use ($shipmentId, $trackingNumber, $meta, $fee) {
            $s = $this->lock($shipmentId);
            if ($s === null || $s->state !== 'booking' || $s->courier_status !== 'creating') {
                return null;
            }

            $current = is_array($s->meta) ? $s->meta : [];
            unset($current['pending']);

            if ($s->attention_reason === self::CREATING_STALE_REASON) {
                $s->forceFill(['needs_attention' => false, 'attention_reason' => null]);
            }

            $s->forceFill([
                'state' => 'booked',
                'tracking_number' => $trackingNumber,
                'courier_status' => null,
                'meta' => array_replace($current, $meta),
                'fee_amount' => $fee ?? $s->fee_amount,
                'fee_currency' => $fee !== null ? 'PHP' : $s->fee_currency,
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
     * Nothing was booked: `failed`, and the client reference is free again. $message is what the seller reads;
     * $courierError, the courier's own words, goes to meta.courier_error for support (cleared when there is none).
     *
     * @param  list<string>  $from  the states this refusal may replace
     */
    public function failed(int $shipmentId, string $code, string $message, array $from = ['booking'], ?string $courierError = null): ?Shipment
    {
        return DB::transaction(function () use ($shipmentId, $code, $message, $from, $courierError) {
            $s = $this->lock($shipmentId);
            if ($s === null || ! in_array($s->state, $from, true)) {
                return null;
            }

            $meta = is_array($s->meta) ? $s->meta : [];
            unset($meta['courier_error']);
            if ($courierError !== null && trim($courierError) !== '') {
                $meta['courier_error'] = mb_substr(trim($courierError), 0, self::MESSAGE_MAX);
            }

            $s->forceFill([
                'state' => 'failed',
                'active_reference' => null,
                'error_code' => $code,
                'error_message' => mb_substr($message, 0, self::MESSAGE_MAX),
                'needs_attention' => false,
                'attention_reason' => null,
                'meta' => $meta === [] ? null : $meta,
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
     * $reason replaces the generic attention reason when the courier said why it cannot tell (SPX 13103).
     *
     * @param  array<string, mixed>  $meta
     */
    public function unknown(int $shipmentId, ?CarbonInterface $staleBefore = null, ?string $trackingNumber = null, array $meta = [], ?string $reason = null): ?Shipment
    {
        return DB::transaction(function () use ($shipmentId, $staleBefore, $trackingNumber, $meta, $reason) {
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
                'attention_reason' => mb_substr($reason ?? self::UNKNOWN_REASON, 0, 255),
            ])->save();

            Outbox::record($s->clientApp, 'shipment.unknown', ['event' => null], $s);

            return $s;
        });
    }

    /**
     * The courier reports a parcel for a shipment marked `failed` or `unknown` here (SPX: a webhook carrying its
     * order_id after a timeout, or a refusal that was not one). The parcel exists, so the shipment is booked with
     * its tracking number and `shipment.booked` is sent, as from a normal booking.
     *
     * A failed shipment takes its client reference back when no live shipment holds it; when one does, both stay
     * and the shipment is flagged, because two parcels may now exist. A failed shipment whose cancel was already
     * settled stays failed, keeps the tracking number and is flagged: the parcel must be cancelled at the courier.
     * A cancel requested while `unknown` is sent to the courier once booked. Null when nothing changed.
     *
     * @param  array<string, mixed>  $meta
     */
    public function recovered(int $shipmentId, string $trackingNumber, array $meta = []): ?Shipment
    {
        $cancel = false;
        $s = DB::transaction(function () use ($shipmentId, $trackingNumber, $meta, &$cancel) {
            $s = $this->lock($shipmentId);
            if ($s === null || ! in_array($s->state, ['failed', 'unknown'], true)) {
                return null;
            }

            $current = is_array($s->meta) ? $s->meta : [];
            if ($s->state === 'failed' && $s->cancel_state !== null) {
                Log::critical('Courier reports a parcel for a cancelled failed shipment', ['shipment' => $s->public_id, 'tracking_number' => $trackingNumber]);
                $s->forceFill([
                    'tracking_number' => $s->tracking_number ?: $trackingNumber,
                    'meta' => array_replace($current, $meta),
                    'needs_attention' => true,
                    'attention_reason' => mb_substr("The courier booked this shipment after it was cancelled here (tracking {$trackingNumber}). Cancel it at the courier portal", 0, 255),
                ])->save();

                return null;
            }

            $attention = null;
            if ($s->state === 'failed') {
                $holder = Shipment::query()
                    ->where('client_app_id', $s->client_app_id)
                    ->where('active_reference', $s->client_reference)
                    ->whereKeyNot($s->getKey())
                    ->lockForUpdate()
                    ->first();
                if ($holder !== null && in_array($holder->state, ShipmentCreator::RELEASED_STATES, true)) {
                    $holder->forceFill(['active_reference' => null])->save();
                    $holder = null;
                }
                if ($holder === null) {
                    $s->forceFill(['active_reference' => $s->client_reference]);
                } else {
                    $name = $holder->courier_ref ?: $holder->public_id;
                    $attention = "The courier booked this shipment after it failed, and {$name} now uses its reference. Cancel one of them";
                }
            }

            $s->forceFill([
                'state' => 'booked',
                'tracking_number' => $trackingNumber,
                'courier_status' => $s->courier_status === 'creating' ? null : $s->courier_status,
                'meta' => array_replace($current, $meta),
                'booked_at' => now(),
                'error_code' => null,
                'error_message' => null,
                'needs_attention' => $attention !== null,
                'attention_reason' => $attention === null ? null : mb_substr($attention, 0, 255),
            ])->save();

            Outbox::record($s->clientApp, 'shipment.booked', ['event' => null], $s);
            $cancel = $s->cancel_state === 'requested';

            return $s;
        });

        if ($cancel) {
            CancelAtCourier::dispatch($shipmentId);
        }

        return $s;
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
