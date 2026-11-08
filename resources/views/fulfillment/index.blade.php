@extends('layouts.app')

@section('title', 'Fulfillment')

@section('actions')
    @if(Route::has('imports.create'))
        <x-ui.button :href="route('imports.create')">Import CSV</x-ui.button>
    @endif
    @if(Route::has('shipments.create'))
        <x-ui.button variant="primary" :href="route('shipments.create')"><x-ui.icon name="plus" size="14" /> New shipment</x-ui.button>
    @endif
@endsection

@section('content')
@php
    $scope = array_filter(['account' => $account, 'source' => $source]);
    $poll = ['url' => route('fulfillment.poll'), 'query' => $scope, 'counts' => $counts];
@endphp

<div class="fl-grid">
    @foreach($cards as $card)
        @include('fulfillment._card', ['card' => $card])
    @endforeach
</div>

<section class="x-panel" x-data="fulfillmentPoll(@js($poll))">
    <nav class="x-tabs" aria-label="Status">
        @foreach(\App\Fulfillment\Tabs::LABELS as $key => $label)
            <a class="x-tab {{ $tab === $key ? 'is-on' : '' }}" href="{{ route('fulfillment', $scope + ['tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif>
                {{ $label }}<em class="x-tab__count x-num" x-text="counts.{{ $key }}">{{ $counts[$key] }}</em>
            </a>
        @endforeach
    </nav>

    <form method="GET" action="{{ route('fulfillment') }}" class="fl-filters" x-ref="filters">
        <input type="hidden" name="tab" value="{{ $tab }}">
        @if($chip)<input type="hidden" name="chip" value="{{ $chip }}">@endif

        @if($tab === 'to_ship')
            <div class="fl-chips">
                @foreach(\App\Fulfillment\Tabs::CHIP_LABELS as $key => $label)
                    <a class="fl-chip {{ $chip === $key ? 'is-on' : '' }}" href="{{ route('fulfillment', $scope + ['tab' => 'to_ship', 'chip' => $key]) }}">
                        {{ $label }} · <span class="x-num" x-text="counts.{{ $key }}">{{ $counts[$key] }}</span>
                    </a>
                @endforeach
            </div>
        @endif

        <x-ui.select wrapClass="fl-filters__select" name="account" aria-label="Courier" @change="$refs.filters.requestSubmit()">
            <option value="">All couriers</option>
            @foreach($accounts as $a)
                <option value="{{ $a->public_id }}" @selected($account === $a->public_id)>{{ $a->label }}</option>
            @endforeach
        </x-ui.select>

        <x-ui.select wrapClass="fl-filters__select" name="source" aria-label="Source" @change="$refs.filters.requestSubmit()">
            <option value="">All sources</option>
            <option value="manual" @selected($source === 'manual')>Manual</option>
            <option value="csv" @selected($source === 'csv')>CSV import</option>
            @foreach($apps as $app)
                <option value="api:{{ $app->public_id }}" @selected($source === 'api:'.$app->public_id)>{{ $app->name }}</option>
            @endforeach
        </x-ui.select>
    </form>

    <form id="bulk" method="POST" action="{{ Route::has('shipments.bulk') ? route('shipments.bulk') : '' }}">
        @csrf
        @forelse($shipments as $shipment)
            @include('fulfillment._row', ['shipment' => $shipment])
        @empty
            <x-ui.empty title="No shipments" />
        @endforelse
    </form>

    <x-ui.pager :paginator="$shipments" class="fl-pager" />
</section>
@endsection
