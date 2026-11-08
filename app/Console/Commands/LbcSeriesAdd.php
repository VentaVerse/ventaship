<?php

namespace App\Console\Commands;

use App\Couriers\TrackingNumbers\TrackingNumberSeriesException;
use App\Couriers\TrackingNumbers\TrackingNumberSeriesService;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LbcSeriesAdd extends Command
{
    protected $signature = 'ventaship:lbc-series-add {account} {first} {last}';

    protected $description = 'Record a block of tracking numbers LBC assigned to an LBC account';

    public function handle(TrackingNumberSeriesService $series): int
    {
        $account = CourierAccount::query()->where('public_id', (string) $this->argument('account'))->first();
        if ($account === null || $account->courier !== 'lbc') {
            $this->error('No such LBC account.');

            return self::FAILURE;
        }

        $first = trim((string) $this->argument('first'));
        $last = trim((string) $this->argument('last'));
        $digits = '/^[1-9]\d{0,' . (TrackingNumberSeriesService::MAX_DIGITS - 1) . '}$/';
        if (! preg_match($digits, $first) || ! preg_match($digits, $last)) {
            $this->error('The first and last numbers must be whole numbers of at most ' . TrackingNumberSeriesService::MAX_DIGITS . ' digits.');

            return self::FAILURE;
        }

        try {
            $block = DB::transaction(function () use ($series, $account, $first, $last) {
                $block = $series->addSeries($account, (int) $first, (int) $last);

                AuditEntry::query()->create([
                    'actor_type' => 'system',
                    'action' => 'tracking_series.added',
                    'subject_type' => 'courier_account',
                    'subject_id' => $account->public_id,
                    'changes' => ['first_number' => $first, 'last_number' => $last],
                    'created_at' => now(),
                ]);

                return $block;
            });
        } catch (TrackingNumberSeriesException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Added {$first} to {$last} ({$block->size()} numbers) for {$account->public_id}.");

        return self::SUCCESS;
    }
}
