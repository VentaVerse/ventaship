@extends('layouts.app')

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
            @include('settings._bar', ['tab' => 'couriers'])
        </form>
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
