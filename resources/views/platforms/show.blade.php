@extends('layouts.app')

@section('width', 'form')
@section('title', $app->name)

@section('title_after')
    <span class="pf-title-badges">
        <x-ui.badge :tone="$app->status === 'active' ? 'good' : 'mute'">{{ $app->status === 'active' ? 'Active' : 'Disabled' }}</x-ui.badge>
    </span>
@endsection

@section('crumb')<a href="{{ route('apps.index') }}">Connected platforms</a>@endsection

@section('content')
<section class="x-panel pf-page">
    <form method="POST" action="{{ route('apps.update', $app) }}" novalidate>
        @csrf
        @method('PUT')
        <div class="st-sec" id="general">
            <h2 class="st-h">General</h2>
            <div class="st-row">
                <x-ui.field label="Name" name="name" required>
                    <x-ui.input id="f-name" name="name" class="st-ctl" value="{{ old('name', $app->name) }}" maxlength="255" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <x-ui.field label="Webhook URL" name="webhook_url">
                    <x-ui.input type="url" id="f-webhook-url" name="webhook_url" class="st-ctl" value="{{ old('webhook_url', $app->webhook_url) }}" maxlength="2048" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row st-row--switch">
                <label class="st-switch" for="f-active">
                    <span class="st-switch__label">Active</span>
                    <input type="hidden" name="active" value="0">
                    <input type="checkbox" class="st-switch__input" role="switch" id="f-active" name="active" value="1" @checked(old('active', $app->status === 'active' ? '1' : '0') === '1')>
                    <span class="st-switch__track" aria-hidden="true"></span>
                </label>
            </div>
        </div>
        <div class="st-bar">
            <x-ui.button :href="route('apps.show', $app)">Discard</x-ui.button>
            <x-ui.button variant="primary" type="submit">Save</x-ui.button>
        </div>
    </form>

    <div class="st-sec" id="keys" x-data="{ status: '' }" x-on:credential-status="status = ''; $nextTick(() => status = $event.detail)">
        <h2 class="st-h">Keys</h2>
        @if($keys->isEmpty())
            <x-ui.empty title="No keys" />
        @else
            <div class="pf-keys-wrap">
                <table class="pf-keys">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Prefix</th>
                            <th scope="col">Created</th>
                            <th scope="col">Last used</th>
                            <th scope="col"><span class="x-sr">Actions</span></th>
                        </tr>
                    </thead>
                    @foreach($keys as $row)
                        @php $k = $row['key']; @endphp
                        <tbody class="pf-key">
                            <tr>
                                <td class="pf-key__name">{{ $k->name }}</td>
                                <td class="pf-key__mono">{{ $k->prefix }}</td>
                                <td data-label="Created">{{ $k->created_at?->format('j M Y') }}</td>
                                <td data-label="Last used">{{ $k->last_used_at ? $k->last_used_at->diffForHumans() : 'Never' }}</td>
                                <td class="pf-key__act">
                                    <x-ui.button size="sm" x-on:click="$dispatch('confirm', {{ Js::from(['title' => 'Revoke key '.$k->prefix.'?', 'confirm' => 'Revoke key', 'dismiss' => 'Keep key', 'form' => 'revoke-key-'.$k->id]) }})" :aria-label="'Revoke key ' . $k->prefix">Revoke</x-ui.button>
                                </td>
                            </tr>
                            <tr class="pf-key__creds">
                                <td colspan="5">
                                    @if($row['viewable'])
                                        <div class="st-row">
                                            <x-ui.secret label="API token" :context="$k->prefix" :masked="$row['masked']"
                                                :revealUrl="route('apps.reveal', [$app, 'token:' . $k->id])"
                                                :id="'f-token-' . $k->id" :autoView="$row['auto']" />
                                        </div>
                                    @else
                                        <p class="pf-key__legacy">Issued before keys were viewable</p>
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
        @endif
        <div class="st-row pf-issue">
            <x-ui.field label="Name" name="key_name" for="f-key-name">
                <x-ui.input id="f-key-name" name="key_name" form="issue-key" class="st-ctl" :value="old('key_name', $nextKeyName)" maxlength="255" autocomplete="off" />
            </x-ui.field>
            <x-ui.button type="submit" form="issue-key">Issue key</x-ui.button>
        </div>
        <p class="x-sr" aria-live="polite" x-text="status"></p>
    </div>

    <div class="st-sec" id="webhook" x-data="{ status: '' }" x-on:credential-status="status = ''; $nextTick(() => status = $event.detail)">
        <h2 class="st-h">Webhook</h2>
        <div class="st-row">
            <div class="fm-field">
                <span class="fm-label">URL</span>
                <div>
                    @if(filled($app->webhook_url))
                        <span class="pf-url">{{ $app->webhook_url }}</span>
                    @else
                        <x-ui.badge tone="attn">Not set</x-ui.badge>
                    @endif
                </div>
            </div>
        </div>
        <div class="st-row">
            <x-ui.secret label="Secret" context="webhook" :masked="$webhookSecret" :revealUrl="route('apps.reveal', [$app, 'webhook'])" id="f-webhook-secret">
                <x-ui.button size="sm" x-on:click="$dispatch('confirm', {{ Js::from(['title' => 'Regenerate the webhook secret?', 'confirm' => 'Regenerate', 'dismiss' => 'Keep secret', 'form' => 'regenerate-webhook']) }})" aria-label="Regenerate webhook secret">Regenerate</x-ui.button>
            </x-ui.secret>
        </div>
        <div class="st-row">
            <div class="fm-field">
                <span class="fm-label">Test</span>
                <div><x-ui.button type="submit" form="ping">Send test</x-ui.button></div>
            </div>
        </div>
        <p class="x-sr" aria-live="polite" x-text="status"></p>
    </div>
</section>
@endsection

@push('forms')
    <form id="issue-key" method="POST" action="{{ route('apps.keys.store', $app) }}" novalidate>@csrf</form>
    @foreach($keys as $row)
        <form id="revoke-key-{{ $row['key']->id }}" method="POST" action="{{ route('apps.keys.revoke', [$app, $row['key']->id]) }}">@csrf</form>
    @endforeach
    <form id="regenerate-webhook" method="POST" action="{{ route('apps.webhook.regenerate', $app) }}">@csrf</form>
    <form id="ping" method="POST" action="{{ route('apps.ping', $app) }}">@csrf</form>
@endpush
