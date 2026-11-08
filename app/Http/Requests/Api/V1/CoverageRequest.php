<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesShipmentParts;
use Illuminate\Foundation\Http\FormRequest;

/** POST /coverage: up to 10 destinations from one pickup point. */
class CoverageRequest extends FormRequest
{
    use ValidatesShipmentParts;

    public const MAX_DESTINATIONS = 10;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->accountRules() + $this->addressRules('pickup') + [
            'pickup' => ['required', 'array'],
            'destinations' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_DESTINATIONS],
            'destinations.*' => ['required', 'array'],
            'destinations.*.ref' => ['required', 'string', 'max:64', 'distinct'],
            'destinations.*.recipient' => ['required', 'array'],
            'payment' => ['nullable', 'array'],
            'payment.method' => ['nullable', 'string', 'in:cod,prepaid'],
        ] + $this->addressRules('destinations.*.recipient');
    }
}
