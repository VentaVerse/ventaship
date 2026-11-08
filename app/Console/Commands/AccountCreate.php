<?php

namespace App\Console\Commands;

use App\Couriers\CourierAccountException;
use App\Couriers\CourierAccounts;
use Illuminate\Console\Command;

class AccountCreate extends Command
{
    protected $signature = 'ventaship:account-create {courier} {label} {--mode=staging} {--default}';

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
        $token = $account->webhook_token;

        $base = rtrim((string) (app(\App\Support\Settings::class)->publicBaseUrl() ?? config('app.url')), '/');
        $url = match ($courier) {
            'quadx' => "{$base}/webhooks/quadx?token={$token}",
            'spx' => "{$base}/webhooks/spx/{$token}",
            default => null, // LBC sends no webhooks: it is polled
        };

        $this->line("Account:     {$account->public_id}");
        if ($url !== null) {
            $this->line("Webhook URL: {$url}");
            $this->warn('The webhook URL carries a secret token. Give it only to the courier.');
        }

        return self::SUCCESS;
    }
}
