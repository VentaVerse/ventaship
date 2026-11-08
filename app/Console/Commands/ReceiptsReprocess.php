<?php

namespace App\Console\Commands;

use App\Models\WebhookReceipt;
use App\Webhooks\Inbound\Jobs\ProcessReceipt;
use App\Webhooks\Inbound\Jobs\RelayReceipt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Every minute: queues again any courier webhook receipt still unprocessed two minutes after it arrived, so a lost
 * dispatch never loses data. ProcessReceipt skips a receipt another job already settled or is processing.
 * A `relay_pending` receipt (its relay could not be queued) has its relay queued again.
 */
class ReceiptsReprocess extends Command
{
    protected $signature = 'ventaship:receipts-reprocess';

    protected $description = 'Queue courier webhook receipts left unprocessed';

    private const AFTER_MINUTES = 2;

    private const BATCH = 500;

    /** A receipt queued again is not queued a second time for this long, so a backed-up queue is not flooded. */
    private const REQUEUE_SECONDS = 300;

    public function handle(): int
    {
        $ids = WebhookReceipt::query()
            ->where('status', 'received')
            ->whereNull('processed_at')
            ->where('received_at', '<', now()->subMinutes(self::AFTER_MINUTES))
            ->orderBy('id')
            ->limit(self::BATCH)
            ->pluck('id');

        $queued = 0;
        foreach ($ids as $id) {
            if (Cache::add("receipt-requeued:{$id}", true, self::REQUEUE_SECONDS)) {
                ProcessReceipt::dispatch((int) $id);
                $queued++;
            }
        }

        $relays = WebhookReceipt::query()
            ->where('status', 'relay_pending')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->pluck('id');

        $relayed = 0;
        foreach ($relays as $id) {
            if (Cache::add("receipt-relay-requeued:{$id}", true, self::REQUEUE_SECONDS)) {
                RelayReceipt::dispatch((int) $id);
                $relayed++;
            }
        }

        $this->info("Queued {$queued} receipts and {$relayed} relays.");

        return self::SUCCESS;
    }
}
