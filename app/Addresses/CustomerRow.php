<?php

namespace App\Addresses;

use App\Models\Customer;

/**
 * A saved customer as the shipment form's customer search lists and fills it: only what the list shows and what the
 * form fills, never the email or the shipment counts.
 */
final class CustomerRow
{
    private const FILL = ['first_name', 'last_name', 'phone', 'address_1', 'address_2', 'barangay', 'district', 'city', 'state', 'postcode'];

    /** @return array<string, mixed> */
    public static function of(Customer $customer): array
    {
        $address = (array) $customer->address;
        $fill = [];
        foreach (self::FILL as $key) {
            $value = $address[$key] ?? null;
            $fill[$key] = is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
        }
        $fill['phone'] ??= $customer->phone;
        $codes = [];
        foreach (['state', 'city', 'barangay'] as $level) {
            $code = $address['codes'][$level] ?? null;
            if (is_scalar($code) && (string) $code !== '') {
                $codes[$level] = (string) $code;
            }
        }
        $fill['codes'] = (object) $codes;

        return [
            'public_id' => $customer->public_id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'summary' => implode(', ', array_filter([$fill['district'] ?? $fill['barangay'], $fill['city']])),
            'address' => $fill,
        ];
    }
}
