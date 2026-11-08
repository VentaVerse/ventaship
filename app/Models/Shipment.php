<?php

namespace App\Models;

use App\Support\HasPublicId;
use App\Support\Vault\VaultEncrypted;
use App\Support\Vault\VaultEncryptedJson;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    use HasFactory, HasPublicId;

    public const PUBLIC_PREFIX = 'shp_';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'items' => 'array',
            'meta' => 'array',
            'metadata' => 'array',
            'pickup' => VaultEncryptedJson::class,
            'recipient' => VaultEncryptedJson::class,
            'courier_note' => VaultEncrypted::class,
            'needs_attention' => 'boolean',
            'cod_amount' => 'decimal:2',
            'declared_value' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'actual_fee' => 'decimal:2',
            'remitted_at' => 'datetime',
            'booking_started_at' => 'datetime',
            'booked_at' => 'datetime',
            'terminal_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'next_poll_at' => 'datetime',
            'last_event_at' => 'datetime',
            'pii_purged_at' => 'datetime',
        ];
    }

    public function clientApp(): BelongsTo
    {
        return $this->belongsTo(ClientApp::class);
    }

    /** Soft-deleted accounts included: a shipment keeps rendering the account it was booked with. */
    public function courierAccount(): BelongsTo
    {
        return $this->belongsTo(CourierAccount::class)->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class);
    }

    public function labels(): HasMany
    {
        return $this->hasMany(ShipmentLabel::class);
    }
}
