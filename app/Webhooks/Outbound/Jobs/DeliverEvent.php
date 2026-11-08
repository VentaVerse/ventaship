<?php

namespace App\Webhooks\Outbound\Jobs;

use App\Models\OutgoingAttempt;
use App\Models\OutgoingEvent;
use App\Webhooks\Outbound\EventPayload;
use App\Webhooks\Outbound\RetrySchedule;
use App\Webhooks\Outbound\Signer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one outgoing event to its client app's webhook URL, signed with the app's webhook secret.
 *
 * The event is leased (row lock + `leased_until`) before the POST and settled after it, so two workers never send it
 * at the same time. A worker that dies mid-delivery leaves a lease that runs out after 10 minutes, and the event is
 * due again: the client may then see it twice, which is safe because clients dedupe on the event id.
 *
 * Never retried by the queue: the event's own `next_attempt_at` (RetrySchedule) is the retry mechanism, picked up by
 * `ventaship:events-deliver`.
 */
class DeliverEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    private const LEASE_SECONDS = 600;

    private const TIMEOUT_SECONDS = 10;

    /** Seconds a later sequence waits while an earlier one for the same shipment is still pending. */
    private const ORDER_WAIT_SECONDS = 30;

    private const EXCERPT_BYTES = 2048;

    public function __construct(public int $eventId)
    {
        $this->onQueue('outgoing');
    }

    public function handle(): void
    {
        $e = $this->lease();
        if ($e === null) {
            return;
        }

        $app = $e->clientApp;
        $body = EventPayload::for($e);
        $headers = Signer::headers((string) $app->webhook_secret, $body, $e) + ['User-Agent' => 'Ventaship-Webhooks/1'];

        $started = hrtime(true);
        $statusCode = null;
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post((string) $app->webhook_url);

            $statusCode = $response->status();
            $ok = $response->successful(); // 2xx only; a 3xx is not followed and counts as a failure
            $error = $ok ? null : self::excerpt("HTTP {$statusCode}: ".$response->body());
        } catch (Throwable $ex) {
            $ok = false;
            $error = self::excerpt($ex::class.': '.$ex->getMessage());
        }
        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);

        $this->settle($ok, $statusCode, $durationMs, $error);
    }

    /** Takes the lease, or returns null when the event is not ours to send now (settled, leased, not due, skipped, waiting). */
    private function lease(): ?OutgoingEvent
    {
        return DB::transaction(function (): ?OutgoingEvent {
            $e = OutgoingEvent::query()->lockForUpdate()->find($this->eventId);
            if ($e === null || $e->status !== 'pending'
                || $e->leased_until?->isFuture() || $e->next_attempt_at?->isFuture()) {
                return null;
            }

            $app = $e->clientApp;
            if ($app === null || blank($app->webhook_url)) {
                $this->skip($e, 'no webhook url');

                return null;
            }

            if (blank($app->webhook_secret)) {
                $this->skip($e, 'no webhook secret');

                return null;
            }

            // A ping is the app's own test, so it is sent whatever the event filter says.
            $subscribed = $app->webhook_events;
            if ($e->type !== 'ping' && is_array($subscribed) && $subscribed !== [] && ! in_array($e->type, $subscribed, true)) {
                $this->skip($e, 'event type not subscribed');

                return null;
            }

            if ($e->shipment_id !== null && $this->earlierStillPending($e)) {
                $e->update(['next_attempt_at' => now()->addSeconds(self::ORDER_WAIT_SECONDS)]);

                return null;
            }

            $e->update(['leased_until' => now()->addSeconds(self::LEASE_SECONDS)]);

            return $e;
        });
    }

    private function earlierStillPending(OutgoingEvent $e): bool
    {
        return OutgoingEvent::query()
            ->where('shipment_id', $e->shipment_id)
            ->where('sequence', '<', $e->sequence)
            ->where('status', 'pending')
            ->exists();
    }

    private function skip(OutgoingEvent $e, string $reason): void
    {
        $e->update(['status' => 'skipped', 'last_error' => $reason, 'next_attempt_at' => null, 'leased_until' => null]);
    }

    private function settle(bool $ok, ?int $statusCode, int $durationMs, ?string $error): void
    {
        DB::transaction(function () use ($ok, $statusCode, $durationMs, $error): void {
            $e = OutgoingEvent::query()->lockForUpdate()->find($this->eventId);
            if ($e === null) {
                return;
            }

            OutgoingAttempt::query()->create([
                'outgoing_event_id' => $e->id,
                'status_code' => $statusCode,
                'duration_ms' => $durationMs,
                'error' => $error,
                'created_at' => now(),
            ]);

            if ($e->status !== 'pending') {
                return; // settled meanwhile by another worker whose lease followed an expired one
            }

            $attempts = $e->attempts + 1;

            if ($ok) {
                $e->update([
                    'status' => 'delivered', 'attempts' => $attempts, 'delivered_at' => now(),
                    'next_attempt_at' => null, 'leased_until' => null, 'last_error' => null,
                ]);

                return;
            }

            $delay = RetrySchedule::delayAfter($attempts, $e->window_started_at ?? $e->created_at);
            $e->update([
                'status' => $delay === null ? 'failed' : 'pending',
                'attempts' => $attempts,
                'next_attempt_at' => $delay === null ? null : now()->addSeconds($delay),
                'leased_until' => null,
                'last_error' => $error,
            ]);

            if ($delay === null) {
                Log::warning('Outgoing webhook given up', [
                    'event' => $e->public_id, 'client_app_id' => $e->client_app_id, 'attempts' => $attempts, 'status_code' => $statusCode,
                ]);
            }
        });
    }

    /** At most 2 KB of valid UTF-8, so a binary or huge response body can neither bloat nor break the row. */
    private static function excerpt(string $text): string
    {
        return mb_strcut(mb_scrub($text, 'UTF-8'), 0, self::EXCERPT_BYTES, 'UTF-8');
    }
}
