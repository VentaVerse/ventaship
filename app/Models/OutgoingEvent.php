<?php

namespace App\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutgoingEvent extends Model
{
    use HasPublicId;

    public const PUBLIC_PREFIX = 'evt_';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_attempt_at' => 'datetime',
            'window_started_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function clientApp(): BelongsTo
    {
        return $this->belongsTo(ClientApp::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(OutgoingAttempt::class);
    }
}
