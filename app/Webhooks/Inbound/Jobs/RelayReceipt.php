<?php

namespace App\Webhooks\Inbound\Jobs;

use App\Models\CourierAccount;
use App\Models\WebhookReceipt;
use App\Webhooks\Outbound\WebhookUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Forwards an unmatched courier webhook to the account's relay URL, so parcels a
 * store books itself on a shared courier account still reach it. The raw body goes byte for byte with the original
 * allow-listed courier headers (SPX's check-sign, app-id, timestamp and random-num stay valid). QuadX's relay URL
 * carries its own ?token; nothing of ours is added to it.
 *
 * http(s) only, no redirects followed, 10 s timeout. A non-2xx answer or a connection failure is retried.
 */
class RelayReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** The first send plus retries after 60, 300 and 900 seconds. */
    public int $tries = 4;

    public int $timeout = 30;

    private const TIMEOUT_SECONDS = 10;

    /** The stored (lower-case) header names as sent: SPX's own headers keep SPX's spelling. */
    private const WIRE_NAMES = [
        'content-type' => 'Content-Type',
        'user-agent' => 'User-Agent',
        'app-id' => 'app-id',
        'check-sign' => 'check-sign',
        'timestamp' => 'timestamp',
        'random-num' => 'random-num',
        'x-request-id' => 'X-Request-Id',
    ];

    public function __construct(public int $receiptId)
    {
        $this->onQueue('inbound');
    }

    /** @return list<int> seconds before each retry */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $receipt = WebhookReceipt::query()->find($this->receiptId);
        if ($receipt === null || $receipt->body === null || ! in_array($receipt->status, ['unmatched', 'processed', 'relay_pending'], true)) {
            return;
        }

        if ($receipt->status === 'relay_pending') {
            // The relay job runs now: the queue's own retries take over from receipts-reprocess.
            WebhookReceipt::query()->whereKey($receipt->id)->where('status', 'relay_pending')->update(['status' => 'unmatched']);
        }

        $account = $receipt->courier_account_id === null ? null : CourierAccount::query()->find($receipt->courier_account_id);
        $url = (string) $account?->relay_url;
        if ($url === '' || ! WebhookUrl::isValid($url)) {
            Log::warning('Courier webhook not relayed: no usable relay URL', ['receipt' => $receipt->id]);

            return;
        }

        WebhookReceipt::query()->whereKey($receipt->id)->increment('relay_attempts');

        $stored = is_array($receipt->headers) ? $receipt->headers : [];
        $contentType = (string) ($stored['content-type'] ?? 'application/json');
        $headers = [];
        foreach (self::WIRE_NAMES as $name => $wire) {
            if ($name !== 'content-type' && is_string($stored[$name] ?? null)) {
                $headers[$wire] = $stored[$name];
            }
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders($headers)
                ->withBody($receipt->body, $contentType)
                ->post($url);
        } catch (ConnectionException) {
            // The exception text names the URL, whose query can carry the store's token: not kept in failed_jobs.
            throw new RuntimeException("Relay of receipt {$receipt->id} could not connect");
        }

        if (! $response->successful()) {
            throw new RuntimeException("Relay of receipt {$receipt->id} answered HTTP {$response->status()}");
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('Courier webhook relay gave up', ['receipt' => $this->receiptId, 'exception' => $e === null ? null : $e::class]);

        WebhookReceipt::query()->whereKey($this->receiptId)->update([
            'error' => mb_substr('relay failed: '.($e?->getMessage() ?? 'unknown'), 0, 500),
        ]);
    }
}
