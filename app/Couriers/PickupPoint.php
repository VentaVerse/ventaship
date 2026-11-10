<?php

namespace App\Couriers;

/**
 * Where a parcel leaves from, as the client sent it with the shipment (`pickup` in the API body). Carries the
 * property names the ported courier code read from VentaCart's pickup address record, so the adapters read it
 * unchanged. The PSGC codes come from `codes.{state,city,barangay}` when the client sends them.
 */
final readonly class PickupPoint
{
    public function __construct(
        public ?string $contact_name,
        public ?string $contact_phone,
        public ?string $address_1,
        public ?string $barangay,
        public ?string $barangay_code,
        public ?string $district,
        public ?string $city,
        public ?string $city_code,
        public ?string $state,
        public ?string $state_code,
        public ?string $postcode,
        public string $country,
        public ?string $address_2 = null,
    ) {}

    /** @param  array<string, mixed>  $pickup */
    public static function fromArray(array $pickup): self
    {
        $codes = is_array($pickup['codes'] ?? null) ? $pickup['codes'] : [];

        return new self(
            contact_name: self::text($pickup['contact_name'] ?? null),
            contact_phone: self::text($pickup['contact_phone'] ?? null),
            address_1: self::text($pickup['address_1'] ?? null),
            barangay: self::text($pickup['barangay'] ?? null),
            barangay_code: self::text($codes['barangay'] ?? $pickup['barangay_code'] ?? null),
            district: self::text($pickup['district'] ?? null),
            city: self::text($pickup['city'] ?? null),
            city_code: self::text($codes['city'] ?? $pickup['city_code'] ?? null),
            state: self::text($pickup['state'] ?? null),
            state_code: self::text($codes['state'] ?? $pickup['state_code'] ?? null),
            postcode: self::text($pickup['postcode'] ?? null),
            country: strtoupper(self::text($pickup['country'] ?? null) ?? PickupOrigin::COUNTRY),
            address_2: self::text($pickup['address_2'] ?? null),
        );
    }

    /** A scalar as a trimmed string; anything else, or blank, as null. */
    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
