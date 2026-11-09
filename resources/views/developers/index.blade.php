@extends('layouts.app')

@section('title', 'API')

@section('content')
<nav class="x-tabs api-tabs" aria-label="API">
    @foreach(['keys' => ['API keys', $keyCount], 'webhooks' => ['Webhooks', $endpointCount], 'logs' => ['Logs', null]] as $key => [$text, $count])
        <a class="x-tab {{ $tab === $key ? 'is-on' : '' }}" href="{{ route('developers.index', ['tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif>
            {{ $text }}@if($count !== null)<em class="x-tab__count">{{ $count }}</em>@endif
        </a>
    @endforeach
</nav>

@if($tab === 'keys')
    @include('developers.keys')
@elseif($tab === 'webhooks')
    @include('developers.webhooks')
@else
    @include('developers.logs')
@endif
@endsection
