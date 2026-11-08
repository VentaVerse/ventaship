<?php

namespace App\Console\Commands;

use App\Models\OutgoingEvent;
use App\Platforms\PlatformApps;
use Illuminate\Console\Command;

/** Puts a failed or delivered event back in the outbox with a fresh 72-hour window. */
class EventsResend extends Command
{
    protected $signature = 'ventaship:events-resend {event : The event id (evt_…)}';

    protected $description = 'Send a failed or delivered outgoing webhook event again';

    public function handle(PlatformApps $apps): int
    {
        $e = OutgoingEvent::query()->where('public_id', (string) $this->argument('event'))->first();
        if ($e === null) {
            $this->error('No such event.');

            return self::FAILURE;
        }

        if (! $apps->requeue($e)) {
            $this->error("Only a failed or delivered event can be resent; {$e->public_id} is {$e->status}.");

            return self::FAILURE;
        }

        $this->info("{$e->public_id} will be sent on the next delivery run.");

        return self::SUCCESS;
    }
}
