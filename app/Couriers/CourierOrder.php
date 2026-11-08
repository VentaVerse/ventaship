<?php

namespace App\Couriers;

use App\Models\CourierAccount;
use App\Models\Shipment;
use Closure;
use Illuminate\Support\Collection;

/**
 * One shipment as the courier code reads it: a read-only object exposing the property names the ported courier code
 * read from VentaCart's Order model (`shipping_first_name` ... `shipping_postcode`, `phone`, `total`,
 * `courier_comment`, `products`), plus `cod` and `reference`, the id the courier is given for the parcel
 * (`shipments.courier_ref`, e.g. "VS000123").
 *
 * The courier_* fields (`courier_meta` as a JSON string, `courier_tracking_number`, `courier_status`,
 * `courier_provider`) are the booking's, once there is one.
 *
 * @property-read int $id
 * @property-read string|null $reference
 * @property-read float $total
 * @property-read float|null $subtotal
 * @property-read bool $cod
 * @property-read float|null $cod_amount
 * @property-read float|null $declared_value
 * @property-read float|null $weight_kg
 * @property-read string|null $shipping_first_name
 * @property-read string|null $shipping_last_name
 * @property-read string|null $shipping_phone
 * @property-read string|null $shipping_address_1
 * @property-read string|null $shipping_barangay
 * @property-read string|null $shipping_barangay_code
 * @property-read string|null $shipping_district
 * @property-read string|null $shipping_city
 * @property-read string|null $shipping_city_code
 * @property-read string|null $shipping_state
 * @property-read string|null $shipping_state_code
 * @property-read string|null $shipping_postcode
 * @property-read string|null $shipping_country
 * @property-read string|null $phone
 * @property-read string|null $courier_comment
 * @property-read Collection<int, object{name: string, quantity: int, price: float, weight_kg: float|null}> $products
 * @property-read string|null $courier_meta
 * @property-read string|null $courier_tracking_number
 * @property-read string|null $courier_status
 * @property-read string|null $courier_provider
 */
final class CourierOrder
{
    /** The literal statuses a cancel stamps on a booking, read as the cancelled phase by every courier. */
    public const CANCELED_STATUSES = ['canceled', 'cancelled'];

    /** @var (Closure(string, string, ?CourierAccount): ?self)|null */
    private static ?Closure $trackingLookup = null;

    /** @param  array<string, mixed>  $attributes */
    private function __construct(private array $attributes) {}

    /**
     * @param  array<string, mixed>  $attributes  the property names above; `products` may be a list of arrays
     */
    public static function make(array $attributes): self
    {
        $lines = $attributes['products'] ?? [];
        $attributes['products'] = collect($lines instanceof Collection ? $lines->all() : (array) $lines)
            ->map(fn ($line) => (object) [
                'name' => (string) (data_get($line, 'name') ?? ''),
                'quantity' => (int) (data_get($line, 'quantity') ?? 1),
                'price' => (float) (data_get($line, 'price') ?? 0),
                'weight_kg' => is_numeric(data_get($line, 'weight_kg')) ? (float) data_get($line, 'weight_kg') : null,
            ])
            ->values();

        $meta = $attributes['courier_meta'] ?? null;
        if (is_array($meta)) {
            $attributes['courier_meta'] = json_encode($meta);
        }

        $attributes['id'] = (int) ($attributes['id'] ?? 0);
        $attributes['total'] = (float) ($attributes['total'] ?? 0);
        $attributes['cod'] = (bool) ($attributes['cod'] ?? false);

        return new self($attributes);
    }

    /** From a stored shipment: its recipient, items, payment and, once booked, its courier_* fields. */
    public static function fromApi(Shipment $s): self
    {
        $to = is_array($s->recipient) ? $s->recipient : [];
        $codes = is_array($to['codes'] ?? null) ? $to['codes'] : [];
        $first = $to['first_name'] ?? null;
        $last = $to['last_name'] ?? null;
        if ($first === null && $last === null) {
            $first = $to['name'] ?? null;
        }
        $cod = $s->payment_method === 'cod';
        $weight = data_get($s->options, 'parcel.weight_kg');

        return self::make([
            'id' => $s->getKey(),
            'reference' => $s->courier_ref,
            'total' => $s->total ?? $s->declared_value ?? $s->cod_amount ?? 0,
            'subtotal' => $s->subtotal !== null ? (float) $s->subtotal : null,
            'cod' => $cod,
            'cod_amount' => $cod && $s->cod_amount !== null ? (float) $s->cod_amount : null,
            'declared_value' => $s->declared_value !== null ? (float) $s->declared_value : null,
            'weight_kg' => is_numeric($weight) ? (float) $weight : null,
            'shipping_first_name' => $first,
            'shipping_last_name' => $last,
            'shipping_phone' => $to['phone'] ?? null,
            'shipping_address_1' => $to['address_1'] ?? null,
            'shipping_barangay' => $to['barangay'] ?? null,
            'shipping_barangay_code' => $codes['barangay'] ?? null,
            'shipping_district' => $to['district'] ?? null,
            'shipping_city' => $to['city'] ?? null,
            'shipping_city_code' => $codes['city'] ?? null,
            'shipping_state' => $to['state'] ?? null,
            'shipping_state_code' => $codes['state'] ?? null,
            'shipping_postcode' => $to['postcode'] ?? null,
            'shipping_country' => $to['country'] ?? null,
            'phone' => null,
            'courier_comment' => $s->courier_note,
            'products' => is_array($s->items) ? $s->items : [],
            'courier_meta' => $s->meta,
            'courier_tracking_number' => $s->tracking_number,
            'courier_status' => $s->courier_status,
            'courier_provider' => $s->courier,
        ]);
    }

    /**
     * What the courier collects from the recipient. The shipment's payment method alone decides whether there is
     * a collection, and `cod_amount` alone is the amount: never `total`, `declared_value` or the items.
     *
     * 0.0 on a prepaid shipment. Null on a COD shipment with no positive `cod_amount`: that shipment cannot be
     * booked, because guessing the amount a rider hands over is real money either way.
     */
    public function collectAmount(): ?float
    {
        if (! $this->cod) {
            return 0.0;
        }

        $amount = $this->attributes['cod_amount'] ?? null;

        return is_numeric($amount) && (float) $amount > 0 ? round((float) $amount, 2) : null;
    }

    /**
     * Why this shipment cannot be booked for its payment, or null when it can: a COD shipment needs a positive
     * cod_amount, and a caller's `buyer_payment` may not contradict the shipment's own payment method.
     *
     * @param  array<string, mixed>  $opts
     */
    public function paymentProblem(array $opts = []): ?string
    {
        $said = $opts['buyer_payment'] ?? null;
        if ($said !== null && $said !== '' && ($said === 'cod') !== $this->cod) {
            return 'The booking says ' . ($said === 'cod' ? 'cash on delivery' : 'prepaid') . ' but the shipment is '
                . ($this->cod ? 'cash on delivery' : 'prepaid') . '.';
        }

        if ($this->collectAmount() === null) {
            return 'A cash-on-delivery shipment needs a cod_amount above zero.';
        }

        return null;
    }

    /** A copy carrying a booking's courier_* fields. */
    public function withBooking(array $booking): self
    {
        $copy = self::make(array_merge($this->attributes, $booking));

        return $copy;
    }

    /**
     * The shipment booked on this account under this tracking number, for the courier calls that start from a
     * tracking number alone (printing a label, refusing a late cancel). CourierServiceProvider registers the lookup
     * against `shipments`, scoped to the account: with no saved account, nothing is found.
     */
    public static function forTracking(string $courierKey, string $trackingNumber, ?CourierAccount $account): ?self
    {
        return self::$trackingLookup ? (self::$trackingLookup)($courierKey, $trackingNumber, $account) : null;
    }

    /** @param  (Closure(string, string, ?CourierAccount): ?self)|null  $lookup */
    public static function resolveTrackingUsing(?Closure $lookup): void
    {
        self::$trackingLookup = $lookup;
    }

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['products' => $this->attributes['products']->map(fn ($l) => (array) $l)->all()] + $this->attributes;
    }
}
