<?php

namespace App\Models;

use App\Shipments\PhaseResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** The owner's choice of shipment status for one courier status: a status key, or unmapped. */
class StatusMapping extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (self $mapping): void {
            $phase = $mapping->phase;

            if ($phase === null || $phase === PhaseResolver::UNMAPPED) {
                return;
            }

            if (! ShipmentStatus::query()->where('key', $phase)->exists()) {
                throw ValidationException::withMessages(['phase' => 'Choose a shipment status.']);
            }
        });
    }
}
