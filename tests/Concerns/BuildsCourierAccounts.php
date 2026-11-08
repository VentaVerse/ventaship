<?php

namespace Tests\Concerns;

use App\Couriers\AccountSettings;
use App\Models\CourierAccount;

trait BuildsCourierAccounts
{
    /** A staging QuadX account with credentials stored the way ventaship:account-set stores them. */
    protected function quadxAccount(string $apiKey = 'qx-staging-key-0001', string $secret = 'qx-staging-secret-0001', array $attributes = []): CourierAccount
    {
        $account = CourierAccount::factory()->create($attributes + ['courier' => 'quadx', 'mode' => 'staging']);
        $mode = $account->mode;

        $settings = AccountSettings::for($account);
        $settings->writeSecret("quadx_{$mode}_api_key", $apiKey);
        $settings->writeSecret("quadx_{$mode}_secret_key", $secret);

        return $account;
    }

    /** @return array<mixed> */
    protected function quadxFixture(string $name): array
    {
        return json_decode(
            file_get_contents(base_path("tests/Fixtures/quadx/{$name}.json")),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
