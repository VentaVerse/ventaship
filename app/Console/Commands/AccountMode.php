<?php

namespace App\Console\Commands;

use App\Couriers\CourierAccountException;
use App\Couriers\CourierAccounts;
use App\Couriers\CourierRegistry;
use App\Models\CourierAccount;
use Illuminate\Console\Command;

class AccountMode extends Command
{
    protected $signature = 'ventaship:account-mode {account} {mode}';

    protected $description = "Switch a courier account between its courier's modes (audited)";

    public function handle(CourierAccounts $accounts): int
    {
        $publicId = (string) $this->argument('account');
        $mode = (string) $this->argument('mode');

        $account = CourierAccount::query()->where('public_id', $publicId)->first();
        if ($account === null || ! CourierRegistry::has($account->courier) || ! isset(CourierAccounts::MODES[$account->courier])) {
            $this->error('No such account.');

            return self::FAILURE;
        }

        try {
            $from = $accounts->setMode($account, $mode);
        } catch (CourierAccountException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($from === null) {
            $this->info("{$account->public_id} is already in {$mode} mode. Nothing changed.");

            return self::SUCCESS;
        }

        $this->info("{$account->public_id} ({$account->courier}) mode: {$from} → {$mode}");

        if ($mode === 'production') {
            $this->warn($account->courier === 'quadx'
                ? 'QuadX has no live-booking lock: this account now books real parcels.'
                : 'Real bookings still need the live-booking locks (env, safety setting, the account\'s allow_live_booking).');
        }

        return self::SUCCESS;
    }
}
