<?php

namespace App\Platforms;

use App\Models\ApiKey;
use App\Models\AuditEntry;
use App\Models\ClientApp;
use App\Models\OutgoingAttempt;
use App\Models\OutgoingEvent;
use App\Webhooks\Outbound\Jobs\DeliverEvent;
use App\Webhooks\Outbound\Outbox;
use App\Webhooks\Outbound\WebhookUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connected-platform (client app) operations shared by the console commands and the admin screen. Each refusal is a
 * PlatformAppException with a short message. Audit rows name keys by prefix and parts by name, never their values.
 */
class PlatformApps
{
    /** The webhook event types a platform can filter on. A ping is always sent. */
    public const EVENT_TYPES = [
        'shipment.booked',
        'shipment.booking_failed',
        'shipment.unknown',
        'shipment.resolved',
        'shipment.status_changed',
        'shipment.payment_state_changed',
        'shipment.cancelled',
        'shipment.cancel_failed',
    ];

    public const STATUSES = ['active', 'disabled'];

    public const BAD_URL = 'The webhook URL must be an http:// or https:// URL with a host.';

    private const NO_ANSWER = 'No answer from the platform.';

    /** @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor */
    public function create(string $name, ?string $webhookUrl, ?array $actor = null): ClientApp
    {
        $name = $this->validName($name);
        $webhookUrl = $this->validUrl($webhookUrl);

        return DB::transaction(function () use ($name, $webhookUrl, $actor) {
            $app = ClientApp::query()->create([
                'name' => $name,
                'status' => 'active',
                'webhook_url' => $webhookUrl,
                'webhook_secret' => self::newWebhookSecret(),
            ]);

            $this->audit($actor, 'client_app.created', 'client_app', $app->public_id, [
                'name' => $name, 'webhook_url' => $webhookUrl !== null,
            ]);

            return $app;
        });
    }

    /**
     * Applies any of name, webhook_url (empty = none), status and webhook_events (empty = all). Returns whether
     * anything changed. The audit row says the webhook URL changed, never what it is: it may carry a token.
     *
     * @param  array{name?: string, webhook_url?: ?string, status?: string, webhook_events?: ?array<int, string>}  $data
     * @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor
     */
    public function update(ClientApp $app, array $data, ?array $actor = null): bool
    {
        $new = [];
        if (array_key_exists('name', $data)) {
            $new['name'] = $this->validName((string) $data['name']);
        }
        if (array_key_exists('webhook_url', $data)) {
            $new['webhook_url'] = $this->validUrl($data['webhook_url']);
        }
        if (array_key_exists('status', $data)) {
            if (! in_array($data['status'], self::STATUSES, true)) {
                throw new PlatformAppException('The status must be active or disabled.');
            }
            $new['status'] = $data['status'];
        }
        if (array_key_exists('webhook_events', $data)) {
            $events = $data['webhook_events'];
            if ($events !== null && (! is_array($events) || array_filter($events, fn ($t) => ! is_string($t)) !== []
                || array_diff($events, self::EVENT_TYPES) !== [])) {
                throw new PlatformAppException('Unknown webhook event type.');
            }
            $new['webhook_events'] = $events === null || $events === [] ? null : array_values(array_unique($events));
        }

        return DB::transaction(function () use ($app, $new, $actor) {
            $changes = [];
            foreach ($new as $field => $value) {
                $from = $app->{$field};
                if ($from === $value) {
                    continue;
                }
                $changes[$field] = $field === 'webhook_url' ? ['changed' => true] : ['from' => $from, 'to' => $value];
            }
            if ($changes === []) {
                return false;
            }

            $app->update(array_intersect_key($new, $changes));
            $this->audit($actor, 'client_app.updated', 'client_app', $app->public_id, $changes);

            return true;
        });
    }

    /**
     * @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor
     * @return array{key: ApiKey, plaintext_key: string, secret: string}
     */
    public function issueKey(ClientApp $app, string $name, ?array $actor = null): array
    {
        $name = $this->validName($name);
        $plain = 'vsk_'.Str::random(40);
        $secret = Str::random(48);

        $key = DB::transaction(function () use ($app, $name, $plain, $secret, $actor) {
            $key = ApiKey::query()->create([
                'client_app_id' => $app->getKey(),
                'name' => $name,
                'key_prefix' => substr($plain, 0, 8),
                'key_hash' => hash('sha256', $plain),
                'key_encrypted' => $plain,
                'signing_secret' => $secret,
            ]);

            $this->audit($actor, 'api_key.issued', 'client_app', $app->public_id, [
                'name' => $name, 'key_prefix' => $key->key_prefix,
            ]);

            return $key;
        });

        return ['key' => $key, 'plaintext_key' => $plain, 'secret' => $secret];
    }

    /**
     * Returns false when the key was already revoked.
     *
     * @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor
     */
    public function revokeKey(ApiKey $key, ?array $actor = null): bool
    {
        return DB::transaction(function () use ($key, $actor) {
            $revoked = ApiKey::query()->whereKey($key->getKey())->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
            if ($revoked === 0) {
                return false;
            }
            $key->refresh();

            $this->audit($actor, 'api_key.revoked', 'client_app', (string) $key->clientApp?->public_id, [
                'name' => $key->name, 'key_prefix' => $key->key_prefix,
            ]);

            return true;
        });
    }

    /**
     * The key itself (`key`) or its signing secret (`secret`). Refuses a revoked key, and the `key` part of a key
     * issued before keys were stored encrypted (KeyNotViewableException).
     *
     * @param  array{type: string, id: mixed, ip: ?string}  $actor
     */
    public function revealKey(ApiKey $key, string $part, array $actor): string
    {
        if (! in_array($part, ['key', 'secret'], true)) {
            throw new PlatformAppException('Unknown key part.');
        }
        // Fresh from the database: the caller's model may predate a revoke.
        $key = ApiKey::query()->with('clientApp')->find($key->getKey());
        if ($key === null || $key->revoked_at !== null) {
            throw new PlatformAppException('This key is revoked.');
        }

        $column = $part === 'key' ? 'key_encrypted' : 'signing_secret';
        if ($key->getRawOriginal($column) === null) {
            throw $part === 'key'
                ? new KeyNotViewableException('Issued before keys were viewable.')
                : new PlatformAppException('Not set.');
        }

        $value = $this->decrypted($key, $column);
        if ($part === 'key' && ! hash_equals((string) $key->key_hash, hash('sha256', $value))) {
            throw new PlatformAppException('This key could not be read.');
        }

        $this->audit($actor, 'api_key.viewed', 'client_app', (string) $key->clientApp?->public_id, [
            'part' => $part, 'key_prefix' => $key->key_prefix,
        ]);

        return $value;
    }

    /** @param  array{type: string, id: mixed, ip: ?string}  $actor */
    public function revealWebhookSecret(ClientApp $app, array $actor): string
    {
        if ($app->getRawOriginal('webhook_secret') === null) {
            throw new PlatformAppException('Not set.');
        }
        $value = $this->decrypted($app, 'webhook_secret');

        $this->audit($actor, 'client_app.webhook_secret_viewed', 'client_app', $app->public_id, []);

        return $value;
    }

    /**
     * A new webhook secret, used from the next delivery on. Deliveries already sent keep the signature they had.
     *
     * @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor
     */
    public function regenerateWebhookSecret(ClientApp $app, ?array $actor = null): void
    {
        DB::transaction(function () use ($app, $actor) {
            $app->update(['webhook_secret' => self::newWebhookSecret()]);
            $this->audit($actor, 'client_app.webhook_secret_regenerated', 'client_app', $app->public_id, []);
        });
    }

    /** Records a ping event for the app, for the caller to deliver. */
    public function recordPing(ClientApp $app): OutgoingEvent
    {
        return DB::transaction(fn () => Outbox::record($app, 'ping', ['message' => 'ping']));
    }

    /**
     * Records a ping and delivers it now, in this request. Never throws.
     *
     * @return array{ok: bool, status: ?int, message: string}
     */
    public function ping(ClientApp $app): array
    {
        if ($app->status !== 'active') {
            return ['ok' => false, 'status' => null, 'message' => 'This platform is disabled.'];
        }
        if (blank($app->webhook_url)) {
            return ['ok' => false, 'status' => null, 'message' => 'No webhook URL.'];
        }

        try {
            $outcome = $this->deliverNow($this->recordPing($app));
        } catch (Throwable $ex) {
            Log::warning('Ping could not be sent', ['client_app' => $app->public_id, 'error' => $ex::class]);

            return ['ok' => false, 'status' => null, 'message' => 'The ping could not be sent.'];
        }

        return $outcome;
    }

    /**
     * Puts a failed or delivered event back in the outbox with a fresh 72-hour window. Conditional, so it never
     * touches an event a worker is delivering right now. Returns false when the event was in another state.
     *
     * @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor
     */
    public function requeue(OutgoingEvent $event, ?array $actor = null): bool
    {
        return DB::transaction(function () use ($event, $actor) {
            $reset = OutgoingEvent::query()
                ->whereKey($event->id)
                ->whereIn('status', ['failed', 'delivered'])
                ->update([
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_attempt_at' => now(),
                    'window_started_at' => now(),
                    'leased_until' => null,
                    'delivered_at' => null,
                    'last_error' => null,
                    'updated_at' => now(),
                ]);
            if ($reset === 0) {
                return false;
            }

            $this->audit($actor, 'outgoing_event.resent', 'outgoing_event', $event->public_id, ['type' => $event->type]);

            return true;
        });
    }

    /**
     * Requeues a failed or delivered event and delivers it now, in this request. Never throws.
     *
     * @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor
     * @return array{ok: bool, message: string}
     */
    public function resend(OutgoingEvent $event, ?array $actor = null): array
    {
        try {
            if (! $this->requeue($event, $actor)) {
                return ['ok' => false, 'message' => 'Only a failed or delivered event can be resent.'];
            }
            $outcome = $this->deliverNow($event);
        } catch (Throwable $ex) {
            Log::warning('Event could not be resent', ['event' => $event->public_id, 'error' => $ex::class]);

            return ['ok' => false, 'message' => 'The event could not be sent.'];
        }

        return ['ok' => $outcome['ok'], 'message' => $outcome['message']];
    }

    /**
     * How to show a secret without showing it: its length, and its last 4 characters when it has at least 8.
     *
     * @return array{length: int, last4: string}
     */
    public function masked(string $plaintext): array
    {
        $length = mb_strlen($plaintext);

        return ['length' => $length, 'last4' => $length >= 8 ? mb_substr($plaintext, -4) : ''];
    }

    /**
     * Runs the delivery job in this process and reads back what happened to the event.
     *
     * @return array{ok: bool, status: ?int, message: string}
     */
    private function deliverNow(OutgoingEvent $event): array
    {
        $before = (int) OutgoingAttempt::query()->where('outgoing_event_id', $event->id)->max('id');

        DeliverEvent::dispatchSync($event->id);

        $event->refresh();
        $attempt = OutgoingAttempt::query()->where('outgoing_event_id', $event->id)
            ->where('id', '>', $before)->orderByDesc('id')->first();

        if ($event->status === 'delivered' && $attempt !== null) {
            return ['ok' => true, 'status' => $attempt->status_code, 'message' => "Delivered · HTTP {$attempt->status_code}"];
        }
        if ($attempt !== null) {
            return $attempt->status_code !== null
                ? ['ok' => false, 'status' => (int) $attempt->status_code, 'message' => "Failed · HTTP {$attempt->status_code}"]
                : ['ok' => false, 'status' => null, 'message' => self::NO_ANSWER];
        }
        if ($event->status === 'skipped') {
            $message = match ($event->last_error) {
                'app disabled' => 'This platform is disabled.',
                'no webhook url' => 'No webhook URL.',
                'invalid webhook url' => 'The webhook URL is not valid.',
                'no webhook secret' => 'No webhook secret.',
                'event type not subscribed' => 'This platform does not take this event type.',
                default => 'Not sent.',
            };

            return ['ok' => false, 'status' => null, 'message' => $message];
        }

        // Still pending without an attempt: held back behind an earlier event for the same shipment.
        return ['ok' => false, 'status' => null, 'message' => 'Waiting for an earlier event.'];
    }

    /** Decrypts a vault column, turning a bad ciphertext into a refusal instead of an error page. */
    private function decrypted(ApiKey|ClientApp $model, string $column): string
    {
        try {
            return (string) $model->{$column};
        } catch (Throwable $ex) {
            Log::warning('Stored credential could not be decrypted', [
                'model' => class_basename($model), 'id' => $model->getKey(), 'column' => $column, 'error' => $ex::class,
            ]);

            throw new PlatformAppException('This value could not be read.');
        }
    }

    private function validName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new PlatformAppException('The name must be 1 to 255 characters.');
        }

        return $name;
    }

    private function validUrl(?string $url): ?string
    {
        $url = $url === null ? null : trim($url);
        if ($url === null || $url === '') {
            return null;
        }
        if (mb_strlen($url) > 2048 || ! WebhookUrl::isValid($url)) {
            throw new PlatformAppException(self::BAD_URL, 'webhook_url');
        }

        return $url;
    }

    private static function newWebhookSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    /** @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor */
    private function audit(?array $actor, string $action, string $subjectType, string $subjectId, array $changes): void
    {
        $admin = $actor !== null && ($actor['type'] ?? null) === 'admin';

        AuditEntry::query()->create([
            'actor_type' => $admin ? 'admin' : 'system',
            'actor_id' => $admin ? (string) $actor['id'] : null,
            'ip' => $admin ? ($actor['ip'] ?? null) : null,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }
}
