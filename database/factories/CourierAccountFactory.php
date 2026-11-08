<?php

namespace Database\Factories;

use App\Models\CourierAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CourierAccount> */
class CourierAccountFactory extends Factory
{
    protected $model = CourierAccount::class;

    public function definition(): array
    {
        return [
            'courier' => 'quadx',
            'label' => fake()->words(2, true),
            'mode' => 'staging',
            'status' => 'active',
            'is_default' => false,
            'default_slot' => null,
            'allow_live_booking' => false,
        ];
    }
}
