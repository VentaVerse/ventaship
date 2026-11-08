<?php

namespace App\Webhooks\Outbound;

/** A client app's webhook URL must be an absolute http(s) URL with a host. */
final class WebhookUrl
{
    public static function isValid(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array($scheme, ['http', 'https'], true)
            && filled(parse_url($url, PHP_URL_HOST));
    }
}
