<?php

namespace App\View;

/**
 * A JSON setting as it is shown in its code box: a list one item per line, anything else pretty-printed. A value that
 * is not valid JSON (a refused edit coming back) is shown as it was typed.
 */
final class JsonSetting
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public static function pretty(?string $value): string
    {
        $value = (string) $value;
        if (trim($value) === '') {
            return '';
        }

        try {
            $decoded = json_decode($value, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value;
        }

        if (! is_array($decoded) || $decoded === []) {
            return (string) json_encode($decoded, self::FLAGS | JSON_PRETTY_PRINT);
        }

        return "[\n".implode(",\n", array_map(fn ($item) => '  '.self::inline($item), $decoded))."\n]";
    }

    /** Rows for the code box: the value's lines, at least 4 and at most 16 (it scrolls past that). */
    public static function rows(string $pretty): int
    {
        return max(4, min(16, substr_count($pretty, "\n") + 1));
    }

    /** One item on one line, with a space after each `:` and `,` (pretty-print, then join its lines). */
    private static function inline(mixed $item): string
    {
        $line = (string) preg_replace('/\n\s*/', ' ', (string) json_encode($item, self::FLAGS | JSON_PRETTY_PRINT));
        if (str_starts_with($line, '{ ') && str_ends_with($line, ' }')) {
            $line = '{'.substr($line, 2, -2).'}';
        } elseif (str_starts_with($line, '[ ') && str_ends_with($line, ' ]')) {
            $line = '['.substr($line, 2, -2).']';
        }

        return $line;
    }
}
