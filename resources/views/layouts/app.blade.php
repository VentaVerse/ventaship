<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('doc_title'){{ trim($__env->yieldContent('doc_title')) }} · @elseif($__env->hasSection('title')){{ trim($__env->yieldContent('title')) }} · @endif{{ config('app.name', 'Ventaship') }}</title>
    @include('partials.theme-boot')
    {{-- The fonts are fetched with the stylesheet, not after it: no fallback-font frame, no text reflow on each page. --}}
    <link rel="preload" href="{{ Vite::asset('resources/fonts/Archivo-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bl-app">
<a class="x-skip" href="#main">Skip to content</a>
<div x-data="{
        nav: false,
        openNav() { this.nav = true; this.$nextTick(() => this.$refs.navClose.focus()); },
        closeNav() { if (! this.nav) { return; } this.nav = false; this.$nextTick(() => this.$refs.navOpen.focus()); },
     }" class="x-shell">
    <aside class="x-side" id="x-nav" :class="{ 'is-open': nav }" @keydown.escape.window="closeNav()">
        <div class="x-side__head">
            <a class="x-logo" href="{{ Route::has('fulfillment') ? route('fulfillment') : url('/') }}">
                <span class="x-logo__mark">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="#0b1228" stroke-width="2" aria-hidden="true"><path d="M2 5l6-3 6 3v6l-6 3-6-3z"/><path d="M2 5l6 3 6-3M8 8v6"/></svg>
                </span>
                Ventaship
            </a>
            <button type="button" class="x-side__close" x-ref="navClose" @click="closeNav()" aria-label="Close navigation">
                <x-ui.icon name="x" size="16" />
            </button>
        </div>

        <div class="x-side__body">
            @include('partials.nav', ['navGroups' => \App\Support\Navigation::groups()])
        </div>

        <div class="x-side__foot">
            <span class="x-side__ver">Ventaship {{ \App\Support\AppVersion::current() }}</span>
        </div>
    </aside>

    <div class="x-scrim" x-show="nav" x-cloak @click="closeNav()" aria-hidden="true"></div>

    <div class="x-main">
        <div class="x-top">
            <div class="x-top__in">
                <button type="button" class="x-menu" x-ref="navOpen" @click="openNav()" aria-label="Open navigation" aria-controls="x-nav" aria-expanded="false" :aria-expanded="nav.toString()">
                    <x-ui.icon name="menu" size="16" />
                </button>
                @auth
                    @include('partials.profile-menu', ['admin' => auth()->user()])
                @endauth
            </div>
        </div>
        <main id="main" tabindex="-1" class="x-content">
            <header class="x-page">
                <div>
                    @hasSection('crumb')<div class="x-page__crumb">@yield('crumb')</div>@endif
                    <h1 class="x-page__title">@yield('title_lead')@yield('title')@yield('title_after')</h1>
                    @hasSection('title_meta')<div class="x-page__meta">@yield('title_meta')</div>@endif
                </div>
                @hasSection('actions')<div class="x-page__actions">@yield('actions')</div>@endif
            </header>
            @php
                // Errors shown under their own field are not repeated here; the rest (an expired form, a refused action) are.
                $flashErrors = \App\View\InlineErrors::rest($errors ?? null);
                $notice = session()->pull('notice');
                // A refusal that names another record: its text with that record linked (all parts escaped).
                $flashLink = is_array(session('flash_link')) ? session('flash_link') : null;
            @endphp
            @if(session('status') || $notice || $flashErrors || $flashLink)
                <div class="x-flash" role="status">
                    @if(session('status'))
                        <div class="x-flash__line x-flash__line--ok"><x-ui.icon name="check" size="14" />{{ session('status') }}</div>
                    @endif
                    @if($notice)
                        <div class="x-flash__line x-flash__line--ok"><x-ui.icon name="check" size="14" />{{ $notice }}</div>
                    @endif
                    @if($flashLink)
                        <div class="x-flash__line x-flash__line--bad"><x-ui.icon name="alert-triangle" size="14" /><span>{{ $flashLink['before'] ?? '' }}<a class="x-flash__link" href="{{ $flashLink['href'] ?? '#' }}">{{ $flashLink['label'] ?? '' }}</a>{{ $flashLink['after'] ?? '' }}</span></div>
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
