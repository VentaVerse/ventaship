<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesShipmentParts;
use Illuminate\Foundation\Http\FormRequest;

/** POST /quotes: the fee one account would charge for one parcel. */
class QuoteRequest extends FormRequest
{
    use ValidatesShipmentParts;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->accountRules() + $this->addressRules('pickup') + [
            'pickup' => ['required', 'array'],
            'recipient' => ['required', 'array'],
            'service' => ['nullable', 'string', 'max:64'],
            'insure' => ['sometimes', 'boolean'],
        ] + $this->addressRules('recipient') + $this->parcelRules() + $this->paymentRules();
    }
}
