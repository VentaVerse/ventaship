<?php

namespace Tests\Concerns;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\CourierAccount;

trait BuildsShipments
{
    /** A default QuadX account whose courier is a FakeCourier, so nothing ever reaches QuadX. */
    protected function fakeDefaultAccount(string $courier = 'quadx', array $attributes = []): CourierAccount
    {
        CourierRegistry::fake($courier, FakeCourier::make($courier));

        return CourierAccount::factory()->create($attributes + [
            'courier' => $courier,
            'label' => 'Main '.strtoupper($courier),
            'is_default' => true,
            'default_slot' => $courier,
        ]);
    }

    /** A valid POST /shipments body; $overrides are merged with array_replace_recursive. */
    protected function shipmentBody(array $overrides = []): array
    {
        $body = [
            'courier' => 'quadx',
            'client_reference' => '4211',
            'order_label' => '#4211',
            'method' => 'pickup',
            'service' => 'next_day',
            'parcel' => ['type' => 'box', 'weight_kg' => 1.2, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10],
            'payment' => ['method' => 'cod', 'cod_amount' => 1290.00, 'declared_value' => 1290.00, 'shipping_payment' => null],
            'pickup' => [
                'contact_name' => 'Venta Warehouse', 'contact_phone' => '09181234567', 'address_1' => '5 Shaw Blvd',
                'barangay' => 'Wack-Wack', 'district' => null, 'city' => 'Mandaluyong', 'state' => 'Metro Manila',
                'postcode' => '1550', 'country' => 'PH',
                'codes' => ['state' => '1300000000', 'city' => '1380500000', 'barangay' => '1380500020'],
            ],
            'recipient' => [
                'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'phone' => '09171234567',
                'address_1' => '1 Rizal St', 'barangay' => 'Poblacion', 'city' => 'Makati', 'state' => 'Metro Manila',
                'postcode' => '1210', 'country' => 'PH', 'codes' => [],
            ],
            'items' => [
                ['name' => 'Blue Tee', 'quantity' => 2, 'price' => 500.00, 'weight_kg' => 0.4],
                ['name' => 'Cap', 'quantity' => 1, 'price' => 200.00, 'weight_kg' => 0.4],
            ],
            'total' => 1290.00,
            'subtotal' => 1200.00,
            'note' => 'Call before delivery',
            'metadata' => ['shop_id' => '501'],
        ];

        return array_replace_recursive($body, $overrides);
    }
}
