<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookReceipt extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['headers' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    public function courierAccount(): BelongsTo
    {
        return $this->belongsTo(CourierAccount::class);
    }
}
