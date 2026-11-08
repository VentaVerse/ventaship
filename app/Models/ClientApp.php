<?php

namespace App\Models;

use App\Support\HasPublicId;
use App\Support\Vault\VaultEncrypted;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class ClientApp extends Model
{
    use HasApiTokens, HasFactory, HasPublicId;

    public const PUBLIC_PREFIX = 'app_';

    protected $guarded = ['id', 'public_id'];

    protected $hidden = ['webhook_secret'];

    protected function casts(): array
    {
        return ['webhook_secret' => VaultEncrypted::class, 'webhook_events' => 'array'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(OutgoingEvent::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
