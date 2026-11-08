<?php

namespace App\Shipments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Cancels one booked shipment at its courier. Dispatched by BookShipment when a cancel was requested while the
 * booking was in flight, and by the Canceller.
 *
 * Shell only: Task 11 implements handle().
 */
class CancelAtCourier implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $shipmentId)
    {
        $this->onConnection('couriers');
        $this->onQueue('couriers');
    }

    public function handle(): void
    {
        //
    }
}
