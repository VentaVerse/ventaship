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

    /**
     * An SPX account with the app pair and, unless told otherwise, the user pair, stored the way
     * ventaship:account-set stores them. Obviously fake values: no real SPX call is ever made.
     */
    protected function spxAccount(array $attributes = [], bool $withUser = true): CourierAccount
    {
        $account = CourierAccount::factory()->create($attributes + ['courier' => 'spx', 'mode' => 'staging']);

        $settings = AccountSettings::for($account);
        $settings->write('spx_app_id', '424242');
        $settings->writeSecret('spx_secret_key', 'fake-app-secret-not-real');
        if ($withUser) {
            $settings->write('spx_user_id', '999999');
            $settings->writeSecret('spx_user_secret', 'fake-user-secret-not-real');
        }

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
