@extends('layouts.guest')

@section('title', 'Sign in')

@section('content')
<form method="POST" action="{{ route('login') }}" class="lg-card" novalidate>
    @csrf
    <div class="lg-brand">
        <span class="lg-mark">
            <svg width="17" height="17" viewBox="0 0 16 16" fill="none" stroke="#0b1228" stroke-width="2" aria-hidden="true"><path d="M2 5l6-3 6 3v6l-6 3-6-3z"/><path d="M2 5l6 3 6-3M8 8v6"/></svg>
        </span>
        Ventaship
    </div>
    <h1 class="lg-title">Sign in</h1>

    @if($errors->has('login') || $errors->has('password'))
        <div class="lg-alert" role="alert">
            <x-ui.icon name="alert-triangle" size="16" />
            {{ $errors->first('login') ?: $errors->first('password') }}
        </div>
    @endif

    <x-ui.field label="Username" name="login" errorKey="_none">
        <x-ui.input type="text" id="f-login" name="login" value="{{ old('login') }}" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus required
                    :class="$errors->any() ? 'is-invalid' : ''" />
    </x-ui.field>
    <x-ui.field label="Password" name="password" errorKey="_none">
        <x-ui.input type="password" id="f-password" name="password" autocomplete="current-password" required
                    :class="$errors->any() ? 'is-invalid' : ''" />
    </x-ui.field>

    <label class="lg-check">
        <input type="checkbox" name="remember" value="1" @checked(session()->hasOldInput() ? old('remember') : true)>
        Keep me signed in
    </label>

    <x-ui.button type="submit" variant="primary" class="lg-submit">Sign in</x-ui.button>
    <div class="lg-foot">v0.1</div>
</form>
@endsection
