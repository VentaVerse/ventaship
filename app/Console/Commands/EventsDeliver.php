<?php

namespace App\Console\Commands;

use App\Models\OutgoingEvent;
use App\Webhooks\Outbound\Jobs\DeliverEvent;
use Illuminate\Console\Command;

/** Queues delivery of every due outgoing event (oldest sequence first per shipment), at most 200 per run. */
class EventsDeliver extends Command
{
    protected $signature = 'ventaship:events-deliver';

    protected $description = 'Queue delivery of due outgoing webhook events';

    private const BATCH = 200;

    public function handle(): int
    {
        $now = now();

        $ids = OutgoingEvent::query()
            ->where('status', 'pending')
            ->where('next_attempt_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('leased_until')->orWhere('leased_until', '<=', $now))
            ->orderBy('shipment_id')
            ->orderBy('sequence')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->pluck('id');

        foreach ($ids as $id) {
            DeliverEvent::dispatch((int) $id);
        }

        $this->info("Queued {$ids->count()} event(s).");

        return self::SUCCESS;
    }
}
