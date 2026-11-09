<?php

namespace App\Addresses;

use App\Models\PickupAddress;
use App\Shipments\ShipmentCreator;
use Illuminate\Support\Facades\DB;

/**
 * The saved pickup addresses. While any exist, exactly one is the default: the first one saved becomes it, and
 * deleting the default promotes the oldest remaining address. Input is the shipment `pickup` shape plus `label`
 * (and an optional `is_default`); validation is the caller's job.
 */
final class PickupAddresses
{
    public function default(): ?PickupAddress
    {
        return PickupAddress::query()->where('is_default', true)->orderBy('id')->first();
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): PickupAddress
    {
        return DB::transaction(function () use ($data) {
            $hasDefault = PickupAddress::query()->where('is_default', true)->lockForUpdate()->exists();
            $address = PickupAddress::query()->create([
                'label' => trim((string) ($data['label'] ?? '')),
                'is_default' => ! $hasDefault,
                'address' => self::address($data),
            ]);

            if ($hasDefault && filter_var($data['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $this->makeDefault($address);
            }

            return $address;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(PickupAddress $address, array $data): PickupAddress
    {
        $address->forceFill(['label' => trim((string) ($data['label'] ?? $address->label)), 'address' => self::address($data)])->save();

        return $address;
    }

    public function makeDefault(PickupAddress $address): void
    {
        DB::transaction(function () use ($address) {
            PickupAddress::query()->lockForUpdate()->get(['id']);
            PickupAddress::query()->whereKeyNot($address->getKey())->where('is_default', true)->update(['is_default' => false]);
            PickupAddress::query()->whereKey($address->getKey())->update(['is_default' => true]);
            $address->is_default = true;
            $address->syncOriginalAttribute('is_default');
        });
    }

    public function delete(PickupAddress $address): void
    {
        DB::transaction(function () use ($address) {
            PickupAddress::query()->lockForUpdate()->get(['id']);
            $address->delete();
            if (! PickupAddress::query()->where('is_default', true)->exists()) {
                PickupAddress::query()->orderBy('id')->first()?->forceFill(['is_default' => true])->save();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> the stored address, in the shipment `pickup` shape
     */
    public static function address(array $data): array
    {
        $out = [];
        foreach (PickupAddress::FIELDS as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            $out[$field] = $value === '' ? null : $value;
        }
        $out['contact_phone'] = ShipmentCreator::localPhone($out['contact_phone']) ?? $out['contact_phone'];
        $out['country'] = 'PH';
        $out['codes'] = [];
        foreach (['state', 'city', 'barangay'] as $level) {
            $code = trim((string) data_get($data, "codes.{$level}", ''));
            if ($code !== '') {
                $out['codes'][$level] = $code;
            }
        }

        return $out;
    }
}
