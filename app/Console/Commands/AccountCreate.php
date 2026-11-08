<?php

namespace App\Console\Commands;

use App\Couriers\CourierRegistry;
use App\Models\CourierAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountCreate extends Command
{
    protected $signature = 'ventaship:account-create {courier} {label} {--mode=staging} {--default}';

    protected $description = 'Create a courier account and print its id and inbound webhook URL';

    private const MODES = ['staging', 'production'];

    public function handle(): int
    {
        $courier = (string) $this->argument('courier');
        $label = trim((string) $this->argument('label'));
        $mode = (string) $this->option('mode');

        if (! CourierRegistry::has($courier)) {
            $this->error('Unknown courier. Available: ' . implode(', ', CourierRegistry::keys()) . '.');

            return self::FAILURE;
        }
        if ($label === '' || mb_strlen($label) > 255) {
            $this->error('The label must be 1 to 255 characters.');

            return self::FAILURE;
        }
        if (! in_array($mode, self::MODES, true)) {
            $this->error('The mode must be staging or production.');

            return self::FAILURE;
        }

        $token = Str::random(40);
        $default = (bool) $this->option('default');

        $account = DB::transaction(function () use ($courier, $label, $mode, $token, $default) {
            if ($default) {
                CourierAccount::withTrashed()
                    ->where('default_slot', $courier)
                    ->update(['default_slot' => null, 'is_default' => false]);
            }

            return CourierAccount::query()->create([
                'courier' => $courier,
                'label' => $label,
                'mode' => $mode,
                'status' => 'active',
                'is_default' => $default,
                'default_slot' => $default ? $courier : null,
                'webhook_token' => $token,
                'webhook_token_hash' => hash('sha256', $token),
                'allow_live_booking' => false,
            ]);
        });

        $url = rtrim((string) config('app.url'), '/') . "/webhooks/{$courier}?token={$token}";

        $this->line("Account:     {$account->public_id}");
        $this->line("Webhook URL: {$url}");
        $this->warn('The webhook URL carries a secret token. Give it only to the courier.');

        return self::SUCCESS;
    }
}
