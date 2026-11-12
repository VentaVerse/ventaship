<?php

namespace App\Http\Requests\Ui;

use App\Models\Shipment;

/**
 * Edit booking (SPX 1.7 Update Order). The same rules as the shipment form, with everything that cannot change taken
 * from the booked shipment, whatever was posted: the account, the pickup address, the value, the payment and who
 * pays shipping, insurance, the handover, the item category and the order reference.
 */
class UpdateSpxBookingRequest extends ShipmentFormRequest
{
    protected function prepareForValidation(): void
    {
        $s = $this->route('shipment');
        if ($s instanceof Shipment) {
            $options = is_array($s->options) ? $s->options : [];
            $cod = $s->payment_method === 'cod';
            $value = $cod ? $s->cod_amount : ($s->total ?? $s->declared_value);

            $parcel = (array) $this->input('parcel', []);
            $parcel['category'] = $options['parcel']['category'] ?? null;

            $this->merge([
                'account_id' => $s->courierAccount?->public_id,
                'pickup_address_id' => 'current',
                'value' => is_numeric($value) && (float) $value > 0 ? (string) $value : '1',
                'payment' => ['method' => $cod ? 'cod' : 'prepaid', 'shipping_payment' => $options['shipping_payment'] ?? null],
                'insure' => ($options['insure'] ?? false) === true ? '1' : '0',
                'handover' => $s->method === 'pickup' ? 'pickup' : 'dropoff',
                'client_reference' => $s->client_reference,
                'parcel' => $parcel,
                'customer_id' => null,
                'save_customer' => '0',
            ]);
            if ($s->method !== 'pickup') {
                $this->offsetUnset('pickup_slot');
            }
        }

        parent::prepareForValidation();
    }
}
