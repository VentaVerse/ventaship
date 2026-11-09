@extends('layouts.app')

@section('width', 'form')
@section('title', 'Settings')

@section('content')
<section class="x-panel st-panel">
    <nav class="x-tabs" aria-label="Settings">
        @foreach($tabs as $key => $label)
            <a class="x-tab {{ $tab === $key ? 'is-on' : '' }}" href="{{ route('settings.edit', ['tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    @if($tab === 'general')
        <form method="POST" action="{{ route('settings.update') }}" novalidate>
            @csrf
            @method('PUT')
            <input type="hidden" name="tab" value="general">
            <div class="st-sec">
                <h2 class="st-h">Shipments</h2>
                <div class="st-row">
                    <x-ui.field label="Reference prefix" name="shipments_reference_prefix">
                        <x-ui.input id="f-shipments-reference-prefix" name="shipments_reference_prefix" class="st-ctl st-narrow x-mono"
                                    value="{{ old('shipments_reference_prefix', $prefix) }}" maxlength="8" autocomplete="off" />
                    </x-ui.field>
                </div>
            </div>
            <div class="st-sec">
                <h2 class="st-h">Privacy</h2>
                <div class="st-row">
                    <x-ui.field label="Keep recipient details for (days)" name="privacy_retention_days">
                        <x-ui.input type="number" id="f-privacy-retention-days" name="privacy_retention_days" class="st-ctl st-narrow"
                                    value="{{ old('privacy_retention_days', $retention) }}" min="30" max="3650" step="1" />
                    </x-ui.field>
                </div>
            </div>
            <div class="st-sec">
                <h2 class="st-h">Sender</h2>
                <div class="st-row">
                    <x-ui.field label="Name" name="pickup_contact_name">
                        <x-ui.input id="f-pickup-contact-name" name="pickup_contact_name" class="st-ctl" value="{{ old('pickup_contact_name', $sender['pickup_contact_name'] ?? '') }}" maxlength="255" autocomplete="off" />
                    </x-ui.field>
                </div>
                <div class="st-row">
                    <x-ui.field label="Phone" name="pickup_contact_phone">
                        <x-ui.input type="tel" id="f-pickup-contact-phone" name="pickup_contact_phone" class="st-ctl" value="{{ old('pickup_contact_phone', $sender['pickup_contact_phone'] ?? '') }}" maxlength="32" autocomplete="off" />
                    </x-ui.field>
                </div>
                <div class="st-row">
                    <x-ui.field label="Address" name="pickup_address_1">
                        <x-ui.input id="f-pickup-address-1" name="pickup_address_1" class="st-ctl" value="{{ old('pickup_address_1', $sender['pickup_address_1'] ?? '') }}" maxlength="255" autocomplete="off" />
                    </x-ui.field>
                </div>
                <div class="st-row">
                    <x-ui.field label="Address line 2" name="pickup_address_2">
                        <x-ui.input id="f-pickup-address-2" name="pickup_address_2" class="st-ctl" value="{{ old('pickup_address_2', $sender['pickup_address_2'] ?? '') }}" maxlength="255" autocomplete="off" />
                    </x-ui.field>
                </div>
                <div class="st-row">
                    <x-ph-address prefix="pickup_addr" :values="old('pickup_addr', $senderAddress)" />
                </div>
            </div>
            @include('settings._bar', ['tab' => 'general'])
        </form>
    @elseif($tab === 'couriers')
        <form method="POST" action="{{ route('settings.update') }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="tab" value="couriers">
            <div class="st-sec">
                <h2 class="st-h">Live booking</h2>
                @foreach(['safety_allow_live_lbc' => 'Allow live LBC bookings', 'safety_allow_live_spx' => 'Allow live SPX bookings'] as $field => $label)
                    <div class="st-row st-row--switch">
                        <label class="st-switch" for="f-{{ str_replace('_', '-', $field) }}">
                            <span class="st-switch__label">{{ $label }}</span>
                            <input type="hidden" name="{{ $field }}" value="0">
                            <input type="checkbox" class="st-switch__input" role="switch" id="f-{{ str_replace('_', '-', $field) }}" name="{{ $field }}" value="1" @checked(old($field, $switches[$field] ? '1' : '0') === '1')>
                            <span class="st-switch__track" aria-hidden="true"></span>
                        </label>
                    </div>
                @endforeach
            </div>
            <div class="st-sec">
                <h2 class="st-h">Webhooks</h2>
                <div class="st-row">
                    <x-ui.field label="Public address" name="webhooks_public_base_url">
                        <x-ui.input type="url" id="f-webhooks-public-base-url" name="webhooks_public_base_url" class="st-ctl x-mono"
                                    value="{{ old('webhooks_public_base_url', $publicAddress ?? '') }}" maxlength="255" placeholder="https://your-domain" autocomplete="off" />
                    </x-ui.field>
                </div>
            </div>
            @include('settings._bar', ['tab' => 'couriers'])
        </form>
    @elseif($tab === 'jobs')
        <div class="st-jobs" x-data="{ status: '' }" x-on:credential-status="status = ''; $nextTick(() => status = $event.detail)">
            <div class="st-sec">
                <h2 class="st-h">Scheduler</h2>
                <div class="st-row">
                    <div class="fm-field">
                        <span class="fm-label">Last run</span>
                        <span><x-ui.badge :tone="$cron['lastRun'][1]">{{ $cron['lastRun'][0] }}</x-ui.badge></span>
                    </div>
                </div>
                <div class="st-row">
                    <x-ui.copy-value label="Cron line" id="f-cron-line" :value="$cron['line']" />
                </div>
            </div>
            <div class="st-sec">
                <h2 class="st-h">HestiaCP</h2>
                @foreach($cron['fields'] as $field => $value)
                    <div class="st-row">
                        <x-ui.copy-value :label="$field" :id="'f-cron-' . strtolower($field)" :value="$value" :short="true" />
                    </div>
                @endforeach
                <div class="st-row">
                    <x-ui.copy-value label="Command" id="f-cron-command" :value="$cron['command']" />
                </div>
            </div>
            <p class="x-sr" aria-live="polite" x-text="status"></p>
        </div>
    @else
        <form method="POST" action="{{ route('settings.account') }}" novalidate>
            @csrf
            @method('PUT')
            <div class="st-sec">
                <h2 class="st-h">Profile</h2>
                <div class="st-row">
                    <x-ui.field label="Name" name="name">
                        <x-ui.input id="f-name" class="st-ctl" name="name" value="{{ old('name', $admin->name) }}" autocomplete="name" />
                    </x-ui.field>
                </div>
                <div class="st-row">
                    <x-ui.field label="Email" name="email">
                        <x-ui.input type="email" id="f-email" class="st-ctl" name="email" value="{{ old('email', $admin->email) }}" autocomplete="username" />
                    </x-ui.field>
                </div>
            </div>
            <div class="st-sec">
                <h2 class="st-h">Password</h2>
                <div class="st-row">
                    <x-ui.field label="Current password" name="current_password">
                        <x-ui.input type="password" id="f-current-password" class="st-ctl" name="current_password" autocomplete="current-password" />
                    </x-ui.field>
                </div>
                <div class="st-row">
                    <x-ui.field label="New password" name="password">
                        <x-ui.input type="password" id="f-password" class="st-ctl" name="password" autocomplete="new-password" />
                    </x-ui.field>
                </div>
                <div class="st-row">
                    <x-ui.field label="Confirm new password" name="password_confirmation">
                        <x-ui.input type="password" id="f-password-confirmation" class="st-ctl" name="password_confirmation" autocomplete="new-password" />
                    </x-ui.field>
                </div>
            </div>
            @include('settings._bar', ['tab' => 'account'])
        </form>
    @endif
</section>
@endsection
