<?php

namespace App\Rules;

use App\Shipments\ShipmentCreator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The admin UI's phone rule: exactly a PH mobile, +63 9XXXXXXXXX. Stored as "09XXXXXXXXX" (ShipmentCreator::localPhone).
 * The public API uses it too, with its own message.
 */
class PhMobile implements ValidationRule
{
    public const MESSAGE = 'Enter the 10-digit mobile number.';

    /** The canonical stored form, or null when the value is not a PH mobile. */
    public static function canonical(mixed $raw): ?string
    {
        $phone = is_string($raw) ? ShipmentCreator::localPhone($raw) : null;

        return $phone !== null && preg_match('/^09\d{9}$/', $phone) === 1 ? $phone : null;
    }

    /** The 10 digits after +63, for prefilling the field; whatever digits survive when it is not a full number. */
    public static function national(mixed $raw): string
    {
        $digits = preg_replace('/\D+/', '', is_scalar($raw) ? (string) $raw : '');
        if (str_starts_with($digits, '63')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return substr($digits, 0, 10);
    }

    public function __construct(private readonly string $message = self::MESSAGE) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::canonical($value) === null) {
            $fail($this->message);
        }
    }
}
