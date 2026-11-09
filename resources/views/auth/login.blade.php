@extends('layouts.guest')

@section('title', 'Sign in')

@section('content')
<form method="POST" action="{{ route('login') }}" class="lg-card" novalidate x-data="{ busy: false, show: false }" x-on:submit="busy = true" x-on:pageshow.window="busy = false">
    @csrf
    <div class="lg-brand">
        <span class="lg-mark">
            <svg width="17" height="17" viewBox="0 0 16 16" fill="none" stroke="#0b1228" stroke-width="2" aria-hidden="true"><path d="M2 5l6-3 6 3v6l-6 3-6-3z"/><path d="M2 5l6 3 6-3M8 8v6"/></svg>
        </span>
        Ventaship
    </div>
    <h1 class="lg-title">Sign in</h1>

    @if($errors->has('auth'))
        <div class="lg-alert" role="alert">
            <x-ui.icon name="alert-triangle" size="16" />
            {{ $errors->first('auth') }}
        </div>
    @endif

    <x-ui.field label="Username or email" name="login">
        <x-ui.input type="text" id="f-login" name="login" value="{{ old('login') }}" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus required
                    :class="$errors->has('login') ? 'is-invalid' : ''"
                    :aria-invalid="$errors->has('login') ? 'true' : null"
                    :aria-describedby="$errors->has('login') ? 'f-login-error' : null" />
    </x-ui.field>
    <x-ui.field label="Password" name="password">
        <div class="lg-pass">
            <x-ui.input type="password" id="f-password" name="password" autocomplete="current-password" required
                        x-bind:type="show ? 'text' : 'password'"
                        :class="$errors->has('password') ? 'is-invalid' : ''"
                        :aria-invalid="$errors->has('password') ? 'true' : null"
                        :aria-describedby="$errors->has('password') ? 'f-password-error' : null" />
            <button type="button" class="lg-eye" x-cloak x-on:click="show = ! show"
                    x-bind:aria-label="show ? 'Hide password' : 'Show password'" x-bind:aria-pressed="show ? 'true' : 'false'">
                <span x-show="! show"><x-ui.icon name="eye" size="16" /></span>
                <span x-show="show"><x-ui.icon name="eye-off" size="16" /></span>
            </button>
        </div>
    </x-ui.field>

    <label class="lg-check">
        <input type="checkbox" name="remember" value="1" @checked(session()->hasOldInput() ? old('remember') : true)>
        Keep me signed in
    </label>

    <x-ui.button type="submit" variant="primary" class="lg-submit" x-bind:disabled="busy">
        <span x-show="! busy">Sign in</span>
        <span x-show="busy" x-cloak>Signing in…</span>
    </x-ui.button>
    <div class="lg-foot">v0.1</div>
</form>
@endsection
