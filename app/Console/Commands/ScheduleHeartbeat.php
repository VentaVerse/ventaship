<?php

namespace App\Console\Commands;

use App\Support\Settings;
use Illuminate\Console\Command;

/** Every minute: stamps when the scheduler last ran, for Settings → Scheduled jobs. */
class ScheduleHeartbeat extends Command
{
    public const KEY = 'schedule.last_run_at';

    protected $signature = 'ventaship:schedule-heartbeat';

    protected $description = 'Record that the scheduler ran';

    public function handle(Settings $settings): int
    {
        $settings->set(self::KEY, now()->toIso8601String());

        return self::SUCCESS;
    }
}
