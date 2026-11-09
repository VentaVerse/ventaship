<?php

namespace App\Console\Commands;

use App\Couriers\AccountSettings;
use App\Couriers\CourierRegistry;
use App\Models\CourierAccount;
use Illuminate\Console\Command;

class AccountShow extends Command
{
    protected $signature = 'ventaship:account-show {account}';

    protected $description = 'Show a courier account and its settings, secrets masked';

    /** A secret shorter than this shows bullets only: four characters would give most of it away. */
    private const MIN_LENGTH_FOR_TAIL = 8;

    public function handle(): int
    {
        $account = CourierAccount::query()->where('public_id', (string) $this->argument('account'))->first();
        if ($account === null) {
            $this->error('No such account.');

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['id', $account->public_id],
            ['courier', $account->courier],
            ['label', $account->label],
            ['mode', $account->mode],
            ['status', $account->status],
            ['default', $account->is_default ? 'yes' : 'no'],
            ['webhook_token', $account->webhook_token !== null ? self::mask($account->webhook_token) : '-'],
            ['last_check', $account->last_check_at ? $account->last_check_at->toIso8601String() . ($account->last_check_ok ? ' ok' : ' failed') : '-'],
        ]);

        $schema = CourierRegistry::has($account->courier)
            ? collect(CourierRegistry::describe($account->courier)->settingsSchema())->keyBy('key')
            : collect();
        $settings = AccountSettings::for($account);

        $rows = [];
        foreach ($account->settings()->orderBy('key')->pluck('key') as $key) {
            // A key the schema does not declare as plain is treated as a secret.
            $plain = $schema->has($key) && empty($schema[$key]['encrypted']);
            $rows[] = [$key, $plain ? (string) $settings->read($key) : self::maskSecret($settings->secret($key))];
        }

        $this->table(['Setting', 'Value'], $rows);

        return self::SUCCESS;
    }

    private static function maskSecret(?string $value): string
    {
        return $value === null ? '(unreadable)' : self::mask($value);
    }

    private static function mask(string $value): string
    {
        return mb_strlen($value) >= self::MIN_LENGTH_FOR_TAIL ? '••••' . mb_substr($value, -4) : '••••';
    }
}
