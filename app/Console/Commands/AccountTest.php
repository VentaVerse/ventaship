<?php

namespace App\Console\Commands;

use App\Couriers\CourierAccounts;
use App\Couriers\CourierRegistry;
use App\Models\CourierAccount;
use Illuminate\Console\Command;

class AccountTest extends Command
{
    protected $signature = 'ventaship:account-test {account}';

    protected $description = "Check a courier account's credentials against the courier";

    public function handle(CourierAccounts $accounts): int
    {
        $account = CourierAccount::query()->where('public_id', (string) $this->argument('account'))->first();
        if ($account === null || ! CourierRegistry::has($account->courier)) {
            $this->error('No such account.');

            return self::FAILURE;
        }

        $result = $accounts->test($account);
        $ok = $result['ok'];

        $message = $result['message'];
        $ok ? $this->info($message) : $this->error($message);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
