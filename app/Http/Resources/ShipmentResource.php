<?php

namespace App\Http\Resources;

use App\Models\Shipment;
use App\Shipments\PhaseResolver;
use App\Shipments\PickupSlot;
use App\Shipments\TrackingLink;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One shipment as clients see it, in API answers and in webhook `data`.
 * Money is a string with two decimals, exactly as stored. Personal data (pickup, recipient, items, note) appears
 * only when the caller asks with withParties(): webhooks never carry it.
 *
 * @mixin Shipment
 */
class ShipmentResource extends JsonResource
{
    private bool $parties = false;

    public function withParties(bool $parties = true): static
    {
        $this->parties = $parties;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(?Request $request = null): array
    {
        /** @var Shipment $s */
        $s = $this->resource;
        $account = $s->courierAccount;

        $data = [
            'id' => $s->public_id,
            'client_reference' => $s->client_reference,
            'order_label' => $s->order_label,
            'source' => $s->source,
            'account' => $account === null ? null : [
                'id' => $account->public_id,
                'courier' => $account->courier,
                'label' => $account->label,
            ],
            'courier' => $s->courier,
            'state' => $s->state,
            'cancel_state' => $s->cancel_state,
            'phase' => $s->phase,
            'courier_status' => $s->courier_status,
            'courier_status_label' => $this->statusLabel($s),
            'tracking_number' => $s->tracking_number,
            'tracking_url' => TrackingLink::for($s),
            'label_available' => $s->state === 'booked' && $s->tracking_number !== null,
            'method' => $s->method,
            'service' => $s->service,
            // The slot's day when there is a slot (it is what LBC and SPX book on), else the date sent as pickup_date.
            'pickup_date' => PickupSlot::date($s) ?? (is_string(data_get($s->options, 'pickup_date')) ? data_get($s->options, 'pickup_date') : null),
            'pickup_slot' => is_string(data_get($s->options, 'pickup_slot')) ? data_get($s->options, 'pickup_slot') : null,
            'pickup_slot_label' => PickupSlot::label($s),
            'fee' => $s->fee_amount === null ? null : ['amount' => $s->fee_amount, 'currency' => $s->fee_currency ?? 'PHP'],
            'payment_method' => $s->payment_method,
            'payment_state' => $s->payment_state,
            'cod_amount' => $s->cod_amount,
            'declared_value' => $s->declared_value,
            'insure' => data_get($s->options, 'insure') === true,
            'items_count' => (int) $s->items_count,
            'items_summary' => $s->items_summary,
            'notice' => $s->notice,
            'error' => $s->error_code === null && $s->error_message === null ? null : [
                'code' => $s->error_code,
                'message' => $s->error_message,
            ],
            'needs_attention' => (bool) $s->needs_attention,
            'attention_reason' => $s->attention_reason,
            'created_at' => $this->time($s->created_at),
            'booked_at' => $this->time($s->booked_at),
            'updated_at' => $this->time($s->updated_at),
            'metadata' => $s->metadata === null || $s->metadata === [] ? (object) [] : $s->metadata,
        ];

        if ($this->parties) {
            $data['pickup'] = $this->party($s->pickup);
            $data['recipient'] = $this->party($s->recipient);
            $data['items'] = $s->items ?? [];
            $data['note'] = $s->courier_note;
            $data['parcel'] = data_get($s->options, 'parcel');
        }

        return $data;
    }

    /** `codes` is an object on the wire even when empty (stored as an empty PHP array, which would encode as `[]`). */
    private function party(mixed $party): mixed
    {
        if (is_array($party) && array_key_exists('codes', $party) && ($party['codes'] === [] || $party['codes'] === null)) {
            $party['codes'] = (object) [];
        }

        return $party;
    }

    private function time(?CarbonInterface $at): ?string
    {
        return $at?->copy()->setTimezone(config('app.timezone'))->toIso8601String();
    }

    private function statusLabel(Shipment $s): ?string
    {
        if ($s->courier === null || $s->courier_status === null || $s->courier_status === '') {
            return null;
        }

        return app(PhaseResolver::class)->label($s->courier, $s->courier_status);
    }
}
