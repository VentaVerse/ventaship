<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Models\ApiRequest;
use App\Models\ApiToken;
use App\Models\ClientApp;
use App\Models\OutgoingEvent;
use App\Platforms\PlatformAppException;
use App\Platforms\PlatformApps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * The API page: keys (one client app per key), webhook endpoints (an app's URL and signing secret) and logs. Keys
 * and secrets reach the page masked; View and Copy fetch them through reveal (POST, throttled, audited, no-store).
 */
class ApiController extends Controller
{
    private const TABS = ['keys', 'webhooks', 'logs'];

    private const LOG_TYPES = ['all', 'api', 'webhooks'];

    private const LOG_LIMIT = 100;

    public function index(Request $request, PlatformApps $platforms): View
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'keys';
        $apps = ClientApp::query()->where('status', 'active')
            ->with(['tokens' => fn ($q) => $q->usable()->orderBy('id')])
            ->orderBy('name')->orderBy('id')->get();
        $keys = $this->keyRows($apps, $request, $platforms);
        $hooked = $apps->filter(fn (ClientApp $app) => filled($app->webhook_url))->values();

        $data = [
            'tab' => $tab,
            'keyCount' => $keys->count(),
            'endpointCount' => $hooked->count(),
        ];

        if ($tab === 'keys') {
            $data += [
                'keys' => $keys,
                'creating' => $request->boolean('create') || $request->session()->hasOldInput('name'),
            ];
        } elseif ($tab === 'webhooks') {
            $data += [
                'endpoints' => $this->endpointRows($hooked, $request, $platforms),
                'freeApps' => $apps->reject(fn (ClientApp $app) => filled($app->webhook_url))->values(),
                'adding' => $request->boolean('add') || $request->session()->hasOldInput('app'),
            ];
        } else {
            $type = in_array($request->query('type'), self::LOG_TYPES, true) ? $request->query('type') : 'all';
            $data += ['type' => $type, 'logs' => $this->logRows($type)];
        }

        return view('developers.index', $data);
    }

    public function storeKey(Request $request, PlatformApps $platforms): RedirectResponse
    {
        $back = route('developers.index', ['tab' => 'keys', 'create' => 1]);
        $data = $this->checked($request, ['name' => ['required', 'string', 'max:255']], $back);
        if ($data instanceof RedirectResponse) {
            return $data;
        }
        $actor = $this->actor($request);

        try {
            $issued = DB::transaction(fn () => $platforms->issueKey($platforms->create($data['name'], null, $actor), $data['name'], $actor));
        } catch (PlatformAppException $e) {
            return redirect($back)->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return $this->toKeys('Created')->with('issued_key', $issued['key']->id);
    }

    /** A new key for the app, then the old one revoked. An app without a key gets its first. */
    public function rollKey(Request $request, ClientApp $app, PlatformApps $platforms): RedirectResponse
    {
        $old = $this->keyOf($request, $app);
        $actor = $this->actor($request);

        $issued = DB::transaction(function () use ($platforms, $app, $old, $actor) {
            $issued = $platforms->issueKey($app, $old->name ?? $app->name, $actor);
            if ($old !== null) {
                $platforms->revokeKey($old, $actor);
            }

            return $issued;
        });

        return $this->toKeys('Rolled')->with('issued_key', $issued['key']->id);
    }

    /** The app's name when it has one key (or none), else that key's own name. */
    public function renameKey(Request $request, ClientApp $app, PlatformApps $platforms): RedirectResponse
    {
        $key = $this->keyOf($request, $app);
        $back = route('developers.index', ['tab' => 'keys', 'rename' => $key ? 'key-'.$key->id : 'app-'.$app->public_id]);
        $data = $this->checked($request, ['name' => ['required', 'string', 'max:255']], $back, 'rename', false);
        if ($data instanceof RedirectResponse) {
            return $data;
        }
        $actor = $this->actor($request);

        try {
            if ($key !== null && $app->tokens()->usable()->count() > 1) {
                $platforms->renameKey($key, $data['name'], $actor);
            } else {
                $platforms->update($app, ['name' => $data['name']], $actor);
            }
        } catch (PlatformAppException $e) {
            return redirect($back)->withErrors(['rename' => $e->getMessage()]);
        }

        return $this->toKeys('Renamed');
    }

    /** Revokes the key; an app left without one is disabled, so it leaves the list (its history stays). */
    public function revokeKey(Request $request, ClientApp $app, PlatformApps $platforms): RedirectResponse
    {
        $key = $this->keyOf($request, $app);
        $actor = $this->actor($request);

        DB::transaction(function () use ($platforms, $app, $key, $actor) {
            if ($key !== null) {
                $platforms->revokeKey($key, $actor);
            }
            if (! $app->tokens()->usable()->exists()) {
                $platforms->update($app, ['status' => 'disabled'], $actor);
            }
        });

        return $this->toKeys('Revoked');
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

    public function storeEndpoint(Request $request, PlatformApps $platforms): RedirectResponse
    {
        $back = route('developers.index', ['tab' => 'webhooks', 'add' => 1]);
        $data = $this->checked($request, [
            'app' => ['required', 'string'],
            'webhook_url' => ['required', 'string', 'max:2048', 'url:http,https'],
        ], $back);
        if ($data instanceof RedirectResponse) {
            return $data;
        }

        $app = ClientApp::query()->where('public_id', $data['app'])->where('status', 'active')->whereNull('webhook_url')->first();
        if ($app === null) {
            return redirect($back)->withInput()->withErrors(['app' => 'Choose a key without an endpoint.']);
        }

        try {
            $platforms->update($app, ['webhook_url' => $data['webhook_url']], $this->actor($request));
        } catch (PlatformAppException $e) {
            return redirect($back)->withInput()->withErrors(['webhook_url' => $e->getMessage()]);
        }

        return $this->toWebhooks('Added');
    }

    public function updateEndpoint(Request $request, ClientApp $app, PlatformApps $platforms): RedirectResponse
    {
        $back = route('developers.index', ['tab' => 'webhooks', 'edit' => $app->public_id]);
        $data = $this->checked($request, ['webhook_url' => ['required', 'string', 'max:2048', 'url:http,https']], $back);
        if ($data instanceof RedirectResponse) {
            return $data;
        }

        try {
            $platforms->update($app, ['webhook_url' => $data['webhook_url']], $this->actor($request));
        } catch (PlatformAppException $e) {
            return redirect($back)->withInput()->withErrors(['webhook_url' => $e->getMessage()]);
        }

        return $this->toWebhooks('Saved');
    }

    public function destroyEndpoint(Request $request, ClientApp $app, PlatformApps $platforms): RedirectResponse
    {
        $platforms->update($app, ['webhook_url' => null], $this->actor($request));

        return $this->toWebhooks('Deleted');
    }

    public function testEndpoint(ClientApp $app, PlatformApps $platforms): RedirectResponse
    {
        $result = $platforms->ping($app);

        return $result['ok'] ? $this->toWebhooks($result['message']) : $this->toWebhooks()->withErrors(['ping' => $result['message']]);
    }

    public function rollSecret(Request $request, ClientApp $app, PlatformApps $platforms): RedirectResponse
    {
        $platforms->regenerateWebhookSecret($app, $this->actor($request));

        return $this->toWebhooks('Rolled');
    }

    public function resendEvent(Request $request, OutgoingEvent $event, PlatformApps $platforms): RedirectResponse
    {
        $result = $platforms->resend($event, $this->actor($request));
        $back = redirect(route('developers.index', ['tab' => 'logs']));

        return $result['ok'] ? $back->with('status', $result['message']) : $back->withErrors(['resend' => $result['message']]);
    }

    /**
     * One row per usable key of each active app, named "{app} · {key}" when the app has several; an app without a
     * key gets one row so it can be given one.
     *
     * @param  Collection<int, ClientApp>  $apps
     */
    private function keyRows(Collection $apps, Request $request, PlatformApps $platforms): Collection
    {
        // Set by store and roll for one request: the new key's id only. The page then reveals it through reveal.
        $issued = $request->session()->get('issued_key');
        $rename = (string) $request->query('rename', '');

        return $apps->flatMap(function (ClientApp $app) use ($issued, $rename, $platforms) {
            if ($app->tokens->isEmpty()) {
                return [[
                    'id' => 'app-'.$app->public_id, 'app' => $app, 'key' => null, 'name' => $app->name,
                    'created' => $app->created_at, 'last_used' => null, 'masked' => null, 'viewable' => false,
                    'auto' => false, 'renaming' => $rename === 'app-'.$app->public_id,
                ]];
            }
            $several = $app->tokens->count() > 1;

            return $app->tokens->map(fn (ApiToken $key) => [
                'id' => 'key-'.$key->id, 'app' => $app, 'key' => $key,
                'name' => $several ? $app->name.' · '.$key->name : $app->name,
                'rename_value' => $several ? $key->name : $app->name,
                'created' => $key->created_at, 'last_used' => $key->last_used_at,
                'masked' => $this->maskedOf($key, 'token_encrypted', $platforms),
                'viewable' => $key->getRawOriginal('token_encrypted') !== null,
                'auto' => $issued !== null && (int) $issued === (int) $key->id,
                'renaming' => $rename === 'key-'.$key->id,
            ]);
        })->map(fn (array $row) => $row + ['rename_value' => $row['name']])->values();
    }

    /** @param  Collection<int, ClientApp>  $apps */
    private function endpointRows(Collection $apps, Request $request, PlatformApps $platforms): Collection
    {
        $ids = $apps->modelKeys();
        // The newest delivery that was actually tried: a skipped event says nothing about the receiver.
        $latestIds = OutgoingEvent::query()->whereIn('client_app_id', $ids)->where('status', '!=', 'skipped')
            ->selectRaw('MAX(id) as id')->groupBy('client_app_id')->pluck('id');
        $latest = OutgoingEvent::query()->whereIn('id', $latestIds)->get(['id', 'client_app_id', 'status', 'last_error'])->keyBy('client_app_id');
        $edit = (string) $request->query('edit', '');

        return $apps->map(fn (ClientApp $app) => [
            'app' => $app,
            'health' => $this->health($latest[$app->id] ?? null),
            'secret' => $this->maskedOf($app, 'webhook_secret', $platforms),
            'editing' => $edit === $app->public_id,
        ]);
    }

    /** The newest API calls and webhook deliveries together, newest first. No bodies or headers. */
    private function logRows(string $type): Collection
    {
        $rows = collect();
        $names = ClientApp::query()->pluck('name', 'id');

        if ($type !== 'webhooks') {
            ApiRequest::query()->orderByDesc('created_at')->orderByDesc('id')->limit(self::LOG_LIMIT)
                ->get(['id', 'client_app_id', 'method', 'path', 'status', 'created_at'])
                ->each(function (ApiRequest $r) use ($rows, $names) {
                    $rows->push([
                        'kind' => 'api', 'at' => Carbon::parse($r->created_at), 'seq' => $r->id, 'call' => $r,
                        'app' => $names[$r->client_app_id] ?? null,
                        'badge' => [(string) $r->status, $r->status >= 500 ? 'bad' : ($r->status >= 400 ? 'attn' : 'good')],
                    ]);
                });
        }
        if ($type !== 'api') {
            OutgoingEvent::query()->with('shipment:id,public_id,client_reference')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(self::LOG_LIMIT)
                ->get(['id', 'public_id', 'client_app_id', 'type', 'shipment_id', 'status', 'attempts', 'last_error', 'created_at'])
                ->each(function (OutgoingEvent $e) use ($rows, $names) {
                    $rows->push([
                        'kind' => 'webhook', 'at' => $e->created_at, 'seq' => $e->id, 'event' => $e,
                        'app' => $names[$e->client_app_id] ?? null,
                        'badge' => $this->deliveryBadge($e),
                        'resendable' => in_array($e->status, ['failed', 'delivered'], true),
                    ]);
                });
        }

        return $rows->sort(fn (array $a, array $b) => [$b['at'], $b['seq']] <=> [$a['at'], $a['seq']])
            ->take(self::LOG_LIMIT)->values();
    }

    private function toKeys(?string $status = null): RedirectResponse
    {
        $to = redirect(route('developers.index', ['tab' => 'keys']));

        return $status === null ? $to : $to->with('status', $status);
    }

    private function toWebhooks(?string $status = null): RedirectResponse
    {
        $to = redirect(route('developers.index', ['tab' => 'webhooks']));

        return $status === null ? $to : $to->with('status', $status);
    }

    /**
     * The validated input, or a redirect to $back with the errors (under $errorKey when given) so the form there
     * opens again.
     *
     * @return array<string, mixed>|RedirectResponse
     */
    private function checked(Request $request, array $rules, string $back, ?string $errorKey = null, bool $keepInput = true): array|RedirectResponse
    {
        $validator = validator($request->all(), $rules, [], ['app' => 'key', 'webhook_url' => 'URL']);
        if (! $validator->fails()) {
            return $validator->validated();
        }
        $errors = $errorKey === null ? $validator->errors() : [$errorKey => $validator->errors()->first()];
        $to = redirect($back)->withErrors($errors);

        return $keepInput ? $to->withInput() : $to;
    }

    /** The posted `key` as one of this app's usable tokens, or null when none was posted. 404 for any other. */
    private function keyOf(Request $request, ClientApp $app): ?ApiToken
    {
        $id = $request->input('key');
        if ($id === null || $id === '') {
            return null;
        }
        abort_unless(is_scalar($id) && ctype_digit((string) $id), 404);

        return $app->tokens()->usable()->whereKey((int) $id)->firstOrFail();
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

    /** @return array{0: string, 1: string} label and badge tone */
    private function deliveryBadge(OutgoingEvent $e): array
    {
        $code = preg_match('/\bHTTP (\d{3})\b/', (string) $e->last_error, $m) === 1 ? ' · '.$m[1] : '';

        return match (true) {
            $e->status === 'delivered' => ['Delivered', 'good'],
            $e->status === 'failed' => ['Failed'.$code, 'bad'],
            $e->status === 'skipped' => ['Skipped', 'mute'],
            $e->status === 'pending' && ($e->attempts > 0 || $e->last_error !== null) => ['Retrying', 'attn'],
            default => ['Pending', 'mute'],
        };
    }

    /** @return array{0: string, 1: string} label and badge tone */
    private function health(?OutgoingEvent $event): array
    {
        return match (true) {
            $event?->status === 'delivered' => ['Delivering', 'good'],
            $event?->status === 'failed', $event?->status === 'pending' && $event->last_error !== null => ['Failing', 'bad'],
            default => ['No deliveries', 'mute'],
        };
    }
}
