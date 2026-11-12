@extends('layouts.app')

@section('title', 'Settings')

@section('content')
<section class="x-panel st-panel">
    <nav class="x-tabs" aria-label="Settings">
        @foreach($tabs as $key => $label)
            <a class="x-tab {{ $tab === $key ? 'is-on' : '' }}" href="{{ route('settings.edit', ['tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    @if($tab === 'jobs')
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
    @endif
</section>
@endsection
