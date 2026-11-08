<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\ApiRequest;
use App\Models\ClientApp;
use App\Models\OutgoingEvent;
use App\Platforms\PlatformAppException;
use App\Platforms\PlatformApps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class PlatformsController extends Controller
{
    public function index(): View
    {
        $apps = ClientApp::query()->withCount(['tokens as active_keys_count' => fn ($q) => $q->usable()])
            ->orderBy('name')->orderBy('id')->get();
        $ids = $apps->modelKeys();

        $lastCalls = ApiRequest::query()->whereIn('client_app_id', $ids)
            ->selectRaw('client_app_id, MAX(created_at) as last_at')->groupBy('client_app_id')->pluck('last_at', 'client_app_id');

        // The newest delivery that was actually tried: a skipped event says nothing about the receiver.
        $latestIds = OutgoingEvent::query()->whereIn('client_app_id', $ids)->where('status', '!=', 'skipped')
            ->selectRaw('MAX(id) as id')->groupBy('client_app_id')->pluck('id');
        $latest = OutgoingEvent::query()->whereIn('id', $latestIds)->get(['id', 'client_app_id', 'status', 'last_error'])->keyBy('client_app_id');

        return view('platforms.index', [
            'rows' => $apps->map(fn (ClientApp $app) => [
                'app' => $app,
                'keys' => (int) $app->active_keys_count,
                'last_call' => isset($lastCalls[$app->id]) ? \Illuminate\Support\Carbon::parse($lastCalls[$app->id]) : null,
                'health' => $this->health($app, $latest[$app->id] ?? null),
            ]),
        ]);
    }

    public function create(): View
    {
        return view('platforms.create');
    }

    public function store(Request $request, PlatformApps $platforms): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'webhook_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
        ]);

        try {
            $app = $platforms->create($data['name'], $data['webhook_url'] ?? null, $this->actor($request));
        } catch (PlatformAppException $e) {
            return back()->withInput()->withErrors([$this->field($e) => $e->getMessage()]);
        }

        return redirect(route('apps.show', $app))->with('status', 'Added');
    }

    public function show(Request $request, ClientApp $app, PlatformApps $platforms): View
    {
        $keys = $app->tokens()->usable()->orderBy('id')->get();
        // Set by issueKey for one request: the new key's id only. The page then reveals it through apps.reveal.
        $issued = $request->session()->get('issued_key');

        $deliveries = OutgoingEvent::query()->where('client_app_id', $app->id)
            ->with('shipment:id,public_id,client_reference')
            ->orderByDesc('id')->limit(50)
            ->get(['id', 'public_id', 'client_app_id', 'type', 'shipment_id', 'status', 'attempts', 'last_error', 'created_at']);
        $calls = ApiRequest::query()->where('client_app_id', $app->id)
            ->orderByDesc('id')->limit(50)
            ->get(['id', 'method', 'path', 'status', 'duration_ms', 'token_prefix', 'created_at']);

        return view('platforms.show', [
            'app' => $app,
            'deliveries' => $deliveries->map(fn (OutgoingEvent $e) => [
                'event' => $e,
                'badge' => $this->deliveryBadge($e),
                'resendable' => in_array($e->status, ['failed', 'delivered'], true),
                'error' => $e->last_error === null ? null : Str::limit($e->last_error, 80),
            ]),
            'calls' => $calls->map(fn (ApiRequest $r) => [
                'call' => $r,
                'tone' => $r->status >= 500 ? 'bad' : ($r->status >= 400 ? 'attn' : 'good'),
            ]),
            'keys' => $keys->map(fn (ApiToken $key) => [
                'key' => $key,
                'viewable' => $key->getRawOriginal('token_encrypted') !== null,
                'masked' => $this->maskedOf($key, 'token_encrypted', $platforms),
                'auto' => $issued !== null && (int) $issued === (int) $key->id,
            ]),
            'nextKeyName' => 'Key '.($keys->count() + 1),
            'webhookSecret' => $this->maskedOf($app, 'webhook_secret', $platforms),
        ]);
    }

    public function issueKey(Request $request, ClientApp $app, PlatformApps $platforms): RedirectResponse
    {
        $data = $request->validate(['key_name' => ['required', 'string', 'max:255']], [], ['key_name' => 'name']);

        try {
            $issued = $platforms->issueKey($app, $data['key_name'], $this->actor($request));
        } catch (PlatformAppException $e) {
            return back()->withInput()->withErrors(['key_name' => $e->getMessage()]);
        }

        // The key's id only: the plaintext never goes into the session.
        return redirect(route('apps.show', $app).'#keys')
            ->with('status', 'Issued')->with('issued_key', $issued['key']->id);
    }

    public function revokeKey(Request $request, ClientApp $app, int $key, PlatformApps $platforms): RedirectResponse
    {
        $apiKey = $app->tokens()->whereKey($key)->firstOrFail();
        $back = redirect(route('apps.show', $app).'#keys');

        return $platforms->revokeKey($apiKey, $this->actor($request))
            ? $back->with('status', 'Revoked')
            : $back->withErrors(['keys' => 'This key is already revoked.']);
    }

    /**
     * `token:{id}` (a token of this app only) or `webhook`. Throttled, audited, never cached. Tokens are addressed by
     * id: prefixes are short and may repeat within an app.
     */
    public function reveal(Request $request, ClientApp $app, string $what, PlatformApps $platforms): JsonResponse
    {
        $actor = $this->actor($request);

        try {
            if ($what === 'webhook') {
                $value = $platforms->revealWebhookSecret($app, $actor);
            } else {
                abort_unless(preg_match('/^token:(\d{1,19})$/', $what, $m) === 1, 404);
                $key = $app->tokens()->usable()->whereKey((int) $m[1])->first();
                abort_if($key === null, 404);
                $value = $platforms->revealKey($key, $actor);
            }
        } catch (PlatformAppException $e) {
            return $this->noStore(response()->json(['message' => $e->getMessage()], 422));
        }

        return $this->noStore(response()->json(['value' => $value]));
    }

    public function regenerateWebhookSecret(Request $request, ClientApp $app, PlatformApps $platforms): RedirectResponse
    {
        $platforms->regenerateWebhookSecret($app, $this->actor($request));

        return redirect(route('apps.show', $app).'#webhook')->with('status', 'Regenerated');
    }

    public function ping(ClientApp $app, PlatformApps $platforms): RedirectResponse
    {
        $result = $platforms->ping($app);
        $back = redirect(route('apps.show', $app).'#webhook');

        return $result['ok'] ? $back->with('status', $result['message']) : $back->withErrors(['ping' => $result['message']]);
    }

    public function resendEvent(Request $request, ClientApp $app, OutgoingEvent $event, PlatformApps $platforms): RedirectResponse
    {
        abort_unless($event->clientApp()->is($app), 404);

        $result = $platforms->resend($event, $this->actor($request));
        $back = redirect(route('apps.show', $app).'#activity');

        return $result['ok'] ? $back->with('status', $result['message']) : $back->withErrors(['resend' => $result['message']]);
    }

    public function update(Request $request, ClientApp $app, PlatformApps $platforms): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'webhook_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'active' => ['required', 'boolean'],
        ]);

        try {
            $platforms->update($app, [
                'name' => $data['name'],
                'webhook_url' => $data['webhook_url'] ?? null,
                'status' => $data['active'] ? 'active' : 'disabled',
            ], $this->actor($request));
        } catch (PlatformAppException $e) {
            return back()->withInput()->withErrors([$this->field($e) => $e->getMessage()]);
        }

        return redirect(route('apps.show', $app))->with('status', 'Saved');
    }

    /** @return array{type: string, id: mixed, ip: ?string} */
    private function actor(Request $request): array
    {
        return ['type' => 'admin', 'id' => $request->user()->getAuthIdentifier(), 'ip' => $request->ip()];
    }

    /**
     * Length and last 4 of a stored secret, decrypted here and never passed on whole. Null when unset or unreadable.
     *
     * @return array{length: int, last4: string}|null
     */
    private function maskedOf(ApiToken|ClientApp $model, string $column, PlatformApps $platforms): ?array
    {
        if ($model->getRawOriginal($column) === null) {
            return null;
        }
        try {
            return $platforms->masked((string) $model->{$column});
        } catch (Throwable) {
            return null;
        }
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    private function field(PlatformAppException $e): string
    {
        return $e->field ?? 'name';
    }

    /**
     * Skipped events (nothing was sent) get their own neutral label.
     *
     * @return array{0: string, 1: string} label and badge tone
     */
    private function deliveryBadge(OutgoingEvent $e): array
    {
        return match (true) {
            $e->status === 'delivered' => ['Delivered', 'good'],
            $e->status === 'failed' => ['Failed', 'bad'],
            $e->status === 'skipped' => ['Skipped', 'mute'],
            $e->status === 'pending' && ($e->attempts > 0 || $e->last_error !== null) => ['Retrying', 'attn'],
            default => ['Pending', 'mute'],
        };
    }

    /** @return array{0: string, 1: string} label and badge tone */
    private function health(ClientApp $app, ?OutgoingEvent $event): array
    {
        if (blank($app->webhook_url)) {
            return ['No webhook', 'mute'];
        }
        if ($event === null) {
            return ['No deliveries', 'mute'];
        }
        if ($event->status === 'delivered') {
            return ['Delivering', 'good'];
        }
        if ($event->status === 'failed' || ($event->status === 'pending' && $event->last_error !== null)) {
            return ['Failing', 'bad'];
        }

        return ['No deliveries', 'mute'];
    }
}
