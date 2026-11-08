<?php

namespace App\Couriers\TrackingNumbers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One block of tracking numbers a courier assigned to one courier account. Added by ventaship:lbc-series-add, read
 * and written only through TrackingNumberSeriesService, which holds the lock.
 */
class TrackingNumberSeries extends Model
{
    protected $table = 'tracking_series';

    protected $fillable = ['courier_account_id', 'courier', 'first_number', 'last_number', 'last_issued_number', 'low_water_mark'];

    protected $casts = [
        'courier_account_id' => 'integer',
        'first_number' => 'integer',
        'last_number' => 'integer',
        'last_issued_number' => 'integer',
        'low_water_mark' => 'integer',
    ];

    public function numbers(): HasMany
    {
        return $this->hasMany(TrackingNumber::class, 'series_id');
    }

    /** How many numbers the block holds in total. */
    public function size(): int
    {
        return $this->last_number - $this->first_number + 1;
    }
}
