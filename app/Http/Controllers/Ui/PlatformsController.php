<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Models\ApiRequest;
use App\Models\ClientApp;
use App\Models\OutgoingEvent;
use App\Platforms\PlatformAppException;
use App\Platforms\PlatformApps;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformsController extends Controller
{
    public function index(): View
    {
        $apps = ClientApp::query()->withCount(['apiKeys as active_keys_count' => fn ($q) => $q->whereNull('revoked_at')])
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

    public function show(ClientApp $app): View
    {
        return view('platforms.show', ['app' => $app]);
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

    private function field(PlatformAppException $e): string
    {
        return str_contains($e->getMessage(), 'webhook URL') ? 'webhook_url' : 'name';
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
