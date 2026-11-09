<?php

namespace App\Addresses;

use App\Models\Customer;
use App\Models\Shipment;
use App\Shipments\ShipmentCreator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Recipients saved as customers, one per phone (normalised as the couriers get it, so "0917 123 4567",
 * "+63 917 123 4567" and "9171234567" are one customer). The latest shipment's name and address win.
 */
class Customers
{
    private const FIELDS = ['first_name', 'last_name', 'phone', 'email', 'address_1', 'address_2', 'barangay', 'district', 'city', 'state', 'postcode'];

    public const SEARCH_MIN = 2;

    /**
     * Saves the shipment's recipient. `$count` adds the shipment to the customer's count (false when an edited
     * draft is saved again). A recipient without a PH mobile number is not saved.
     */
    public function upsertFromShipment(Shipment $shipment, bool $count = true): ?Customer
    {
        $address = self::address((array) $shipment->recipient);
        if ($address['phone'] === null) {
            return null;
        }

        try {
            return $this->upsert($address, $count);
        } catch (UniqueConstraintViolationException) {
            return $this->upsert($address, $count); // a concurrent first save of the same phone: it exists now
        }
    }

    /** @return Collection<int, Customer> name or phone matches, most recently shipped first */
    public function search(string $q, int $limit = 8): Collection
    {
        $q = trim($q);
        if (mb_strlen($q) < self::SEARCH_MIN) {
            return new Collection;
        }

        $like = '%'.addcslashes($q, '!%_').'%';
        $digits = preg_replace('/\D+/', '', $q);
        if (strlen($digits) > 2 && str_starts_with($digits, '63')) {
            $digits = '0'.substr($digits, 2);
        }

        return Customer::query()
            ->where(function ($w) use ($like, $digits) {
                $w->whereRaw("name like ? escape '!'", [$like]);
                if (strlen($digits) >= self::SEARCH_MIN) {
                    $w->orWhere('phone', 'like', '%'.$digits.'%');
                }
            })
            ->orderByDesc('last_shipped_at')->orderByDesc('id')
            ->limit(max(1, min($limit, 8)))
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data  the shipment `recipient` shape
     *
     * @throws InvalidArgumentException a phone that is not a PH mobile number
     */
    public function update(Customer $customer, array $data): Customer
    {
        $address = self::address($data);
        if ($address['phone'] === null) {
            throw new InvalidArgumentException('Not a valid PH mobile number.');
        }

        $customer->forceFill(['phone' => $address['phone'], 'name' => self::name($address), 'address' => $address])->save();

        return $customer;
    }

    public function delete(Customer $customer): void
    {
        $customer->delete();
    }

    /** @param  array<string, mixed>  $address */
    private function upsert(array $address, bool $count): Customer
    {
        return DB::transaction(function () use ($address, $count) {
            $customer = Customer::query()->where('phone', $address['phone'])->lockForUpdate()->first() ?? new Customer(['shipments_count' => 0]);
            $isNew = ! $customer->exists;
            $customer->forceFill([
                'phone' => $address['phone'],
                'name' => self::name($address),
                'address' => $address,
                'shipments_count' => $customer->shipments_count + ($count || $isNew ? 1 : 0),
                'last_shipped_at' => now(),
            ])->save();

            return $customer;
        });
    }

    /** @param  array<string, mixed>  $address */
    private static function name(array $address): string
    {
        $name = trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? ''));

        return $name !== '' ? $name : (string) $address['phone'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> the shipment `recipient` shape, phone normalised (null when not a PH mobile)
     */
    private static function address(array $data): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = is_scalar($data[$field] ?? null) ? trim((string) $data[$field]) : '';
            $out[$field] = $value === '' ? null : $value;
        }
        $out['phone'] = ShipmentCreator::localPhone($out['phone']);
        $out['country'] = 'PH';
        $out['codes'] = [];
        foreach (['state', 'city', 'barangay'] as $level) {
            $code = data_get($data, "codes.{$level}");
            if (is_scalar($code) && trim((string) $code) !== '') {
                $out['codes'][$level] = trim((string) $code);
            }
        }

        return $out;
    }
}
