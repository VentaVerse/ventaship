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
@endsection
