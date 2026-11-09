<?php

namespace App\Addresses;

use App\Models\Customer;
use App\Models\Shipment;
use App\Shipments\ShipmentCreator;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

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
        } catch (Throwable $e) {
            if (! $this->lostRace($e)) {
                throw $e;
            }

            return $this->upsert($address, $count); // a concurrent first save of the same phone: it exists now
        }
    }

    /**
     * A shipment sent to a customer picked on the form: its last shipped time moves on, and with `$count` (a new
     * shipment, not an edited one) its count goes up. The saved name and address stay as they are.
     */
    public function recordShipment(Customer $customer, bool $count = true): void
    {
        Customer::query()->whereKey($customer->getKey())->update(array_filter([
            'shipments_count' => $count ? DB::raw('shipments_count + 1') : null,
            'last_shipped_at' => now(),
        ]));
    }

    /** @return Collection<int, Customer> name or phone matches, most recently shipped first */
    public function search(string $q, int $limit = 8): Collection
    {
        $q = trim($q);
        if (mb_strlen($q) < self::SEARCH_MIN) {
            return new Collection;
        }

        return $this->matching(Customer::query(), $q)
            ->limit(max(1, min($limit, 8)))
            ->get();
    }

    /**
     * Narrows a customer query to the name or phone matches for `$q` (the phone in any written form), newest first.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function matching(Builder $query, string $q): Builder
    {
        $q = trim($q);
        $like = '%'.addcslashes($q, '!%_').'%';
        $digits = preg_replace('/\D+/', '', $q);
        if (strlen($digits) > 2 && str_starts_with($digits, '63')) {
            $digits = '0'.substr($digits, 2);
        }

        // A one-character search (the Addresses list allows it) still reaches the phone when that character is a digit.
        $minDigits = min(self::SEARCH_MIN, mb_strlen($q));

        return $query
            ->where(function ($w) use ($like, $digits, $minDigits) {
                $w->whereRaw("name like ? escape '!'", [$like]);
                if ($digits !== '' && strlen($digits) >= $minDigits) {
                    $w->orWhere('phone', 'like', '%'.$digits.'%');
                }
            })
            ->orderByDesc('last_shipped_at')->orderByDesc('id');
    }

    /**
     * A customer added by hand: no shipments yet, never shipped.
     *
     * @param  array<string, mixed>  $data  the shipment `recipient` shape
     *
     * @throws ValidationException `phone`: not a PH mobile number, or already another customer's
     */
    public function create(array $data): Customer
    {
        return $this->save(new Customer(['shipments_count' => 0]), $data);
    }

    /**
     * @param  array<string, mixed>  $data  the shipment `recipient` shape
     *
     * @throws ValidationException `phone`: not a PH mobile number, or already another customer's
     */
    public function update(Customer $customer, array $data): Customer
    {
        return $this->save($customer, $data);
    }

    /** @param  array<string, mixed>  $data */
    private function save(Customer $customer, array $data): Customer
    {
        $address = self::address($data);
        if ($address['phone'] === null) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid phone.']);
        }
        $taken = Customer::query()->where('phone', $address['phone'])
            ->when($customer->exists, fn ($q) => $q->whereKeyNot($customer->getKey()));
        if ($taken->exists()) {
            throw ValidationException::withMessages(['phone' => 'Another customer has this phone.']);
        }

        try {
            $customer->forceFill(['phone' => $address['phone'], 'name' => self::name($address), 'address' => $address])->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => 'Another customer has this phone.']);
        }

        return $customer;
    }

    public function delete(Customer $customer): void
    {
        $customer->delete();
    }

    /** A concurrent first save of the same phone (unique violation), or a MySQL deadlock on the gap lock. */
    private function lostRace(Throwable $e): bool
    {
        return $e instanceof UniqueConstraintViolationException
            || $e instanceof DeadlockException
            || ($e instanceof QueryException && app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e));
    }

    /** @param  array<string, mixed>  $address */
    protected function upsert(array $address, bool $count): Customer
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
