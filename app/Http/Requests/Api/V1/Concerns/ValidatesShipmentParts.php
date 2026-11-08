<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Couriers\Spx\SpxFulfillment;
use App\Rules\PhPhone;
use Illuminate\Validation\Rule;

/**
 * The validation rules for the parts every courier request shares: addresses, parcel, payment, account choice.
 * Nothing is cast or cleaned up here: a value that is not already right is a 422 with a field error.
 */
trait ValidatesShipmentParts
{
    /** The largest amount decimal(12,2) holds. */
    private const MONEY_MAX = '9999999999.99';

    /** @return array<string, mixed> */
    protected function accountRules(): array
    {
        return [
            'account_id' => ['nullable', 'string', 'max:30', 'required_without:courier'],
            'courier' => ['nullable', 'string', 'max:16', 'required_without:account_id'],
        ];
    }

    /** @return array<string, mixed> */
    protected function pickupRules(string $prefix = 'pickup'): array
    {
        return [
            $prefix => ['required', 'array'],
            "{$prefix}.contact_name" => ['required', 'string', 'max:255'],
            "{$prefix}.contact_phone" => ['required', 'string', 'max:32', new PhPhone],
        ] + $this->addressRules($prefix);
    }

    /** @return array<string, mixed> */
    protected function recipientRules(string $prefix = 'recipient'): array
    {
        return [
            $prefix => ['required', 'array'],
            "{$prefix}.first_name" => ['required', 'string', 'max:255'],
            "{$prefix}.last_name" => ['nullable', 'string', 'max:255'],
            "{$prefix}.phone" => ['required', 'string', 'max:32', new PhPhone],
            "{$prefix}.email" => ['nullable', 'string', 'email', 'max:255'],
        ] + $this->addressRules($prefix);
    }

    /** @return array<string, mixed> */
    protected function parcelRules(): array
    {
        return [
            'parcel' => ['nullable', 'array'],
            'parcel.type' => ['nullable', 'string', 'max:64'],
            'parcel.category' => ['nullable', 'string', Rule::in(SpxFulfillment::PARCEL_ITEM_TYPES)],
            'parcel.weight_kg' => ['nullable', 'numeric', 'gt:0', 'max:1000'],
            'parcel.length_cm' => ['nullable', 'numeric', 'gt:0', 'max:1000'],
            'parcel.width_cm' => ['nullable', 'numeric', 'gt:0', 'max:1000'],
            'parcel.height_cm' => ['nullable', 'numeric', 'gt:0', 'max:1000'],
        ];
    }

    /**
     * payment.method decides whether anything is collected; cod_amount is the amount and is never inferred, so it is
     * required for cod and refused for prepaid (a prepaid parcel with an amount is a client bug about real money).
     *
     * @return array<string, mixed>
     */
    protected function paymentRules(): array
    {
        return [
            'payment' => ['required', 'array'],
            'payment.method' => ['required', 'string', 'in:cod,prepaid'],
            'payment.cod_amount' => ['required_if:payment.method,cod', 'prohibited_unless:payment.method,cod', 'nullable', ...$this->money(), 'gt:0'],
            'payment.declared_value' => ['nullable', ...$this->money(), 'min:0'],
            'payment.shipping_payment' => ['nullable', 'string', 'max:32'],
        ];
    }

    /** @return list<string> */
    protected function money(): array
    {
        return ['numeric', 'decimal:0,2', 'max:'.self::MONEY_MAX];
    }

    /** @return array<string, mixed> */
    protected function addressRules(string $prefix): array
    {
        return [
            "{$prefix}.address_1" => ['required', 'string', 'max:255'],
            "{$prefix}.address_2" => ['nullable', 'string', 'max:255'],
            "{$prefix}.barangay" => ['nullable', 'string', 'max:255'],
            "{$prefix}.district" => ['nullable', 'string', 'max:255'],
            "{$prefix}.city" => ['required', 'string', 'max:255'],
            "{$prefix}.state" => ['required', 'string', 'max:255'],
            "{$prefix}.postcode" => ['nullable', 'string', 'max:16'],
            "{$prefix}.country" => ['nullable', 'string', 'in:PH'],
            "{$prefix}.codes" => ['nullable', 'array'],
            "{$prefix}.codes.state" => ['nullable', 'string', 'max:32'],
            "{$prefix}.codes.city" => ['nullable', 'string', 'max:32'],
            "{$prefix}.codes.barangay" => ['nullable', 'string', 'max:32'],
        ];
    }
}
