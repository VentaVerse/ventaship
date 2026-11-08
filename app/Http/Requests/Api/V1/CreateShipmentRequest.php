<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesShipmentParts;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** POST /shipments (base plan §2 "Create a shipment"). Authorization is the signed request itself. */
class CreateShipmentRequest extends FormRequest
{
    use ValidatesShipmentParts;

    private const METADATA_MAX_KEYS = 20;

    private const METADATA_MAX_BYTES = 4096;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = ['nullable', ...$this->money(), 'min:0'];

        return $this->accountRules() + [
            'client_reference' => ['required', 'string', 'max:64', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'order_label' => ['nullable', 'string', 'max:64'],
            'submit' => ['sometimes', 'boolean'],
            'method' => ['nullable', 'string', 'in:pickup,dropoff'],
            'pickup_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'pickup_slot' => ['nullable', 'string', 'max:255'],
            'service' => ['nullable', 'string', 'max:64'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.price' => $money,
            'items.*.weight_kg' => ['nullable', 'numeric', 'gt:0', 'max:1000'],
            'total' => $money,
            'subtotal' => $money,
            'note' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array', 'max:'.self::METADATA_MAX_KEYS, $this->flatMetadata()],
        ] + $this->parcelRules() + $this->paymentRules() + $this->pickupRules() + $this->recipientRules();
    }

    /** Metadata is echoed back on every answer and webhook: flat string keys, scalar values, small. */
    private function flatMetadata(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $item) {
                if (! is_string($key) || $key === '' || strlen($key) > 40 || ! (is_scalar($item) || $item === null)) {
                    $fail('The :attribute must be an object of up to 40-character keys with string, number, boolean or null values.');

                    return;
                }
            }
            if (strlen((string) json_encode($value)) > self::METADATA_MAX_BYTES) {
                $fail('The :attribute may not be larger than '.self::METADATA_MAX_BYTES.' bytes.');
            }
        };
    }
}
