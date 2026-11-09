<?php

namespace App\Models;

use App\Support\HasPublicId;
use App\Support\Vault\VaultEncryptedJson;
use Illuminate\Database\Eloquent\Model;

/**
 * A recipient saved from the shipments sent to them, keyed by phone. `name` and `phone` stay plain so they can be
 * searched; the address (shipment `recipient` shape) is vault-encrypted. Written by App\Addresses\Customers.
 */
class Customer extends Model
{
    use HasPublicId;

    public const PUBLIC_PREFIX = 'cus_';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['address' => VaultEncryptedJson::class, 'shipments_count' => 'integer', 'last_shipped_at' => 'datetime'];
    }
}
