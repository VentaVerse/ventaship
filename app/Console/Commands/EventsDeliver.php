<?php

namespace App\Console\Commands;

use App\Models\OutgoingEvent;
use App\Webhooks\Outbound\Jobs\DeliverEvent;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Queues delivery of due outgoing events, oldest sequence first per shipment: at most 200 per run and 50 per app, so
 * one app with a backlog cannot crowd out the others. Events still waiting behind an earlier pending sequence of
 * the same shipment are not selected, and an app whose URL just failed to connect is left out for the run.
 */
class EventsDeliver extends Command
{
    protected $signature = 'ventaship:events-deliver';

    protected $description = 'Queue delivery of due outgoing webhook events';

    private const BATCH = 200;

    private const PER_APP = 50;

    /** Rows read per run to fill the batch under the per-app cap. */
    private const SCAN = 2000;

    /** A queued event is not queued again for this long, unless its job settles or releases it first. */
    private const DISPATCH_LEASE_SECONDS = 300;

    public function handle(): int
    {
        $now = now();

        $candidates = OutgoingEvent::query()
            ->select(['id', 'client_app_id'])
            ->where('status', 'pending')
            ->where('next_attempt_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('leased_until')->orWhere('leased_until', '<=', $now))
            ->where(fn ($q) => $q->whereNull('dispatched_until')->orWhere('dispatched_until', '<=', $now))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')
                ->from('outgoing_events as earlier')
                ->whereColumn('earlier.shipment_id', 'outgoing_events.shipment_id')
                ->whereColumn('earlier.sequence', '<', 'outgoing_events.sequence')
                ->where('earlier.status', 'pending'))
            ->orderBy('shipment_id')
            ->orderBy('sequence')
            ->orderBy('id')
            ->limit(self::SCAN)
            ->toBase()
            ->get();

        $perApp = [];
        $queued = 0;
        foreach ($candidates as $row) {
            if ($queued >= self::BATCH) {
                break;
            }

            $appId = (int) $row->client_app_id;
            if (($perApp[$appId] ?? 0) >= self::PER_APP || Cache::has(DeliverEvent::unreachableKey($appId))) {
                continue;
            }

            // Stamp first: on a sync queue the job runs inside dispatch() and clears the stamp itself.
            $claimed = OutgoingEvent::query()
                ->whereKey($row->id)
                ->where('status', 'pending')
                ->where(fn ($q) => $q->whereNull('dispatched_until')->orWhere('dispatched_until', '<=', $now))
                ->update(['dispatched_until' => $now->copy()->addSeconds(self::DISPATCH_LEASE_SECONDS)]);
            if ($claimed === 0) {
                continue;
            }

            DeliverEvent::dispatch((int) $row->id);
            $perApp[$appId] = ($perApp[$appId] ?? 0) + 1;
            $queued++;
        }

        $this->info("Queued {$queued} event(s).");

        return self::SUCCESS;
    }
}
