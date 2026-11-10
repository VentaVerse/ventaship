@extends('layouts.guest')

@section('title', 'Sign in')

@section('content')
<form method="POST" action="{{ route('login') }}" class="lg-card" novalidate x-data="{ busy: false, show: false }" x-on:submit="busy = true" x-on:pageshow.window="busy = false">
    @csrf
    <div class="lg-brand">
        <img class="lg-logo lg-logo--light" src="{{ asset('brand/ventaship-logo-light.png') }}" alt="Ventaship" width="157" height="52">
        <img class="lg-logo lg-logo--dark" src="{{ asset('brand/ventaship-logo-dark.png') }}" alt="" width="165" height="52">
    </div>
    <h1 class="lg-title">Sign in</h1>

    @if($errors->has('auth'))
        <div class="lg-alert" role="alert">
            <x-ui.icon name="alert-triangle" size="16" />
            {{ $errors->first('auth') }}
        </div>
    @endif

    <x-ui.field label="Username or email" name="login">
        <x-ui.input type="text" id="f-login" name="login" value="{{ old('login') }}" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus required />
    </x-ui.field>
    <x-ui.field label="Password" name="password">
        <div class="lg-pass">
            <x-ui.input type="password" id="f-password" name="password" autocomplete="current-password" required
                        x-bind:type="show ? 'text' : 'password'" />
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
    <div class="lg-foot">@include('partials.version')</div>
</form>
@endsection
