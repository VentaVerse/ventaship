@extends('layouts.app')

@section('title', 'Account')

@section('content')
@php
    $profileEmail = (string) old('email', $admin->email);
@endphp
<section class="x-panel st-panel" aria-labelledby="ac-profile-h">
    <form method="POST" action="{{ route('account.profile') }}" novalidate
          x-data="{ email: @js($profileEmail), saved: @js(\Illuminate\Support\Str::lower((string) $admin->email)), get emailChanged() { return this.email.trim().toLowerCase() !== this.saved; } }">
        @csrf
        @method('PUT')
        <div class="st-sec">
            <h2 class="st-h" id="ac-profile-h">Profile</h2>
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
</section>

<section class="x-panel st-panel" aria-labelledby="ac-password-h">
    <form method="POST" action="{{ route('account.password') }}" novalidate>
        @csrf
        @method('PUT')
        {{-- For password managers: which account this password belongs to. Not submitted. --}}
        <input type="email" class="x-sr" value="{{ $admin->email }}" autocomplete="username" tabindex="-1" aria-hidden="true" readonly>
        <div class="st-sec">
            <h2 class="st-h" id="ac-password-h">Password</h2>
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
@endsection
