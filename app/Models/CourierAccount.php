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

    /** @var array<string, mixed> a new account starts in the sandbox */
    protected $attributes = ['mode' => 'sandbox'];

    /** A deleted account (soft or hard) gives up its default slot, so another account can take it. */
    protected static function booted(): void
    {
        static::deleting(function (CourierAccount $account) {
            if ($account->default_slot !== null || $account->is_default) {
                $account->forceFill(['default_slot' => null, 'is_default' => false])->saveQuietly();
            }
        });
    }

    /** The one "is this account switched on" predicate; any other status is inactive. */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
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
