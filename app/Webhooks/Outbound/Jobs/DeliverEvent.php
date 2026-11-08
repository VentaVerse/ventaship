<?php

namespace App\Webhooks\Outbound\Jobs;

use App\Models\ClientApp;
use App\Models\OutgoingAttempt;
use App\Models\OutgoingEvent;
use App\Webhooks\Outbound\EventPayload;
use App\Webhooks\Outbound\RetrySchedule;
use App\Webhooks\Outbound\Signer;
use App\Webhooks\Outbound\WebhookUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
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

    /** After a connection failure, the app's other events are not posted for this long. */
    private const UNREACHABLE_SECONDS = 60;

    private const EXCERPT_BYTES = 2048;

    public function __construct(public int $eventId)
    {
        $this->onQueue('outgoing');
    }

    /** Set for a minute after a connection failure or timeout talking to the app's webhook URL. */
    public static function unreachableKey(int $clientAppId): string
    {
        return "ventaship:outgoing:unreachable:{$clientAppId}";
    }

    public function handle(): void
    {
        $e = $this->lease();
        if ($e === null) {
            return;
        }

        $app = $e->clientApp;
        $started = hrtime(true);
        $statusCode = null;
        try {
            $body = EventPayload::for($e);
            $headers = Signer::headers((string) $app->webhook_secret, $body, $e) + ['User-Agent' => 'Ventaship-Webhooks/1'];

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post((string) $app->webhook_url);

            $statusCode = $response->status();
            $ok = $response->successful(); // 2xx only; a 3xx is not followed and counts as a failure
            $error = $ok ? null : self::excerpt("HTTP {$statusCode}: ".$response->body());
        } catch (ConnectionException $ex) { // includes timeouts
            Cache::put(self::unreachableKey($app->id), true, self::UNREACHABLE_SECONDS);
            $ok = false;
            $error = self::excerpt(self::withoutQueryStrings($ex::class.': '.$ex->getMessage()));
        } catch (Throwable $ex) { // payload, signing or anything else: still an attempt, so the 72 h give-up applies
            $ok = false;
            $error = self::excerpt(self::withoutQueryStrings($ex::class.': '.$ex->getMessage()));
        }
        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);

        DB::transaction(function () use ($ok, $statusCode, $durationMs, $error): void {
            $e = OutgoingEvent::query()->lockForUpdate()->find($this->eventId);
            if ($e !== null) {
                $this->settle($e, $ok, $statusCode, $durationMs, $error);
            }
        });
    }

    /**
     * Takes the lease, or returns null when the event is not ours to send now (settled, leased, not due, skipped,
     * waiting behind an earlier sequence, or failed without posting because its app was just unreachable).
     */
    private function lease(): ?OutgoingEvent
    {
        return DB::transaction(function (): ?OutgoingEvent {
            $e = OutgoingEvent::query()->lockForUpdate()->find($this->eventId);
            if ($e === null || $e->status !== 'pending'
                || $e->leased_until?->isFuture() || $e->next_attempt_at?->isFuture()) {
                return null;
            }

            $app = $e->clientApp;
            $skipReason = $this->skipReason($e, $app);
            if ($skipReason !== null) {
                $e->update([
                    'status' => 'skipped', 'last_error' => $skipReason,
                    'next_attempt_at' => null, 'leased_until' => null, 'dispatched_until' => null,
                ]);

                return null;
            }

            if ($e->shipment_id !== null && $this->earlierStillPending($e)) {
                $e->update(['next_attempt_at' => now()->addSeconds(self::ORDER_WAIT_SECONDS), 'dispatched_until' => null]);

                return null;
            }

            $lease = ['leased_until' => now()->addSeconds(self::LEASE_SECONDS), 'dispatched_until' => null];
            if ((int) $e->attempts === 0) {
                $lease['window_started_at'] = now(); // the 72 h window starts when the event can first be sent
            }
            $e->update($lease);

            if (Cache::has(self::unreachableKey($app->id))) {
                $this->settle($e, false, null, 0, 'not sent: a connection to this webhook URL failed within the last minute');

                return null;
            }

            return $e;
        });
    }

    private function skipReason(OutgoingEvent $e, ?ClientApp $app): ?string
    {
        if ($app === null || $app->status !== 'active') {
            return 'app disabled';
        }

        if (blank($app->webhook_url)) {
            return 'no webhook url';
        }

        if (! WebhookUrl::isValid((string) $app->webhook_url)) {
            return 'invalid webhook url';
        }

        if (blank($app->webhook_secret)) {
            return 'no webhook secret';
        }

        // A ping is the app's own test, so it is sent whatever the event filter says.
        $subscribed = $app->webhook_events;
        if ($e->type !== 'ping' && is_array($subscribed) && $subscribed !== [] && ! in_array($e->type, $subscribed, true)) {
            return 'event type not subscribed';
        }

        return null;
    }

    private function earlierStillPending(OutgoingEvent $e): bool
    {
        return OutgoingEvent::query()
            ->where('shipment_id', $e->shipment_id)
            ->where('sequence', '<', $e->sequence)
            ->where('status', 'pending')
            ->exists();
    }

    /** Records the attempt and moves the (locked) event on: delivered, retried later, or given up. */
    private function settle(OutgoingEvent $e, bool $ok, ?int $statusCode, int $durationMs, ?string $error): void
    {
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
    }

    /** Drops query strings and fragments from any URL in an error message: they may carry tokens. */
    private static function withoutQueryStrings(string $text): string
    {
        return (string) preg_replace('~(\bhttps?://[^\s?#"\'<>]+)[?#][^\s"\'<>]*~i', '$1', $text);
    }

    /** At most 2 KB of valid UTF-8, so a binary or huge response body can neither bloat nor break the row. */
    private static function excerpt(string $text): string
    {
        return mb_strcut(mb_scrub($text, 'UTF-8'), 0, self::EXCERPT_BYTES, 'UTF-8');
    }
}
