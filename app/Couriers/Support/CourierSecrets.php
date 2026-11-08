<?php

namespace App\Couriers\Support;

use App\Couriers\Contracts\Courier;

/**
 * A courier's encrypted settings fields (the schema's 'encrypted' => true
 * entries), read in one place so the settings page, its save and its
 * reveal endpoint always agree on which keys are secrets.
 */
final class CourierSecrets
{
    /**
     * The longest value accepted for one secret. Generous on purpose:
     * providers issue keys, tokens and signing secrets of very different
     * shapes, and the format itself is never checked.
     */
    public const MAX_LENGTH = 2048;

    /** A value shorter than this shows bullets only, never its last four. */
    private const MIN_LENGTH_FOR_TAIL = 8;

    /**
     * The courier's encrypted fields, keyed by setting key.
     *
     * @return array<string, array>
     */
    public static function fields(Courier $courier): array
    {
        $fields = [];
        foreach ($courier->settingsSchema() as $field) {
            if (!empty($field['encrypted'])) {
                $fields[$field['key']] = $field;
            }
        }

        return $fields;
    }

    /** @return list<string> */
    public static function keys(Courier $courier): array
    {
        return array_keys(self::fields($courier));
    }

    /**
     * The masked form of a decrypted value: sixteen bullets plus its last
     * four characters, so an admin can tell which key is saved without the
     * page ever carrying it. Short values get no tail, since four
     * characters of a short value give most of it away.
     */
    public static function mask(string $value): string
    {
        $bullets = str_repeat("\u{2022}", 16);

        return mb_strlen($value) >= self::MIN_LENGTH_FOR_TAIL
            ? $bullets . mb_substr($value, -4)
            : $bullets;
    }
}
