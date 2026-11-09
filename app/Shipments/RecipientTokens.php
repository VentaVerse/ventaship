<?php

namespace App\Shipments;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Blind index over the recipient's name, so the list can search a vault-encrypted field. Each lowercase, accent-folded
 * word of the first and last name becomes 16 hex chars of HMAC-SHA256 under a key derived from the vault key (never
 * the vault key itself); the column holds those tokens space-separated and never any plaintext.
 */
final class RecipientTokens
{
    /** Words kept per name or query; anything past this is ignored. */
    public const MAX_WORDS = 12;

    private const TOKEN_HEX = 16;

    private static ?string $key = null;

    private static ?string $keyFor = null;

    /** @param  array<string, mixed>|null  $recipient */
    public static function forRecipient(?array $recipient): ?string
    {
        if ($recipient === null) {
            return null;
        }
        $name = trim(self::part($recipient['first_name'] ?? null).' '.self::part($recipient['last_name'] ?? null));
        $tokens = self::forWords($name);

        return $tokens === [] ? null : implode(' ', $tokens);
    }

    /** @return list<string> one token per distinct word, in order */
    public static function forWords(string $text): array
    {
        $key = self::key();

        return array_map(
            fn (string $w) => substr(hash_hmac('sha256', $w, $key), 0, self::TOKEN_HEX),
            self::words($text),
        );
    }

    /** @return list<string> lowercase ASCII words, deduplicated, at most MAX_WORDS */
    public static function words(string $text): array
    {
        $folded = Str::lower(Str::ascii($text));
        $parts = preg_split('/[^a-z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($parts)), 0, self::MAX_WORDS);
    }

    private static function part(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }

    private static function key(): string
    {
        $vault = (string) config('ventaship.vault_key');
        if (self::$key !== null && self::$keyFor === $vault) {
            return self::$key;
        }

        $raw = base64_decode($vault, true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('VENTASHIP_VAULT_KEY must be base64 of 32 bytes');
        }

        self::$keyFor = $vault;

        return self::$key = hash_hmac('sha256', 'recipient-tokens', $raw, true);
    }
}
