<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesShipmentParts;
use Illuminate\Foundation\Http\FormRequest;

/** POST /pickup-slots: when one account can collect from this pickup point. */
class PickupSlotsRequest extends FormRequest
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
        ] + $this->parcelRules();
    }
}
