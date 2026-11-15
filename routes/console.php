<?php

use App\Console\Commands\DrainQueue;
use App\Support\CourierPoll;
use App\Support\Settings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$pollMinutes = CourierPoll::minutes(app(Settings::class));

Schedule::command('ventaship:schedule-heartbeat')->everyMinute();
Schedule::command('ventaship:couriers-poll')->cron("*/{$pollMinutes} * * * *")->withoutOverlapping();
Schedule::command('ventaship:shipments-sweep')->everyMinute()->withoutOverlapping();
Schedule::command('ventaship:events-deliver')->everyMinute()->withoutOverlapping();
Schedule::command('ventaship:receipts-reprocess')->everyMinute()->withoutOverlapping();
Schedule::command('ventaship:logs-prune')->daily()->withoutOverlapping();

// Hosts that run only cron (HestiaCP): jobs still queued (API bookings and cancels, retries) are worked here. In the
// background, so this tick's other commands are not held up by it. A host that blocks the pcntl functions cannot run
// queue:work's daemon, so there queue:drain works the same queues one job at a time (as VentaCart does).
Schedule::command(DrainQueue::signalsAvailable()
    ? 'queue:work --queue=couriers,inbound,outgoing,default --stop-when-empty --max-time=50'
    : 'queue:drain --queue=couriers,inbound,outgoing,default --max-time=50')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
