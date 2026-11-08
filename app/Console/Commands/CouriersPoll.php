<?php

namespace App\Console\Commands;

use App\Shipments\Poller;
use Illuminate\Console\Command;

/** Scheduled every couriers.poll_minutes (default 10) without overlapping; see Poller. */
class CouriersPoll extends Command
{
    protected $signature = 'ventaship:couriers-poll';

    protected $description = 'Ask couriers about pending creations and non-terminal booked shipments';

    public function handle(Poller $poller): int
    {
        $polled = $poller->run();

        $this->info("Polled {$polled} shipments.");

        return self::SUCCESS;
    }
}
