<?php

namespace App\Couriers\TrackingNumbers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One block of tracking numbers a courier assigned to the store. Read and
 * written only through TrackingNumberSeriesService, which holds the lock.
 */
class TrackingNumberSeries extends Model
{
    protected $table = 'courier_tracking_series';

    protected $fillable = ['courier', 'first_number', 'last_number', 'last_issued_number'];

    protected $casts = [
        'first_number' => 'integer',
        'last_number' => 'integer',
        'last_issued_number' => 'integer',
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
