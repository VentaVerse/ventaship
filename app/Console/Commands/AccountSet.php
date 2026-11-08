<?php

namespace App\Console\Commands;

use App\Couriers\CourierAccountException;
use App\Couriers\CourierAccounts;
use App\Couriers\CourierRegistry;
use App\Models\CourierAccount;
use Illuminate\Console\Command;

class AccountSet extends Command
{
    protected $signature = 'ventaship:account-set {account} {key} {value?} {--secret}';

    protected $description = "Set one of a courier account's settings (prompts hidden with --secret)";

    public function handle(CourierAccounts $accounts): int
    {
        $account = CourierAccount::query()->where('public_id', (string) $this->argument('account'))->first();
        if ($account === null || ! CourierRegistry::has($account->courier)) {
            $this->error('No such account.');

            return self::FAILURE;
        }

        $key = (string) $this->argument('key');
        try {
            $encrypted = $accounts->isSecret($account, $key);
        } catch (CourierAccountException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $secret = (bool) $this->option('secret');
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

        try {
            $accounts->setSetting($account, $key, $value === null ? '' : (string) $value);
        } catch (CourierAccountException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Saved {$key} for {$account->public_id}.");

        return self::SUCCESS;
    }
}
