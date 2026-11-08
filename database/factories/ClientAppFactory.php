<?php

namespace Database\Factories;

use App\Models\ClientApp;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ClientApp> */
class ClientAppFactory extends Factory
{
    protected $model = ClientApp::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'status' => 'active',
            'webhook_url' => null,
            'webhook_secret' => 'whsec_' . Str::random(32),
        ];
    }
}
