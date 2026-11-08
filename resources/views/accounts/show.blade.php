@extends('layouts.app')

@php
    use App\Fulfillment\Couriers;

    $modeWords = ['staging' => 'Staging', 'uat' => 'UAT', 'production' => 'Live'];
    $check = $account->last_check_ok === null ? ['Not checked', 'mute'] : ($account->last_check_ok ? ['Connected', 'good'] : ['Check failed', 'bad']);
@endphp

@section('width', 'form')
@section('title', Couriers::name($account->courier) . ' · ' . $account->label)

@section('title_lead')<x-courier-tile :courier="$account->courier" class="ac-title-tile" />@endsection

@section('title_after')
    <span class="ac-title-badges">
        <x-ui.badge tone="cool">{{ $modeWords[$account->mode] ?? ucfirst($account->mode) }}</x-ui.badge>
        <x-ui.badge :tone="$check[1]">{{ $check[0] }}</x-ui.badge>
        @if($account->status === 'inactive')
            <x-ui.badge>Inactive</x-ui.badge>
        @endif
    </span>
@endsection

@section('crumb')<a href="{{ route('accounts.index') }}">Courier accounts</a>@endsection

@section('actions')
    <form method="POST" action="{{ route('accounts.test', $account) }}" class="ac-actions">
        @csrf
        <x-ui.button type="submit">Test connection</x-ui.button>
    </form>
@endsection

@section('content')
@php
    $name = Couriers::name($account->courier);
    $mode = old('mode', $account->mode);
    $isOn = fn (string $key, bool $current) => old($key, $current ? '1' : '0') === '1';
@endphp


<section class="x-panel ac-page">
    <form method="POST" action="{{ route('accounts.update', $account) }}" novalidate
          x-data="{ mode: @js($mode), modeWords: @js($modeWords), status: '' }"
          x-on:credential-status="status = ''; $nextTick(() => status = $event.detail)">
        @csrf
        @method('PUT')
        <div class="st-sec">
            <h2 class="st-h">Account</h2>
            <div class="st-row">
                <x-ui.field label="Name" name="label">
                    <x-ui.input id="f-label" name="label" class="st-ctl" value="{{ old('label', $account->label) }}" maxlength="255" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <div class="fm-field">
                    <span class="fm-label" id="account-mode-label">Mode</span>
                    <div class="ac-seg" id="account-mode" role="radiogroup" aria-labelledby="account-mode-label">
                        @foreach($modes as $m)
                            <label class="ac-seg__opt">
                                <input type="radio" class="ac-seg__input" name="mode" value="{{ $m }}" x-model="mode" @checked($mode === $m)>
                                <span class="ac-seg__tick" aria-hidden="true"><x-ui.icon name="check" size="14" /></span>
                                <span>{{ $modeWords[$m] ?? ucfirst($m) }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('mode')<div class="fm-error">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="st-row st-row--switch">
                <label class="st-switch" for="f-default">
                    <span class="st-switch__label">Default for {{ $name }}</span>
                    <input type="hidden" name="default" value="0">
                    <input type="checkbox" class="st-switch__input" role="switch" id="f-default" name="default" value="1" @checked($isOn('default', (bool) $account->is_default))>
                    <span class="st-switch__track" aria-hidden="true"></span>
                </label>
            </div>
            <div class="st-row st-row--switch">
                <label class="st-switch" for="f-active">
                    <span class="st-switch__label">Active</span>
                    <input type="hidden" name="active" value="0">
                    <input type="checkbox" class="st-switch__input" role="switch" id="f-active" name="active" value="1" @checked($isOn('active', $account->status === 'active'))>
                    <span class="st-switch__track" aria-hidden="true"></span>
                </label>
            </div>
            @if($hasLatch)
                <div class="st-row st-row--switch">
                    <label class="st-switch" for="f-allow-live">
                        <span class="st-switch__label">Allow live booking</span>
                        <input type="hidden" name="allow_live_booking" value="0">
                        <input type="checkbox" class="st-switch__input" role="switch" id="f-allow-live" name="allow_live_booking" value="1" @checked($isOn('allow_live_booking', (bool) $account->allow_live_booking))>
                        <span class="st-switch__track" aria-hidden="true"></span>
                    </label>
                </div>
            @endif
        </div>

        @if($keyGroups !== [])
            <div class="st-sec">
                <h2 class="st-h ac-keys-h">Keys
                    <x-ui.badge :tone="$mode === 'production' ? 'good' : 'cool'" data-keys-badge x-bind:class="{ 'x-badge--good': mode === 'production', 'x-badge--cool': mode !== 'production' }"><span x-text="modeWords[mode] ?? mode">{{ $modeWords[$mode] ?? ucfirst($mode) }}</span></x-ui.badge>
                </h2>
                @foreach($keyGroups as $group)
                    @php $groupMode = $group['mode']; @endphp
                    <div class="ac-keys" data-keys="{{ $group['scope'] ?? 'all' }}"
                         @if($groupMode !== null) x-bind:hidden="mode !== @js($groupMode)" @endif
                         @if($groupMode !== null && $groupMode !== $mode) hidden @endif>
                        @foreach($group['keys'] as $k)
                            <div class="st-row">
                                <x-ui.credential
                                    :name="'secrets[' . $k['key'] . ']'"
                                    :label="$k['label']"
                                    :account="$account"
                                    :key="$k['key']"
                                    :masked="$k['masked']"
                                    :revealUrl="route('accounts.reveal', [$account, $k['key']])"
                                    :context="$groupMode === null ? null : ($modeWords[$groupMode] ?? ucfirst($groupMode))"
                                    :id="'f-key-' . $k['key']" />
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif

        @if($fields !== [])
            <div class="st-sec">
                <h2 class="st-h">Booking defaults</h2>
                @foreach($fields as $f)
                    @php $id = 'f-set-' . $f['key']; $val = old('settings.' . $f['key'], $f['value']); @endphp
                    <div class="st-row">
                        <x-ui.field :label="$f['label']" :for="$id" :errorKey="'settings.' . $f['key']">
                            @if(($f['type'] ?? 'text') === 'select')
                                <x-ui.select :id="$id" :name="'settings[' . $f['key'] . ']'" class="st-ctl" wrapClass="st-ctl">
                                    <option value="">Not set</option>
                                    @foreach($f['options'] ?? [] as $opt)
                                        <option value="{{ $opt['value'] }}" @selected($val === $opt['value'])>{{ $opt['label'] }}</option>
                                    @endforeach
                                </x-ui.select>
                            @else
                                <x-ui.input :id="$id" :name="'settings[' . $f['key'] . ']'" class="st-ctl" value="{{ $val }}" placeholder="{{ $f['placeholder'] ?? '' }}" maxlength="2048" autocomplete="off" />
                            @endif
                        </x-ui.field>
                    </div>
                @endforeach
            </div>
        @endif

        @if($webhook)
            <div class="st-sec">
                <h2 class="st-h">Webhook</h2>
                <div class="st-row">
                    @if($webhook['masked'])
                        <x-ui.webhook-url :label="'Address for ' . $name" :masked="$webhook['masked']" :revealUrl="route('accounts.reveal', [$account, 'webhook_url'])" />
                    @else
                        <div class="fm-field">
                            <span class="fm-label">Address for {{ $name }}</span>
                            <div><x-ui.badge tone="attn">Not set</x-ui.badge></div>
                        </div>
                    @endif
                </div>
                <div class="st-row">
                    <div class="fm-field">
                        <span class="fm-label">Public address</span>
                        <div class="ac-addr">
                            @if($webhook['base'])
                                <span class="ac-addr__url">{{ $webhook['base'] }}</span>
                            @else
                                <x-ui.badge tone="attn">Not set</x-ui.badge>
                            @endif
                            <x-ui.button size="sm" :href="route('settings.edit', ['tab' => 'couriers'])">{{ $webhook['base'] ? 'Change' : 'Set address' }}</x-ui.button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if($blocks !== null)
            <div class="st-sec">
                <h2 class="st-h">Number blocks</h2>
                @if($blocks === [])
                    <x-ui.empty title="No blocks" />
                @else
                    <div class="ac-blocks-wrap">
                        <table class="ac-blocks">
                            <thead><tr><th scope="col">First</th><th scope="col">Last</th><th scope="col">Next</th><th scope="col">Remaining</th></tr></thead>
                            <tbody>
                                @foreach($blocks as $b)
                                    <tr>
                                        <td>{{ $b['first'] }}</td>
                                        <td>{{ $b['last'] }}</td>
                                        <td>{{ $b['next'] ?? '–' }}</td>
                                        <td>{{ number_format($b['remaining']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <div class="st-row ac-block-add">
                    <x-ui.field label="First" name="first" for="f-block-first">
                        <x-ui.input id="f-block-first" name="first" form="series-form" class="st-ctl" value="{{ old('first') }}" inputmode="numeric" maxlength="20" autocomplete="off" />
                    </x-ui.field>
                    <x-ui.field label="Last" name="last" for="f-block-last">
                        <x-ui.input id="f-block-last" name="last" form="series-form" class="st-ctl" value="{{ old('last') }}" inputmode="numeric" maxlength="20" autocomplete="off" />
                    </x-ui.field>
                    <x-ui.button type="submit" form="series-form">Add block</x-ui.button>
                </div>
            </div>
        @endif

        <p class="x-sr" aria-live="polite" x-text="status"></p>

        <div class="st-bar">
            <x-ui.button :href="route('accounts.show', $account)">Discard</x-ui.button>
            <x-ui.button variant="primary" type="submit">Save</x-ui.button>
        </div>
    </form>
    @if($blocks !== null)
        <form id="series-form" method="POST" action="{{ route('accounts.series', $account) }}" novalidate>@csrf</form>
    @endif
</section>
@endsection
