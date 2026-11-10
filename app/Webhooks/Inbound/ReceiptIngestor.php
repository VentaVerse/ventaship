<?php

namespace App\Webhooks\Inbound;

use App\Models\CourierAccount;
use App\Models\WebhookReceipt;
use App\Support\AfterResponse;
use App\Webhooks\Inbound\Jobs\ProcessReceipt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

/**
 * Stores inbound courier webhooks as webhook_receipts (base plan §3 "Fast acknowledgement"). Nothing here parses
 * the courier's payload or touches a shipment: a known account's receipt is processed by ProcessReceipt in this
 * process once the response is sent (AfterResponse), so the courier is answered at once even with no queue worker.
 * A run that fails leaves the receipt `received`, and ventaship:receipts-reprocess queues it again after two minutes.
 *
 * - accepted: the raw body exactly as received (getContent(), capped at 64 KB) plus the allow-listed headers.
 * - unrouted: no account found; at most 16 KB of the body, pruned after 7 days.
 * - rejected: the token or signature failed. QuadX keeps nothing of the request; SPX keeps the allow-listed headers
 *   and at most 64 KB of the body, with the reason. Rejected and unrouted receipts are pruned after 7 days.
 */
final class ReceiptIngestor
{
    public const BODY_CAP = 65536;

    public const UNROUTED_CAP = 16384;

    /** The only request headers kept (and relayed). Lower case. */
    public const HEADERS = ['content-type', 'user-agent', 'app-id', 'check-sign', 'timestamp', 'random-num', 'x-request-id'];

    private const HEADER_MAX = 512;

    private const EXTERNAL_ID_MAX = 100;

    /** Stores the receipt and processes it after the response. Null for a duplicate of a stored receipt (SPX `id`). */
    public function accept(CourierAccount $account, Request $r, string $routedBy): ?WebhookReceipt
    {
        $raw = $r->getContent();
        [$body, $problem] = $this->fit($raw, self::BODY_CAP);

        try {
            $receipt = WebhookReceipt::query()->create([
                'courier' => $account->courier,
                'courier_account_id' => $account->id,
                'routed_by' => $routedBy,
                'status' => $problem === null ? 'received' : 'failed',
                'external_id' => $problem === null ? $this->externalId($account->courier, $raw) : null,
                'headers' => $this->headers($r),
                'body' => $body,
                'received_at' => now(),
                'processed_at' => $problem === null ? null : now(),
                'error' => $problem,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // the courier sent this delivery before: already stored and processed
        }

        if ($problem === null) {
            $id = $receipt->id;
            // Not through the sync queue: its failed() would settle the receipt as failed instead of leaving it for
            // ventaship:receipts-reprocess.
            AfterResponse::run("process receipt {$id}", fn () => app()->call([new ProcessReceipt($id, inline: true), 'handle']));
        }

        return $receipt;
    }

    public function unrouted(string $courier, Request $r, ?string $reason = null): WebhookReceipt
    {
        [$body] = $this->fit($r->getContent(), self::UNROUTED_CAP);

        return WebhookReceipt::query()->create([
            'courier' => $courier,
            'routed_by' => 'none',
            'status' => 'unrouted',
            'headers' => $this->headers($r),
            'body' => $body,
            'received_at' => now(),
            'error' => $reason === null ? null : mb_substr($reason, 0, 255),
        ]);
    }

    /**
     * A refused request. With the request (SPX), the allow-listed headers (check-sign is SPX's signature, never our
     * secret) and at most BODY_CAP of the body are kept, with the reason, so a mismatch can be diagnosed. Never
     * processed: only `received` receipts are.
     */
    public function rejected(string $courier, ?CourierAccount $account, ?Request $r = null, ?string $reason = null, string $routedBy = 'none'): WebhookReceipt
    {
        $body = $r === null ? null : $this->fit($r->getContent(), self::BODY_CAP)[0];

        return WebhookReceipt::query()->create([
            'courier' => $courier,
            'courier_account_id' => $account?->id,
            'routed_by' => $routedBy,
            'status' => 'rejected',
            'headers' => $r === null ? null : $this->headers($r),
            'body' => $body === '' ? null : $body,
            'received_at' => now(),
            'error' => $reason === null ? null : mb_substr($reason, 0, 255),
        ]);
    }

    /**
     * The body as it can be stored: unchanged when it is valid UTF-8 within the cap; otherwise cut on a character
     * boundary (or scrubbed) with the reason, since a text column cannot hold arbitrary bytes.
     *
     * @return array{0: string, 1: ?string} body, problem
     */
    private function fit(string $raw, int $cap): array
    {
        if (! mb_check_encoding($raw, 'UTF-8')) {
            return [mb_scrub(substr($raw, 0, $cap), 'UTF-8'), 'body is not valid UTF-8'];
        }

        if (strlen($raw) > $cap) {
            return [mb_strcut($raw, 0, $cap, 'UTF-8'), 'body truncated'];
        }

        return [$raw, null];
    }

    /** SPX's per-delivery `id`: the same id twice on one account is one receipt. */
    private function externalId(string $courier, string $raw): ?string
    {
        if ($courier !== 'spx') {
            return null;
        }

        $payload = json_decode($raw, true);
        $id = is_array($payload) ? ($payload['id'] ?? null) : null;
        if (is_int($id)) {
            $id = (string) $id;
        }

        return is_string($id) && $id !== '' && strlen($id) <= self::EXTERNAL_ID_MAX ? $id : null;
    }

    /** @return array<string, string>|null */
    private function headers(Request $r): ?array
    {
        $kept = [];
        foreach (self::HEADERS as $name) {
            $value = $r->headers->get($name);
            if (is_string($value) && $value !== '') {
                $kept[$name] = mb_substr(mb_scrub($value, 'UTF-8'), 0, self::HEADER_MAX);
            }
        }

        return $kept === [] ? null : $kept;
    }
}
