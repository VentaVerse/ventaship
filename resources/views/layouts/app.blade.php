<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title'){{ trim($__env->yieldContent('title')) }} · @endif{{ config('app.name', 'Ventaship') }}</title>
    @include('partials.theme-boot')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bl-app">
<a class="x-skip" href="#main">Skip to content</a>
<div x-data="{ nav: false }" class="x-shell">
    <aside class="x-side" :class="{ 'is-open': nav }" @keydown.escape.window="nav = false">
        <div class="x-side__head">
            <a class="x-logo" href="{{ Route::has('fulfillment') ? route('fulfillment') : url('/') }}">
                <span class="x-logo__mark">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="#0b1228" stroke-width="2" aria-hidden="true"><path d="M2 5l6-3 6 3v6l-6 3-6-3z"/><path d="M2 5l6 3 6-3M8 8v6"/></svg>
                </span>
                Ventaship
            </a>
            <button type="button" class="x-side__close" @click="nav = false" aria-label="Close navigation">
                <x-ui.icon name="x" size="16" />
            </button>
        </div>

        <div class="x-side__body">
            @include('partials.nav', ['navGroups' => \App\Support\Navigation::groups()])
        </div>

        <div class="x-side__foot" x-data="{ mode: (window.ventashipTheme && window.ventashipTheme.get()) || 'light' }">
            @php $adminName = auth()->user()?->name; @endphp
            @if($adminName)
                <span class="x-side__user">{{ $adminName }}</span>
            @endif
            <div class="x-theme">
                @foreach(['light' => 'sun', 'dark' => 'moon', 'system' => 'monitor'] as $mode => $icon)
                    <button type="button" class="x-theme__btn" :class="{ 'is-on': mode === '{{ $mode }}' }"
                            @click="mode = '{{ $mode }}'; window.ventashipTheme.set('{{ $mode }}')"
                            aria-label="{{ ucfirst($mode) }} theme">
                        <x-ui.icon :name="$icon" size="13" />
                    </button>
                @endforeach
            </div>
            @if(Route::has('logout'))
                <form method="POST" action="{{ route('logout') }}" class="x-side__out">
                    @csrf
                    <button type="submit" class="x-side__signout">
                        <x-ui.icon name="log-out" size="14" />
                        Sign out
                    </button>
                </form>
            @endif
        </div>
    </aside>

    <div class="x-scrim" x-show="nav" x-cloak @click="nav = false" aria-hidden="true"></div>

    <div class="x-main">
        <button type="button" class="x-menu" @click="nav = true" aria-label="Open navigation">
            <x-ui.icon name="menu" size="16" />
        </button>
        <main id="main" tabindex="-1" class="x-content">
            <header class="x-page">
                <div>
                    @hasSection('crumb')<div class="x-page__crumb">@yield('crumb')</div>@endif
                    <h1 class="x-page__title">@yield('title_lead')@yield('title')@yield('title_after')</h1>
                </div>
                @hasSection('actions')<div class="x-page__actions">@yield('actions')</div>@endif
            </header>
            @php $flashErrors = isset($errors) ? $errors->all() : []; $notice = session()->pull('notice'); @endphp
            @if(session('status') || $notice || $flashErrors)
                <div class="x-flash" role="status">
                    @if(session('status'))
                        <div class="x-flash__line x-flash__line--ok"><x-ui.icon name="check" size="14" />{{ session('status') }}</div>
                    @endif
                    @if($notice)
                        <div class="x-flash__line x-flash__line--ok"><x-ui.icon name="check" size="14" />{{ $notice }}</div>
                    @endif
                    @foreach($flashErrors as $message)
                        <div class="x-flash__line x-flash__line--bad"><x-ui.icon name="alert-triangle" size="14" />{{ $message }}</div>
                    @endforeach
                </div>
            @endif
            @yield('content')
        </main>
    </div>
</div>
@stack('forms')
<x-ui.confirm />
@stack('scripts')
</body>
</html>
