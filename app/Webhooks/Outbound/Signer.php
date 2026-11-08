<?php

namespace App\Webhooks\Outbound;

use App\Models\OutgoingEvent;

/**
 * Signs an outgoing webhook body: `X-Signature: sha256=HMAC-SHA256(webhook_secret, "{X-Timestamp}.{body}")`, with
 * X-Timestamp in unix seconds. The receiver verifies exactly the bytes it was sent.
 */
final class Signer
{
    /** @return array{X-Event-Id: string, X-Event-Type: string, X-Timestamp: string, X-Signature: string} */
    public static function headers(string $secret, string $body, OutgoingEvent $e): array
    {
        $ts = (string) now()->getTimestamp();

        return [
            'X-Event-Id' => (string) $e->public_id,
            'X-Event-Type' => (string) $e->type,
            'X-Timestamp' => $ts,
            'X-Signature' => 'sha256='.hash_hmac('sha256', $ts.'.'.$body, $secret),
        ];
    }
}
