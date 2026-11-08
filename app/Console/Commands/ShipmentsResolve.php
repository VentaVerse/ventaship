<?php

namespace App\Console\Commands;

use App\Models\Shipment;
use App\Shipments\BookingOutcomes;
use Illuminate\Console\Command;

/**
 * The operator's resolution of an `unknown` booking, after checking the courier portal: not booked (→ failed, the
 * reference is freed) or booked under a tracking number (→ booked). Writes outbox `shipment.resolved`.
 */
class ShipmentsResolve extends Command
{
    protected $signature = 'ventaship:shipments-resolve {shipment : The shipment id (shp_…)}
        {--not-booked : The courier portal shows no booking}
        {--tracking= : The tracking number the courier portal shows}';

    protected $description = 'Resolve a shipment whose booking outcome is unknown';

    public function handle(BookingOutcomes $outcomes): int
    {
        $notBooked = (bool) $this->option('not-booked');
        $tracking = $this->option('tracking');
        $tracking = is_string($tracking) ? trim($tracking) : null;

        if ($notBooked === ($tracking !== null)) {
            $this->error('Give exactly one of --not-booked or --tracking=.');

            return self::FAILURE;
        }

        if ($tracking !== null && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{2,63}$/', $tracking)) {
            $this->error('That is not a tracking number (letters, digits, - and _, 3 to 64 characters).');

            return self::FAILURE;
        }

        $s = Shipment::query()->where('public_id', (string) $this->argument('shipment'))->first();
        if ($s === null) {
            $this->error('No such shipment.');

            return self::FAILURE;
        }

        $resolved = $notBooked ? $outcomes->resolveNotBooked($s->id) : $outcomes->resolveBooked($s->id, $tracking);
        if ($resolved === null) {
            $this->error("Only an unknown shipment can be resolved; {$s->public_id} is {$s->refresh()->state}.");

            return self::FAILURE;
        }

        $this->info($notBooked
            ? "{$s->public_id} marked not booked; its client reference is free again."
            : "{$s->public_id} marked booked with tracking {$tracking}.");

        return self::SUCCESS;
    }
}
