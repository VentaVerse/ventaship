<?php

namespace App\Console\Commands;

use App\Couriers\CourierRegistry;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AccountMode extends Command
{
    protected $signature = 'ventaship:account-mode {account} {mode}';

    protected $description = "Switch a courier account between its courier's modes (audited)";

    /**
     * The modes each courier knows. SPX's test system is its UAT: `staging` (what account-create stores) and `uat`
     * both reach it; only `production` reaches SPX's live system.
     */
    private const MODES = [
        'quadx' => ['staging', 'production'],
        'lbc' => ['staging', 'production'],
        'spx' => ['staging', 'uat', 'production'],
    ];

    public function handle(): int
    {
        $publicId = (string) $this->argument('account');
        $mode = (string) $this->argument('mode');

        $account = CourierAccount::query()->where('public_id', $publicId)->first();
        if ($account === null || ! CourierRegistry::has($account->courier) || ! isset(self::MODES[$account->courier])) {
            $this->error('No such account.');

            return self::FAILURE;
        }

        $modes = self::MODES[$account->courier];
        if (! in_array($mode, $modes, true)) {
            $this->error("The mode for {$account->courier} must be one of: ".implode(', ', $modes).'.');

            return self::FAILURE;
        }

        $from = DB::transaction(function () use ($account, $mode) {
            $row = CourierAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $from = (string) $row->mode;
            if ($from === $mode) {
                return null;
            }

            $row->forceFill(['mode' => $mode])->save();

            AuditEntry::query()->create([
                'actor_type' => 'system',
                'action' => 'courier_account.mode_changed',
                'subject_type' => 'courier_account',
                'subject_id' => $row->public_id,
                'changes' => ['mode' => ['from' => $from, 'to' => $mode]],
                'created_at' => now(),
            ]);

            return $from;
        });

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
