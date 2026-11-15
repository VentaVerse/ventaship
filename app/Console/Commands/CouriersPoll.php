<?php

namespace App\Console\Commands;

use App\Shipments\Poller;
use App\Support\CourierPoll;
use App\Support\Settings;
use Illuminate\Console\Command;

/** Scheduled every N minutes as set in Settings (default 10), without overlapping; see Poller. */
class CouriersPoll extends Command
{
    protected $signature = 'ventaship:couriers-poll';

    protected $description = 'Ask couriers about pending creations and non-terminal booked shipments';

    public function handle(Poller $poller, Settings $settings): int
    {
        $polled = CourierPoll::run($poller, $settings);

        $this->info("Polled {$polled} shipments.");

        return self::SUCCESS;
    }
}
