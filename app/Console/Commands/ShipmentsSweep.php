<?php

namespace App\Console\Commands;

use App\Models\Shipment;
use App\Shipments\BookingOutcomes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Every minute (base plan §2 "Booking safety"):
 * - queued for more than 30 minutes (the job never started) → failed / not_started
 * - booking for more than 10 minutes, not waiting on SPX `creating` → unknown (the job died mid-call)
 */
class ShipmentsSweep extends Command
{
    protected $signature = 'ventaship:shipments-sweep';

    protected $description = 'Fail shipments whose booking never started and flag bookings that never finished';

    private const QUEUED_MINUTES = 30;

    private const BOOKING_MINUTES = 10;

    public function handle(BookingOutcomes $outcomes): int
    {
        $failed = 0;
        $unknown = 0;

        // updated_at, not created_at: a draft submitted today may have been created days ago.
        Shipment::query()
            ->where('state', 'queued')
            ->where('updated_at', '<', now()->subMinutes(self::QUEUED_MINUTES))
            ->chunkById(200, function ($rows) use ($outcomes, &$failed) {
                foreach ($rows as $s) {
                    $done = $outcomes->failed($s->id, 'not_started', 'Booking did not start within '.self::QUEUED_MINUTES.' minutes.', ['queued']);
                    $failed += $done !== null ? 1 : 0;
                }
            }, 'id');

        $staleBefore = now()->subMinutes(self::BOOKING_MINUTES);
        Shipment::query()
            ->where('state', 'booking')
            ->where('booking_started_at', '<', $staleBefore)
            ->where(fn ($q) => $q->whereNull('courier_status')->orWhere('courier_status', '!=', 'creating'))
            ->chunkById(200, function ($rows) use ($outcomes, $staleBefore, &$unknown) {
                foreach ($rows as $s) {
                    // A job still holding the shipment lock is still running: leave it to finish.
                    $lock = Cache::lock("shipment:{$s->id}", 30);
                    if (! $lock->get()) {
                        continue;
                    }

                    try {
                        $unknown += $outcomes->unknown($s->id, $staleBefore) !== null ? 1 : 0;
                    } finally {
                        $lock->release();
                    }
                }
            }, 'id');

        $this->info("Failed {$failed} queued, flagged {$unknown} booking as unknown.");

        return self::SUCCESS;
    }
}
