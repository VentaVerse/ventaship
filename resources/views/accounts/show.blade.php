@extends('layouts.app')

@section('title', \App\Fulfillment\Couriers::name($account->courier) . ' · ' . $account->label)

@section('crumb')<a href="{{ route('accounts.index') }}">Courier accounts</a>@endsection

@section('actions')
    <form method="POST" action="{{ route('accounts.test', $account) }}" class="ac-actions">
        @csrf
        <x-ui.button type="submit">Test connection</x-ui.button>
    </form>
@endsection

@section('content')
@php
    use App\Fulfillment\Couriers;

    $name = Couriers::name($account->courier);
    $modeWords = ['staging' => 'Staging', 'uat' => 'UAT', 'production' => 'Live'];
    $check = $account->last_check_ok === null ? ['Not checked', 'mute'] : ($account->last_check_ok ? ['Connected', 'good'] : ['Check failed', 'bad']);
    $mode = old('mode', $account->mode);
    $isOn = fn (string $key, bool $current) => old($key, $current ? '1' : '0') === '1';
@endphp

<div class="ac-head">
    <x-courier-tile :courier="$account->courier" />
    <x-ui.badge tone="cool">{{ $modeWords[$account->mode] ?? ucfirst($account->mode) }}</x-ui.badge>
    <x-ui.badge :tone="$check[1]">{{ $check[0] }}</x-ui.badge>
    @if($account->status === 'inactive')
        <x-ui.badge>Inactive</x-ui.badge>
    @endif
</div>

<section class="x-panel ac-page">
    <form method="POST" action="{{ route('accounts.update', $account) }}" novalidate>
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
                                <input type="radio" class="ac-seg__input" name="mode" value="{{ $m }}" @checked($mode === $m)>
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

        {{-- Task 4: Keys section (masked secrets, follows #account-mode) goes here. --}}

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

        {{-- Task 5: Webhook block (and LBC tracking series) goes here. --}}

        <div class="st-bar">
            <x-ui.button :href="route('accounts.show', $account)">Discard</x-ui.button>
            <x-ui.button variant="primary" type="submit">Save</x-ui.button>
        </div>
    </form>
</section>
@endsection
