<?php

namespace App\Models;

use App\Support\HasPublicId;
use App\Support\Vault\VaultEncrypted;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourierAccount extends Model
{
    use HasFactory, HasPublicId, SoftDeletes;

    public const PUBLIC_PREFIX = 'acc_';

    protected $guarded = ['id', 'public_id'];

    protected $hidden = ['webhook_token', 'webhook_token_hash', 'relay_secret'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'allow_live_booking' => 'boolean',
            'last_check_ok' => 'boolean',
            'last_check_at' => 'datetime',
            'webhook_token' => VaultEncrypted::class,
            'relay_secret' => VaultEncrypted::class,
        ];
    }

    public function settings(): HasMany
    {
        return $this->hasMany(CourierAccountSetting::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
