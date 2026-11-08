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

    <form id="bulk" method="POST" x-data="bulkSelect" @change="sync()">
        @csrf
        @if(Route::has('shipments.labels'))
            <div class="fl-bulk" x-show="count > 0" x-cloak>
                <span><span class="x-num" x-text="count"></span> selected</span>
                <span class="fl-bulk__sp"></span>
                <x-ui.button size="sm" type="submit" formaction="{{ route('shipments.labels') }}">Print waybills</x-ui.button>
                <x-ui.button size="sm" type="submit" formaction="{{ route('shipments.book') }}">Book</x-ui.button>
                <x-ui.button size="sm" @click="$dispatch('confirm', { title: 'Cancel ' + count + ' ' + (count === 1 ? 'shipment' : 'shipments') + '?', confirm: 'Cancel shipments', dismiss: 'Keep shipments', form: 'bulk', submitter: 'bulk-cancel' })">Cancel</x-ui.button>
                <button type="submit" id="bulk-cancel" formaction="{{ route('shipments.cancel') }}" hidden></button>
            </div>
        @endif
        @forelse($shipments as $shipment)
            @include('fulfillment._row', ['shipment' => $shipment])
        @empty
            <x-ui.empty title="No shipments" />
        @endforelse
    </form>

    @if(Route::has('shipments.book'))
        @foreach($shipments as $shipment)
            @if($shipment->state === 'draft')
                <form id="book-{{ $shipment->public_id }}" method="POST" action="{{ route('shipments.book') }}">
                    @csrf
                    <input type="hidden" name="ids[]" value="{{ $shipment->public_id }}">
                </form>
            @endif
        @endforeach
    @endif

    <x-ui.pager :paginator="$shipments" class="fl-pager" />
</section>
@endsection
