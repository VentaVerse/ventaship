<?php

namespace App\Couriers\Logging;

use App\Models\CourierAccount;
use App\Models\CourierCall;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes one courier_calls row per HTTP call made while a courier context is set
 * (Context account_id, plus shipment_id and operation when known). Calls outside that context (outgoing client
 * webhooks, label downloads with no account) are not courier calls and are skipped.
 *
 * Only the method, host and path are stored. Headers (the auth token) and the query string never are. A response
 * body excerpt is kept only for status 400 and above, at most 4 KB, with names, phones (mobile and landline),
 * addresses, emails and anything credential-shaped (tokens, secrets, keys, passwords) masked.
 */
final class RecordCourierCalls
{
    public const EXCERPT_BYTES = 4096;

    private const MASK = '•••';

    /** JSON keys whose values are personal data. */
    private const PERSONAL_KEY = '/name|phone|mobile|contact|email|address|line_?\d|street/i';

    /**
     * JSON keys whose values are credentials, matched case-insensitively: anything naming a token, secret, password,
     * authorization or api key, and "key" on its own or as the last word ("user_key", "Ocp-Apim-Subscription-Key",
     * but not "monkey").
     */
    private const CREDENTIAL_KEY = '/token|secret|password|authorization|api_?key|(?:^|[_-])key$/i';

    /** The same keys inside quotes, for a body that is not valid JSON. */
    private const CREDENTIAL_PAIR = '/("(?:[^"]*(?:token|secret|password|authorization|api_?key)[^"]*|(?:[^"]*[_-])?key)"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i';

    /** PH mobile numbers in their common spellings: 09171234567, +63 917 123 4567, 63-917-123-4567. */
    private const PHONE = '/(?<!\d)(?:\+?63|0)[\s-]?9\d{2}[\s-]?\d{3}[\s-]?\d{4}(?!\d)/';

    /**
     * PH landlines, which always carry their 0 or +63 prefix: Metro Manila (02) 8123 4567, 028123 4567,
     * 02-8123-4567, +63 2 8123 4567, and provincial (032) 123 4567. A bare digit run (a tracking number) is not one.
     */
    private const LANDLINE = '/(?<![\d+])(?:\+?63[\s-]?\(?|\(?0)(?:2\)?[\s-]?\d{4}|[3-8]\d\)?[\s-]?\d{3})[\s-]?\d{4}(?!\d)/';

    public function handleResponse(ResponseReceived $event): void
    {
        $status = $event->response->status();
        $seconds = $event->response->transferStats?->getTransferTime();

        $this->record(
            $event->request,
            $status,
            $status >= 400 ? "HTTP {$status}" : null,
            $status >= 400 ? $event->response->body() : null,
            $seconds !== null ? (int) round($seconds * 1000) : null,
        );
    }

    public function handleFailure(ConnectionFailed $event): void
    {
        $this->record($event->request, null, self::withoutQueries($event->exception->getMessage()), null, null);
    }

    private function record(Request $request, ?int $status, ?string $error, ?string $body, ?int $durationMs): void
    {
        $accountRef = Context::get('account_id');
        if ($accountRef === null || $accountRef === '') {
            return;
        }

        try {
            $account = $this->account($accountRef);
            if ($account === null) {
                return;
            }

            $shipmentId = Context::get('shipment_id');

            CourierCall::query()->create([
                'courier_account_id' => $account['id'],
                'shipment_id' => is_numeric($shipmentId) ? (int) $shipmentId : null,
                'courier' => mb_substr((string) (Context::get('courier') ?: $account['courier']), 0, 16),
                'operation' => mb_substr((string) (Context::get('operation') ?: 'unknown'), 0, 64),
                'method' => mb_substr(strtoupper($request->method()), 0, 8),
                'host_path' => mb_substr(self::hostPath($request->url()), 0, 255),
                'status' => $status,
                'duration_ms' => $durationMs,
                'error' => $error !== null ? mb_substr($error, 0, 255) : null,
                'excerpt' => $body !== null && $body !== '' ? self::excerpt($body) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Logging a courier call must never break the call itself.
            Log::warning('Courier call could not be recorded', ['error' => $e->getMessage()]);
        }
    }

    /** @return array{id: int, courier: string}|null */
    private function account(mixed $ref): ?array
    {
        $query = CourierAccount::withTrashed();
        $query = is_string($ref) && str_starts_with($ref, CourierAccount::PUBLIC_PREFIX)
            ? $query->where('public_id', $ref)
            : $query->whereKey((int) $ref);

        $row = $query->first(['id', 'courier']);

        return $row ? ['id' => (int) $row->id, 'courier' => (string) $row->courier] : null;
    }

    private static function hostPath(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return '';
        }

        return ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
    }

    private static function withoutQueries(string $message): string
    {
        return (string) preg_replace('#(https?://[^\s?\#]+)[?\#]\S*#i', '$1', $message);
    }

    public static function excerpt(string $body): string
    {
        $decoded = json_decode($body, true);

        $masked = is_array($decoded)
            ? (string) json_encode(self::maskArray($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            : self::maskText($body);

        return mb_strcut($masked, 0, self::EXCERPT_BYTES, 'UTF-8');
    }

    /** @param  array<mixed>  $data */
    private static function maskArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::CREDENTIAL_KEY, $key) && (is_scalar($value) || is_array($value))) {
                $data[$key] = self::MASK;

                continue;
            }

            if (is_string($key) && preg_match(self::PERSONAL_KEY, $key) && (is_scalar($value) || is_array($value))) {
                $data[$key] = is_array($value) ? self::maskArray($value) : self::MASK;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::maskArray($value);
            } elseif (is_string($value)) {
                $data[$key] = self::maskText($value);
            }
        }

        return $data;
    }

    private static function maskText(string $text): string
    {
        $text = (string) preg_replace(self::PHONE, self::MASK, $text);
        $text = (string) preg_replace(self::LANDLINE, self::MASK, $text);
        $text = (string) preg_replace('/[^\s@"\'<>]+@[^\s@"\'<>]+\.[a-z]{2,}/i', self::MASK, $text);
        $text = (string) preg_replace(self::CREDENTIAL_PAIR, '$1"' . self::MASK . '"', $text);

        // "name":"…" style pairs in a body that is not valid JSON (truncated, HTML-wrapped, …).
        return (string) preg_replace(
            '/("[^"]*(?:name|phone|mobile|contact|email|address)[^"]*"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i',
            '$1"' . self::MASK . '"',
            $text,
        );
    }
}
