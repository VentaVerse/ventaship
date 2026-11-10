<?php

namespace App\Http\Requests\Ui;

use Illuminate\Foundation\Http\FormRequest;

/** Settings → General. The reference prefix is taken in any case and stored in capitals. */
class SaveGeneralSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $prefix = $this->input('shipments_reference_prefix');
        if (is_string($prefix)) {
            $this->merge(['shipments_reference_prefix' => strtoupper(trim($prefix))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'shipments_reference_prefix' => ['required', 'string', 'regex:/^[A-Z0-9]{1,8}\z/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'shipments_reference_prefix.*' => 'Use 1–8 letters or digits.',
        ];
    }
}
