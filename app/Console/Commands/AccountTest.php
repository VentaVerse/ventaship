<?php

namespace App\Console\Commands;

use App\Couriers\CourierRegistry;
use App\Models\CourierAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;

class AccountTest extends Command
{
    protected $signature = 'ventaship:account-test {account}';

    protected $description = "Check a courier account's credentials against the courier";

    public function handle(): int
    {
        $account = CourierAccount::query()->where('public_id', (string) $this->argument('account'))->first();
        if ($account === null || ! CourierRegistry::has($account->courier)) {
            $this->error('No such account.');

            return self::FAILURE;
        }

        $result = Context::scope(
            fn () => CourierRegistry::for($account)->testConnection(),
            ['account_id' => $account->getKey(), 'operation' => 'test_connection'],
        );

        $ok = (bool) ($result['ok'] ?? false);
        $account->forceFill(['last_check_at' => now(), 'last_check_ok' => $ok])->save();

        $message = (string) ($result['message'] ?? '');
        $ok ? $this->info($message) : $this->error($message);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
