<?php

namespace App\Support;

/**
 * Validation messages for the admin forms: plain words, never an attribute name, since the field's own label is right
 * above its error.
 */
final class PlainMessages
{
    private const GENERIC = [
        'required_with' => 'Required.',
        'required' => 'Required.',
        'required_if' => 'Required.',
        'max' => 'Too long.',
        'string' => 'Not valid.',
        'in' => 'Not valid.',
        'regex' => 'Not valid.',
        'email' => 'Not a valid email.',
        'numeric' => 'Not a number.',
        'integer' => 'Not a whole number.',
        'decimal' => 'At most 2 decimals.',
        'gt' => 'Must be more than 0.',
        'min' => 'Too small.',
        'prohibited_unless' => 'Not valid.',
        'array' => 'Not valid.',
        'boolean' => 'Not valid.',
        'exists' => 'Not valid.',
    ];

    /**
     * `$specific` first (Laravel takes the first pattern that matches), then the generic messages repeated for
     * nested keys, since a `*` matches one segment only.
     *
     * @param  array<string, string>  $specific
     * @return array<string, string>
     */
    public static function with(array $specific = []): array
    {
        $nested = [];
        foreach (['*.', '*.*.', '*.*.*.'] as $depth) {
            foreach (self::GENERIC as $rule => $message) {
                $nested[$depth.$rule] = $message;
            }
        }

        return $specific + $nested;
    }
}
