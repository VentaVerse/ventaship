<?php

namespace App\Console\Commands;

use App\Couriers\AccountSettings;
use App\Couriers\CourierRegistry;
use App\Models\CourierAccount;
use Illuminate\Console\Command;

class AccountSet extends Command
{
    protected $signature = 'ventaship:account-set {account} {key} {value?} {--secret}';

    protected $description = "Set one of a courier account's settings (prompts hidden with --secret)";

    /** The longest value accepted. Providers issue keys and tokens of very different shapes. */
    private const MAX_LENGTH = 2048;

    public function handle(): int
    {
        $account = CourierAccount::query()->where('public_id', (string) $this->argument('account'))->first();
        if ($account === null || ! CourierRegistry::has($account->courier)) {
            $this->error('No such account.');

            return self::FAILURE;
        }

        $key = (string) $this->argument('key');
        $field = collect(CourierRegistry::describe($account->courier)->settingsSchema())->firstWhere('key', $key);
        if ($field === null) {
            $this->error("Unknown setting for {$account->courier}.");

            return self::FAILURE;
        }

        $secret = (bool) $this->option('secret');
        $encrypted = ! empty($field['encrypted']);
        if ($encrypted && ! $secret) {
            $this->error("{$key} is a secret. Run again with --secret and enter it at the prompt.");

            return self::FAILURE;
        }
        if (! $encrypted && $secret) {
            $this->error("{$key} is not a secret. Run again without --secret.");

            return self::FAILURE;
        }

        $value = $this->argument('value');
        if ($value === null && $secret) {
            $value = $this->secret("Value for {$key}");
        }
        $value = $value === null ? '' : trim((string) $value);

        if ($value === '') {
            $this->error('A value is required.');

            return self::FAILURE;
        }
        if (mb_strlen($value) > self::MAX_LENGTH) {
            $this->error('The value is too long.');

            return self::FAILURE;
        }

        $options = array_column($field['options'] ?? [], 'value');
        if ($options !== [] && ! in_array($value, $options, true)) {
            $this->error('The value must be one of: ' . implode(', ', $options) . '.');

            return self::FAILURE;
        }

        $settings = AccountSettings::for($account);
        $encrypted ? $settings->writeSecret($key, $value) : $settings->write($key, $value);

        $this->info("Saved {$key} for {$account->public_id}.");

        return self::SUCCESS;
    }
}
