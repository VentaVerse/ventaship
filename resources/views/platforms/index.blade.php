@extends('layouts.app')

@section('title', 'Connected platforms')

@section('actions')
    <x-ui.button variant="primary" :href="route('apps.create')">Add platform</x-ui.button>
@endsection

@section('content')
@if($rows->isEmpty())
    <section class="x-panel pf-page">
        <x-ui.empty title="No platforms" />
    </section>
@else
<section class="x-panel pf-list pf-page">
    @foreach($rows as $row)
        @php $app = $row['app']; @endphp
        <div class="pf-row {{ $app->status === 'active' ? '' : 'pf-row--off' }}">
            <div class="pf-row__main">
                <div class="pf-row__name">{{ $app->name }}</div>
                <div class="pf-row__sub">
                    {{ $row['keys'] }} {{ $row['keys'] === 1 ? 'key' : 'keys' }}
                    · Last call {{ $row['last_call'] ? $row['last_call']->diffForHumans() : 'never' }}
                </div>
            </div>
            <div class="pf-row__badges">
                <x-ui.badge :tone="$app->status === 'active' ? 'good' : 'mute'">{{ $app->status === 'active' ? 'Active' : 'Disabled' }}</x-ui.badge>
                <x-ui.badge :tone="$row['health'][1]">{{ $row['health'][0] }}</x-ui.badge>
            </div>
            <div class="pf-row__act">
                <x-ui.button size="sm" :href="route('apps.show', $app)" aria-label="Open {{ $app->name }}">Open</x-ui.button>
            </div>
        </div>
    @endforeach
</section>
@endif
@endsection
