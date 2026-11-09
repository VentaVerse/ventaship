<?php

namespace App\Console\Commands;

use App\Couriers\CourierAccountException;
use App\Couriers\CourierAccounts;
use Illuminate\Console\Command;

class AccountCreate extends Command
{
    protected $signature = 'ventaship:account-create {courier} {label} {--mode=sandbox} {--default}';

    protected $description = 'Create a courier account and print its id and inbound webhook URL';

    public function handle(CourierAccounts $accounts): int
    {
        $courier = (string) $this->argument('courier');

        try {
            $account = $accounts->create($courier, (string) $this->argument('label'), (string) $this->option('mode'), (bool) $this->option('default'));
        } catch (CourierAccountException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        // LBC sends no webhooks: it is polled.
        $url = $accounts->webhookAddress($account);

        $this->line("Account:     {$account->public_id}");
        if ($url !== null) {
            $this->line("Webhook URL: {$url}");
            $this->warn('The webhook URL carries a secret token. Give it only to the courier.');
        }

        return self::SUCCESS;
    }
}
