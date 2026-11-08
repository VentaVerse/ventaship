<?php

namespace App\Console\Commands;

use App\Couriers\CourierAccountException;
use App\Couriers\CourierAccounts;
use App\Models\CourierAccount;
use Illuminate\Console\Command;

class LbcSeriesAdd extends Command
{
    protected $signature = 'ventaship:lbc-series-add {account} {first} {last}';

    protected $description = 'Record a block of tracking numbers LBC assigned to an LBC account';

    public function handle(CourierAccounts $accounts): int
    {
        $account = CourierAccount::query()->where('public_id', (string) $this->argument('account'))->first();
        if ($account === null || $account->courier !== 'lbc') {
            $this->error('No such LBC account.');

            return self::FAILURE;
        }

        $first = trim((string) $this->argument('first'));
        $last = trim((string) $this->argument('last'));

        try {
            $block = $accounts->addLbcSeries($account, $first, $last);
        } catch (CourierAccountException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Added {$first} to {$last} ({$block->size()} numbers) for {$account->public_id}.");

        return self::SUCCESS;
    }
}
