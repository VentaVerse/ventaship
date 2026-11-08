<?php

namespace App\Console\Commands;

use App\Models\Shipment;
use App\Shipments\BookingOutcomes;
use App\Shipments\Canceller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Every minute (base plan §2 "Booking safety"):
 * - queued for more than 30 minutes (the job never started) → failed / not_started
 * - booking for more than 10 minutes, not waiting on SPX `creating` → unknown (the job died mid-call)
 * - waiting on SPX `creating` for more than 6 hours → needs attention, state unchanged; the poller keeps trying
 * - booked with a cancel `requested` for more than 30 minutes (since the request and since booking) and no cancel
 *   job holding the shipment's cancel lock → needs attention. The courier is never called from here.
 */
class ShipmentsSweep extends Command
{
    protected $signature = 'ventaship:shipments-sweep';

    protected $description = 'Fail shipments whose booking never started and flag bookings that never finished';

    private const QUEUED_MINUTES = 30;

    private const BOOKING_MINUTES = 10;

    private const CANCEL_MINUTES = 30;

    private const CREATING_HOURS = 6;

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

        $creating = $this->flagStaleCreating();
        $stuck = $this->flagStuckCancels();

        $this->info("Failed {$failed} queued, flagged {$unknown} booking as unknown, {$creating} unconfirmed SPX bookings, {$stuck} stuck cancels.");

        return self::SUCCESS;
    }

    /** One guarded UPDATE: no courier call, no state change, and attention someone else raised is never replaced. */
    private function flagStaleCreating(): int
    {
        return Shipment::query()
            ->where('state', 'booking')
            ->where('courier_status', 'creating')
            ->where('booking_started_at', '<', now()->subHours(self::CREATING_HOURS))
            ->where('needs_attention', false)
            ->update(['needs_attention' => true, 'attention_reason' => BookingOutcomes::CREATING_STALE_REASON, 'updated_at' => now()]);
    }

    private function flagStuckCancels(): int
    {
        $before = now()->subMinutes(self::CANCEL_MINUTES);
        $flagged = 0;

        Shipment::query()
            ->where('state', 'booked')
            ->where('cancel_state', 'requested')
            ->where('needs_attention', false)
            ->where(fn ($q) => $q->where('cancel_requested_at', '<', $before)
                ->orWhere(fn ($q) => $q->whereNull('cancel_requested_at')->where('updated_at', '<', $before)))
            ->where(fn ($q) => $q->whereNull('booked_at')->orWhere('booked_at', '<', $before))
            ->chunkById(200, function ($rows) use (&$flagged) {
                foreach ($rows as $s) {
                    // A CancelAtCourier still running holds this lock: leave it to finish.
                    $lock = Cache::lock("shipment-cancel:{$s->id}", 30);
                    if (! $lock->get()) {
                        continue;
                    }

                    try {
                        $flagged += Shipment::query()
                            ->whereKey($s->id)
                            ->where('state', 'booked')
                            ->where('cancel_state', 'requested')
                            ->where('needs_attention', false)
                            ->update(['needs_attention' => true, 'attention_reason' => Canceller::STUCK_REASON]);
                    } finally {
                        $lock->release();
                    }
                }
            }, 'id');

        return $flagged;
    }
}
