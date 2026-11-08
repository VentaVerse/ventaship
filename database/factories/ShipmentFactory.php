<?php

namespace Database\Factories;

use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Shipment> */
class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    public function definition(): array
    {
        $reference = (string) fake()->unique()->numberBetween(100000, 99999999);

        return [
            'client_app_id' => ClientApp::factory(),
            'courier_account_id' => CourierAccount::factory(),
            'courier' => 'quadx',
            'source' => 'api',
            'client_reference' => $reference,
            'active_reference' => $reference,
            'state' => 'queued',
            'method' => 'pickup',
            'payment_method' => 'prepaid',
            'recipient' => [
                'first_name' => fake()->firstName(),
                'last_name' => fake()->lastName(),
                'phone' => '09171234567',
                'address' => ['line1' => '1 Rizal St', 'city' => 'Makati', 'province' => 'Metro Manila', 'postal_code' => '1200'],
            ],
            'items' => [['name' => 'Blue Tee', 'quantity' => 1]],
            'items_count' => 1,
            'items_summary' => 'Blue Tee ×1',
            'total' => 500,
        ];
    }
}
