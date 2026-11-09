@extends('layouts.app')

@section('title', 'API')

@if($tab === 'keys')
    @section('actions')
        <x-ui.button variant="primary" id="create-key" :href="route('developers.index', ['tab' => 'keys', 'create' => 1])" x-on:click.prevent="$dispatch('create-key')">Create key</x-ui.button>
    @endsection
@endif

@section('content')
<nav class="x-tabs api-tabs" aria-label="API">
    @foreach(['keys' => ['API keys', $keyCount ?? null], 'logs' => ['Logs', null]] as $key => [$text, $count])
        <a class="x-tab {{ $tab === $key ? 'is-on' : '' }}" href="{{ route('developers.index', ['tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif>
            {{ $text }}@if($count !== null)<em class="x-tab__count">{{ $count }}</em>@endif
        </a>
    @endforeach
</nav>

@if($tab === 'keys')
    @include('developers.keys')
@else
    @include('developers.logs')
@endif
@endsection
