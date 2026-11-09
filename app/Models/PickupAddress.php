<?php

namespace App\Models;

use App\Support\HasPublicId;
use App\Support\Vault\VaultEncryptedJson;
use Illuminate\Database\Eloquent\Model;

/** A saved pickup address. Change it through App\Addresses\PickupAddresses, which keeps exactly one default. */
class PickupAddress extends Model
{
    use HasPublicId;

    public const PUBLIC_PREFIX = 'pad_';

    /** The keys of `address`, in the shipment `pickup` shape. */
    public const FIELDS = ['contact_name', 'contact_phone', 'address_1', 'address_2', 'barangay', 'district', 'city', 'state', 'postcode'];

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'address' => VaultEncryptedJson::class];
    }

    /** @return array<string, mixed> A copy in the shipment `pickup` shape, ready to snapshot onto a shipment. */
    public function toPickup(): array
    {
        $address = (array) $this->address;
        $out = [];
        foreach (self::FIELDS as $field) {
            $out[$field] = $address[$field] ?? null;
        }

        return $out + ['country' => $address['country'] ?? 'PH', 'codes' => (array) ($address['codes'] ?? [])];
    }
}
