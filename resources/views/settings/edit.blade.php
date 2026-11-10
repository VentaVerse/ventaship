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
                        <x-ui.input id="f-shipments-reference-prefix" name="shipments_reference_prefix" class="st-ctl st-narrow st-caps x-mono"
                                    value="{{ old('shipments_reference_prefix', $prefix) }}" maxlength="8" autocomplete="off"
                                    autocapitalize="characters" spellcheck="false" />
                    </x-ui.field>
                </div>
            </div>
            @include('settings._bar', ['label' => 'Save'])
        </form>
    @elseif($tab === 'jobs')
        <div class="st-sec">
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
    @else
        @php
            $profileEmail = (string) old('email', $admin->email);
        @endphp
        <form method="POST" action="{{ route('settings.account') }}" novalidate
              x-data="{ email: @js($profileEmail), saved: @js(\Illuminate\Support\Str::lower((string) $admin->email)), get emailChanged() { return this.email.trim().toLowerCase() !== this.saved; } }">
            @csrf
            @method('PUT')
            <div class="st-sec">
                <h2 class="st-h">Profile</h2>
                <div class="st-row">
                    <x-ui.field label="Name" name="name">
                        <x-ui.input id="f-name" class="st-ctl" name="name" value="{{ old('name', $admin->name) }}" maxlength="255" autocomplete="name" />
                    </x-ui.field>
                </div>
                <div class="st-row">
                    <x-ui.field label="Email" name="email">
                        <x-ui.input type="email" id="f-email" class="st-ctl" name="email" value="{{ $profileEmail }}" maxlength="255" autocomplete="username" x-model="email" />
                    </x-ui.field>
                </div>
                <div class="st-row" x-show="emailChanged" x-cloak>
                    <x-ui.field label="Current password" name="current_password" for="f-profile-current-password">
                        <x-ui.password id="f-profile-current-password" name="current_password" autocomplete="current-password" x-bind:disabled="! emailChanged" />
                    </x-ui.field>
                </div>
            </div>
            @include('settings._bar', ['label' => 'Save'])
        </form>
    @endif
</section>

@if($tab === 'account')
    <section class="x-panel st-panel" aria-labelledby="st-password-h">
        <form method="POST" action="{{ route('settings.password') }}" novalidate>
            @csrf
            @method('PUT')
            {{-- For password managers: which account this password belongs to. Not submitted. --}}
            <input type="email" class="x-sr" value="{{ $admin->email }}" autocomplete="username" tabindex="-1" aria-hidden="true" readonly>
            <div class="st-sec">
                <h2 class="st-h" id="st-password-h">Password</h2>
                <div class="st-row">
                    <x-ui.field label="Current password" name="current_password" for="f-current-password" bag="password">
                        <x-ui.password id="f-current-password" name="current_password" autocomplete="current-password" />
                    </x-ui.field>
                </div>
                <div class="st-row">
                    <x-ui.field label="New password" name="password" for="f-password" bag="password">
                        <x-ui.password id="f-password" name="password" autocomplete="new-password" />
                    </x-ui.field>
                </div>
                <div class="st-row">
                    <x-ui.field label="Confirm new password" name="password_confirmation" for="f-password-confirmation" bag="password">
                        <x-ui.password id="f-password-confirmation" name="password_confirmation" autocomplete="new-password" />
                    </x-ui.field>
                </div>
            </div>
            @include('settings._bar', ['label' => 'Change password'])
        </form>
    </section>
@endif
@endsection
