<?php

namespace App\Http\Controllers\Ui;

use App\Couriers\Spx\SpxFulfillment;
use App\Fulfillment\Couriers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ui\UpdateSpxBookingRequest;
use App\Models\AuditEntry;
use App\Models\Shipment;
use App\Shipments\CourierFormFields;
use App\Shipments\ItemsText;
use App\Shipments\SpxBookingEdits;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Edit booking: an SPX shipment still Pending Pickup, changed at SPX (1.7 Update Order) and then here. */
class SpxBookingController extends Controller
{
    public function edit(Shipment $shipment): View|RedirectResponse
    {
        $shipment->loadMissing('courierAccount');
        if (! SpxBookingEdits::allowed($shipment) || $shipment->courierAccount === null) {
            return $this->gone($shipment);
        }

        return view('shipments.edit-booking', $this->page($shipment));
    }

    public function update(UpdateSpxBookingRequest $form, Shipment $shipment, SpxBookingEdits $edits): RedirectResponse
    {
        if (! SpxBookingEdits::allowed($shipment)) {
            return $this->gone($shipment);
        }

        $before = $shipment->only(['recipient', 'options', 'courier_note', 'items']);
        $refusal = $edits->update($shipment, $form->shipmentData());
        if ($refusal !== null) {
            return back()->withInput()->withErrors(['booking' => $refusal->message()]);
        }

        $after = $shipment->fresh()->only(['recipient', 'options', 'courier_note', 'items']);
        AuditEntry::query()->create([
            'actor_type' => 'admin',
            'actor_id' => (string) $form->user()->getAuthIdentifier(),
            'ip' => $form->ip(),
            'action' => 'shipment.booking_updated',
            'subject_type' => 'shipment',
            'subject_id' => $shipment->public_id,
            // The names of what changed, never the values (they hold the recipient).
            'changes' => array_keys(array_filter($after, fn ($v, $k) => $v != ($before[$k] ?? null), ARRAY_FILTER_USE_BOTH)),
            'created_at' => now(),
        ]);

        return redirect()->route('shipments.show', $shipment->public_id)->with('booking_updated', true);
    }

    private function gone(Shipment $shipment): RedirectResponse
    {
        return redirect()->route('shipments.show', $shipment->public_id)
            ->withErrors(['shipment' => 'This booking can no longer be edited.']);
    }

    /** @return array<string, mixed> */
    private function page(Shipment $s): array
    {
        $account = $s->courierAccount;
        $options = is_array($s->options) ? $s->options : [];
        $parcel = is_array($options['parcel'] ?? null) ? $options['parcel'] : [];
        $recipient = is_array($s->recipient) ? $s->recipient : [];
        $street = trim(implode(', ', array_filter([trim((string) ($recipient['address_1'] ?? '')), trim((string) ($recipient['address_2'] ?? ''))])));
        unset($recipient['address_2']);
        if ($street !== '') {
            $recipient['address_1'] = $street;
        }
        $pickup = $s->method === 'pickup';
        $slot = is_string($options['pickup_slot'] ?? null) ? $options['pickup_slot'] : '';
        $payer = CourierFormFields::SHIPPING_PAYMENT['spx'][(string) ($options['shipping_payment'] ?? '')] ?? null;

        return [
            'shipment' => $s,
            'account' => $account,
            'parcels' => CourierFormFields::parcelOptions($account),
            'sizes' => CourierFormFields::presetSizes($account),
            'recipient' => $recipient,
            'parcel' => $parcel,
            'item' => ItemsText::join($s->items, 255),
            'note' => (string) $s->courier_note,
            'pickup' => $pickup,
            'slot' => $slot,
            'slotLabel' => $pickup ? SpxFulfillment::describeSlot($slot) : null,
            'left' => SpxBookingEdits::left($s),
            'summary' => array_filter([
                'Courier' => Couriers::name('spx'),
                'Handover' => CourierFormFields::HANDOVER[$pickup ? 'pickup' : 'dropoff'],
                'Shipping fee' => $payer,
                'COD' => $s->payment_method === 'cod' && $s->cod_amount !== null ? Money::peso((string) $s->cod_amount) : 'No',
                'Insurance' => ($options['insure'] ?? false) === true ? 'Yes' : 'No',
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }
}
