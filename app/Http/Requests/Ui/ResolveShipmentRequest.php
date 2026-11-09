<?php

namespace App\Http\Requests\Ui;

use App\Shipments\BookingOutcomes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The detail page's resolution of an `unknown` booking: `booked` with the tracking number the courier portal shows, or
 * `not_booked`. The tracking number follows the same rule as `ventaship:shipments-resolve --tracking=`.
 */
class ResolveShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $tracking = $this->input('tracking');
        if (is_string($tracking)) {
            $this->merge(['tracking' => trim($tracking)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', Rule::in(['booked', 'not_booked'])],
            'tracking' => ['exclude_unless:outcome,booked', 'required', 'string', 'regex:'.BookingOutcomes::TRACKING_PATTERN],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tracking.required' => 'Enter the tracking number.',
            'tracking.string' => 'Enter the tracking number.',
            'tracking.regex' => 'Use letters, digits, - and _ only, 3 to 64 characters.',
            'outcome.*' => 'Choose Courier has it or Not booked.',
        ];
    }
}
