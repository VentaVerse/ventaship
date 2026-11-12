<?php

namespace App\Http\Requests\Ui;

use App\Couriers\CourierAccounts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The courier account page's Save and Save and test. Keys and values are checked further by CourierAccounts (each
 * courier's schema). A refusal never flashes secrets[] (bootstrap/app.php dontFlash).
 */
class SaveCourierAccountRequest extends FormRequest
{
    /** Long enough for a pretty-printed JSON setting; it is stored compact and checked against the stored limit. */
    public const MAX_SETTING_INPUT = 16384;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(CourierAccounts::ENVIRONMENTS)],
            'settings' => ['nullable', 'array'],
            'settings.*' => ['nullable', 'string', 'max:'.self::MAX_SETTING_INPUT],
            'secrets' => ['nullable', 'array'],
            'secrets.*' => ['nullable', 'string', 'max:'.CourierAccounts::MAX_VALUE_LENGTH],
            'series' => ['nullable', 'array'],
            'series.first' => ['nullable', 'string', 'max:20', 'required_with:series.last'],
            'series.last' => ['nullable', 'string', 'max:20', 'required_with:series.first'],
            'after' => ['nullable', 'string', Rule::in(['test'])],
        ];
    }

    public function wantsTest(): bool
    {
        return $this->validated('after') === 'test';
    }
}
