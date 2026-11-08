<?php

namespace App\Shipments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Books one queued shipment with its courier. Dispatched by ShipmentCreator after the shipment's transaction
 * commits. Never retried automatically: a second createShipment could book the parcel twice.
 *
 * Shell only: Task 10 implements handle().
 */
class BookShipment implements ShouldQueue
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
