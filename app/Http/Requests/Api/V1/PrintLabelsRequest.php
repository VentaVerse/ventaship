<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** POST /labels: up to 50 shipment ids to print as one download. */
class PrintLabelsRequest extends FormRequest
{
    public const MAX_IDS = 50;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'shipment_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'shipment_ids.*' => ['required', 'string', 'regex:/^shp_[0-9a-z]{26}$/D', 'distinct'],
        ];
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_values($this->validated('shipment_ids'));
    }
}
