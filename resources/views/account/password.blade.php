@extends('layouts.app')

@section('title', 'Change password')
@section('crumb')<x-ui.back :href="route('account')">Account</x-ui.back>@endsection

@section('content')
<section class="x-panel st-panel" aria-labelledby="ac-password-h">
    <form method="POST" action="{{ route('account.password') }}" novalidate>
        @csrf
        @method('PUT')
        {{-- For password managers: which account this password belongs to. Not submitted. --}}
        <input type="email" class="x-sr" value="{{ $admin->email }}" autocomplete="username" tabindex="-1" aria-hidden="true" readonly>
        <div class="st-sec">
            <h2 class="st-h" id="ac-password-h">Change password</h2>
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
