<?php

namespace App\Http\Requests\Ui;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Settings → Account → Password. Its errors go to the `password` bag, so they land under the Password card's
 * fields and not under the Profile card's own Current password field.
 */
class UpdatePasswordRequest extends FormRequest
{
    public const BAG = 'password';

    protected $errorBag = self::BAG;

    /** Never flashed back as old input. */
    protected $dontFlash = ['current_password', 'password', 'password_confirmation'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Required.',
            'current_password.string' => 'Current password is wrong.',
            'password.required' => 'Required.',
            'password.string' => 'Required.',
            'password.min' => 'At least 12 characters.',
            'password.max' => 'Too long.',
            'password.confirmed' => "Passwords don't match.",
        ];
    }
}
