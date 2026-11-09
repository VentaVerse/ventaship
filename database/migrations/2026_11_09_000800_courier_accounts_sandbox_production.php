<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Courier accounts have two environments, sandbox and production, for every courier. The stored mode becomes
 * `sandbox` (from staging, and SPX's uat) or stays `production`, and every credential moves to a key named after its
 * environment:
 *
 *  - QuadX: quadx_staging_* → quadx_sandbox_* (production keys keep their names).
 *  - SPX:   spx_app_id, spx_secret_key, spx_user_id, spx_user_secret → spx_{env}_* for the account's environment.
 *  - LBC:   lbc_test_* → lbc_sandbox_*, lbc_live_* → lbc_production_*. The lbc_mode key set goes; the account's
 *           environment becomes the key set it was sending, so the same keys keep being sent.
 *
 * Values move as they are (the vault does not bind a secret to its key name). A key whose new name is already taken
 * is left where it is, never overwritten. Safe to run again.
 */
return new class extends Migration
{
    private const SPX_KEYS = ['app_id', 'secret_key', 'user_id', 'user_secret'];

    private const LBC_APIS = ['common', 'airwaybill', 'pickup', 'track'];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (DB::table('courier_accounts')->orderBy('id')->get(['id', 'courier', 'mode']) as $account) {
                $env = $this->environmentOf($account);
                foreach ($this->renames($account->courier, $env) as $from => $to) {
                    $this->rename($account->id, $from, $to);
                }
                if ($account->courier === 'lbc') {
                    DB::table('courier_account_settings')->where('courier_account_id', $account->id)->where('key', 'lbc_mode')->delete();
                }
                if ($account->mode !== $env) {
                    DB::table('courier_accounts')->where('id', $account->id)->update(['mode' => $env]);
                }
            }
        });

        $this->setDefault('sandbox');
    }

    public function down(): void
    {
        DB::transaction(function () {
            foreach (DB::table('courier_accounts')->orderBy('id')->get(['id', 'courier', 'mode']) as $account) {
                $env = $account->mode === 'production' ? 'production' : 'sandbox';
                foreach ($this->renames($account->courier, $env) as $from => $to) {
                    $this->rename($account->id, $to, $from);
                }
                if ($account->courier === 'lbc') {
                    DB::table('courier_account_settings')->updateOrInsert(
                        ['courier_account_id' => $account->id, 'key' => 'lbc_mode'],
                        ['value' => $env === 'production' ? 'live' : 'test', 'created_at' => now(), 'updated_at' => now()],
                    );
                }
                DB::table('courier_accounts')->where('id', $account->id)->update(['mode' => $env === 'production' ? 'production' : 'staging']);
            }
        });

        $this->setDefault('staging');
    }

    /**
     * The column default. Not on SQLite: changing a column there rebuilds the table, and dropping the old table
     * cascades to courier_account_settings. The model's own default covers new accounts either way.
     */
    private function setDefault(string $mode): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        Schema::table('courier_accounts', function (Blueprint $table) use ($mode) {
            $table->string('mode', 16)->default($mode)->change();
        });
    }

    /** The environment an account is in: production, or sandbox for everything else (staging, uat). */
    private function environmentOf(object $account): string
    {
        if ($account->courier === 'lbc') {
            $keySet = DB::table('courier_account_settings')->where('courier_account_id', $account->id)->where('key', 'lbc_mode')->value('value');
            if ($keySet === 'live' || $keySet === 'test') {
                return $keySet === 'live' ? 'production' : 'sandbox';
            }
        }

        return $account->mode === 'production' ? 'production' : 'sandbox';
    }

    /** @return array<string, string> old key => new key */
    private function renames(string $courier, string $env): array
    {
        return match ($courier) {
            'quadx' => ['quadx_staging_api_key' => 'quadx_sandbox_api_key', 'quadx_staging_secret_key' => 'quadx_sandbox_secret_key'],
            'spx' => collect(self::SPX_KEYS)->mapWithKeys(fn (string $k) => ["spx_{$k}" => "spx_{$env}_{$k}"])->all(),
            'lbc' => collect(self::LBC_APIS)->flatMap(fn (string $api) => [
                "lbc_test_{$api}_key" => "lbc_sandbox_{$api}_key",
                "lbc_live_{$api}_key" => "lbc_production_{$api}_key",
            ])->all(),
            default => [],
        };
    }

    private function rename(int $accountId, string $from, string $to): void
    {
        $rows = DB::table('courier_account_settings')->where('courier_account_id', $accountId);
        if ((clone $rows)->where('key', $to)->exists()) {
            return;
        }
        (clone $rows)->where('key', $from)->update(['key' => $to, 'updated_at' => now()]);
    }
};
