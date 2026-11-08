<?php

namespace App\Shipments;

use App\Couriers\CourierRegistry;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Webhooks\Outbound\Outbox;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one place a courier status becomes shipment state: webhook, poll and refresh all land here. One transaction,
 * shipment row locked, so concurrent reports of the same status cannot double-write and sequences stay gapless.
 */
class StatusRecorder
{
    private const RAW_LIMIT = 8192;

    public function __construct(private readonly PhaseResolver $phases) {}

    public function record(
        Shipment $s,
        string $courierStatus,
        CarbonInterface $occurredAt,
        string $source,
        ?string $remarks = null,
        array $raw = [],
        ?int $receiptId = null,
    ): ?ShipmentEvent {
        // Stored in the app zone like every other datetime column, whatever zone the courier's timestamp arrived in.
        $occurredAt = $occurredAt->copy()->setTimezone(config('app.timezone'))->startOfSecond();

        return DB::transaction(function () use ($s, $courierStatus, $occurredAt, $source, $remarks, $raw, $receiptId) {
            $shipment = Shipment::query()->whereKey($s->getKey())->lockForUpdate()->firstOrFail();

            if ($this->eventExists($shipment, $courierStatus, $occurredAt)) {
                return null;
            }

            $courier = (string) $shipment->courier;
            $phase = $this->phases->phase($courier, $courierStatus);

            try {
                // Savepoint: a unique-key loss must not poison the caller's transaction.
                $event = DB::transaction(fn () => ShipmentEvent::create([
                    'shipment_id' => $shipment->id,
                    'source' => $source,
                    'courier_status' => $courierStatus,
                    'label' => $this->phases->label($courier, $courierStatus),
                    'phase' => $phase,
                    'occurred_at' => $occurredAt,
                    'remarks' => $remarks,
                    'raw' => $this->trimRaw($raw),
                    'receipt_id' => $receiptId,
                ]));
            } catch (UniqueConstraintViolationException) {
                return null;
            }

            $paymentBefore = $this->currentPaymentState($shipment);
            $paymentAfter = $paymentBefore;

            $latest = $shipment->last_event_at === null || $occurredAt->greaterThanOrEqualTo($shipment->last_event_at);

            if ($latest) {
                $shipment->courier_status = $courierStatus;
                $shipment->phase = $phase;
                $shipment->last_event_at = $occurredAt;
            }

            // Money and finality are facts about the parcel, not about which report was newest: they apply from any new event.
            $paymentAfter = PaymentState::after($shipment, $phase);

            if ($shipment->terminal_at === null && $this->isTerminal($courier, $courierStatus)) {
                $shipment->terminal_at = $occurredAt;
            }

            $shipment->payment_state = $paymentAfter;
            $shipment->save();

            $app = $shipment->clientApp;
            $eventData = $event->only(['courier_status', 'label', 'phase', 'occurred_at', 'remarks']);
            $eventData['occurred_at'] = $occurredAt->toIso8601String();

            Outbox::record($app, 'shipment.status_changed', [
                'shipment' => $this->snapshot($shipment),
                'event' => $eventData,
            ], $shipment);

            if ($paymentAfter !== $paymentBefore) {
                Outbox::record($app, 'shipment.payment_state_changed', [
                    'shipment' => $this->snapshot($shipment),
                    'event' => null,
                ], $shipment);
            }

            $s->setRawAttributes($shipment->getAttributes(), true);

            return $event;
        });
    }

    protected function eventExists(Shipment $shipment, string $courierStatus, CarbonInterface $occurredAt): bool
    {
        return ShipmentEvent::query()
            ->where('shipment_id', $shipment->id)
            ->where('courier_status', $courierStatus)
            ->where('occurred_at', $occurredAt)
            ->exists();
    }

    private function currentPaymentState(Shipment $s): string
    {
        if ($s->payment_method !== 'cod') {
            return 'prepaid';
        }

        return in_array($s->payment_state, [null, 'prepaid'], true) ? 'cod_unpaid' : $s->payment_state;
    }

    private function isTerminal(string $courier, string $status): bool
    {
        try {
            return in_array($status, CourierRegistry::describe($courier)->terminalStatuses(), true);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** @return array<string, mixed> Task 9 swaps this for ShipmentResource. */
    private function snapshot(Shipment $s): array
    {
        return [
            'id' => $s->public_id,
            'state' => $s->state,
            'phase' => $s->phase,
            'courier_status' => $s->courier_status,
            'payment_state' => $s->payment_state,
            'tracking_number' => $s->tracking_number,
            'client_reference' => $s->client_reference,
        ];
    }

    /** @return array<string, mixed> */
    private function trimRaw(array $raw): array
    {
        if ($raw === []) {
            return [];
        }

        $json = json_encode($raw, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return ['truncated' => true, 'excerpt' => ''];
        }

        if (strlen($json) <= self::RAW_LIMIT) {
            return $raw;
        }

        return ['truncated' => true, 'excerpt' => mb_strcut($json, 0, self::RAW_LIMIT, 'UTF-8')];
    }
}
