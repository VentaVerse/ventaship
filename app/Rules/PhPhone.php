<?php

namespace App\Rules;

use App\Couriers\Support\PhPhone as PhPhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** The port's one PH mobile rule, so a number that passes is one every courier payload accepts. */
class PhPhone implements ValidationRule
{
    public function __construct(private readonly string $message = 'The :attribute must be a Philippine mobile number, such as 09171234567.') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || PhPhoneNumber::normalize($value) === null) {
            $fail($this->message);
        }
    }
}
