<?php

namespace App\Couriers\TrackingNumbers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One number issued from a tracking number series, and what became of it.
 */
class TrackingNumber extends Model
{
    /** Handed to a request that has not answered yet. */
    public const RESERVED = 'reserved';

    /** Used for good: the courier saved it, or may have. */
    public const SPENT = 'spent';

    /** The courier definitely rejected the request; the number is issued again. */
    public const RELEASED = 'released';

    protected $table = 'courier_tracking_numbers';

    protected $fillable = ['series_id', 'number', 'status', 'order_id', 'outcome'];

    protected $casts = [
        'series_id' => 'integer',
        'number' => 'integer',
        'order_id' => 'integer',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(TrackingNumberSeries::class, 'series_id');
    }
}
