<?php

namespace App\Api;

final class Signature
{
    /** $target is the path plus the raw query string, exactly as sent. */
    public static function sign(string $secret, int $ts, string $method, string $target, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', "{$ts}.{$method} {$target}\n{$body}", $secret);
    }
}
