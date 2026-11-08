<?php

namespace App\Console\Commands;

use App\Models\OutgoingEvent;
use Illuminate\Console\Command;

/** Puts a failed or delivered event back in the outbox with a fresh 72-hour window. */
class EventsResend extends Command
{
    protected $signature = 'ventaship:events-resend {event : The event id (evt_…)}';

    protected $description = 'Send a failed or delivered outgoing webhook event again';

    public function handle(): int
    {
        $e = OutgoingEvent::query()->where('public_id', (string) $this->argument('event'))->first();
        if ($e === null) {
            $this->error('No such event.');

            return self::FAILURE;
        }

        // Conditional update: never touches an event a worker is delivering right now.
        $reset = OutgoingEvent::query()
            ->whereKey($e->id)
            ->whereIn('status', ['failed', 'delivered'])
            ->update([
                'status' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => now(),
                'window_started_at' => now(),
                'leased_until' => null,
                'delivered_at' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($reset === 0) {
            $this->error("Only a failed or delivered event can be resent; {$e->public_id} is {$e->status}.");

            return self::FAILURE;
        }

        $this->info("{$e->public_id} will be sent on the next delivery run.");

        return self::SUCCESS;
    }
}
