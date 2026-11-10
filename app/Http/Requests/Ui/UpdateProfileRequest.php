<?php

namespace App\Http\Requests\Ui;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Settings → Account → Profile: name and email. A changed email also needs the current password, which the
 * controller checks (throttled) after these rules pass. Password fields posted here are ignored.
 */
class UpdateProfileRequest extends FormRequest
{
    /** Never flashed back as old input. */
    protected $dontFlash = ['current_password', 'password', 'password_confirmation'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('admins', 'email')->ignore($this->user()?->getAuthIdentifier())],
            'current_password' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Required.',
            'name.string' => 'Required.',
            'name.max' => 'Too long.',
            'email.required' => 'Required.',
            'email.string' => 'Not a valid email.',
            'email.email' => 'Not a valid email.',
            'email.max' => 'Too long.',
            'email.unique' => 'Email already in use.',
            'current_password.string' => 'Current password is wrong.',
        ];
    }
}
