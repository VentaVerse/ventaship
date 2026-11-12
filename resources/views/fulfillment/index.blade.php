@extends('layouts.app')

@section('title', 'Shipments')

@section('actions')
    @if(Route::has('imports.create'))
        <x-ui.button :href="route('imports.create')">Import CSV</x-ui.button>
    @endif
    @if(Route::has('shipments.create'))
        <x-ui.button variant="primary" :href="route('shipments.create')"><x-ui.icon name="plus" size="14" /> Add shipment</x-ui.button>
    @endif
@endsection

@section('content')
@php
    use App\Fulfillment\Couriers;

    $scope = array_filter(['account' => $account, 'source' => $source, 'status' => $status, 'q' => $q], fn ($v) => $v !== '');
    $poll = ['url' => route('fulfillment.poll'), 'query' => $scope, 'counts' => $counts];
    $filtered = $view !== 'all' || $scope !== [];
    $hasActions = Route::has('shipments.labels');
    $bulk = $hasActions ? [
        'labels' => route('shipments.labels'),
        'book' => route('shipments.book'),
        'cancel' => route('shipments.cancel'),
    ] : [];
    $searchLabel = 'Name, order ref or tracking number';
@endphp

<ul class="fl-chips" aria-label="Couriers">
    @foreach($chips as $chip)
        <li class="fl-chip">
            <x-courier-tile :courier="$chip['courier']" />
            <span class="x-sr">{{ Couriers::name($chip['courier']) }}</span>
            @if($chip['active'] === null)
                <span class="fl-chip__none" aria-hidden="true">—</span><span class="x-sr">No account</span>
            @else
                <span><strong class="x-num">{{ $chip['active'] }}</strong> active</span>
            @endif
        </li>
    @endforeach
</ul>

<div class="fl-grid">
    @foreach($cards as $card)
        @include('fulfillment._card', ['card' => $card])
    @endforeach
</div>

<section class="x-panel fl-panel" x-data="fulfillmentPoll(@js($poll))">
    <form method="GET" action="{{ route('fulfillment') }}" class="fl-filters" x-ref="filters" role="search">
        <input type="hidden" name="view" value="{{ $view }}">

        <nav class="fl-seg" aria-label="View">
            @foreach(\App\Fulfillment\Views::LABELS as $key => $label)
                <a class="fl-seg__opt {{ $view === $key ? 'is-on' : '' }}" href="{{ route('fulfillment', $scope + ['view' => $key]) }}" @if($view === $key) aria-current="page" @endif>{{ $label }}<em class="fl-seg__count{{ $key === 'needs' && $counts['needs'] > 0 ? ' is-bad' : '' }}" @if($key === 'needs') :class="{ 'is-bad': counts.needs }" @endif x-text="counts.{{ $key }}">{{ $counts[$key] }}</em></a>
            @endforeach
        </nav>

        <div class="fl-search">
            <x-ui.icon name="search" size="15" class="fl-search__icon" />
            <input type="search" name="q" value="{{ $q }}" maxlength="100" class="x-input fl-search__input" placeholder="{{ $searchLabel }}" aria-label="{{ $searchLabel }}" autocomplete="off" enterkeyhint="search">
        </div>

        <x-ui.select wrapClass="fl-filters__select" name="account" aria-label="Courier" @change="$refs.filters.requestSubmit()">
            <option value="">All couriers</option>
            @foreach($accounts as $a)
                @php $courierName = Couriers::name($a->courier); @endphp
                <option value="{{ $a->public_id }}" @selected($account === $a->public_id)>{{ strcasecmp(trim((string) $a->label), $courierName) === 0 ? $courierName : $courierName.' · '.$a->label }}</option>
            @endforeach
        </x-ui.select>

        <x-ui.select wrapClass="fl-filters__select" name="source" aria-label="Source" @change="$refs.filters.requestSubmit()">
            <option value="">All sources</option>
            <option value="manual" @selected($source === 'manual')>Manual</option>
            @foreach($apps as $app)
                <option value="api:{{ $app->public_id }}" @selected($source === 'api:'.$app->public_id)>{{ $app->name }}</option>
            @endforeach
        </x-ui.select>

        <x-ui.select wrapClass="fl-filters__select" name="status" aria-label="Status" @change="$refs.filters.requestSubmit()">
            <option value="">All statuses</option>
            @foreach($statuses as $opt)
                <option value="{{ $opt['key'] }}" @selected($status === $opt['key'])>{{ $opt['courier'] ? Couriers::name($opt['courier']).' · ' : '' }}{{ $opt['label'] }} ({{ $opt['count'] }})</option>
            @endforeach
        </x-ui.select>
    </form>

    <form id="bulk" method="POST" x-data="bulkSelect(@js($bulk))" @change="sync()">
        @csrf
        <p class="x-sr" aria-live="polite" x-text="count > 0 ? count + ' selected' : ''"></p>
        @if($hasActions)
            <div class="fl-bulk" x-show="count > 0" x-cloak>
                <b class="fl-bulk__count" aria-hidden="true"><span class="x-num" x-text="count"></span> selected</b>
                <x-ui.button size="sm" type="submit" :formaction="route('shipments.labels')" formtarget="_blank" x-bind:disabled="n.printable === 0 || busy" @click.prevent="print()"><span x-text="label('Print waybills', n.printable)">Print waybills</span></x-ui.button>
                <x-ui.button size="sm" type="submit" :formaction="route('shipments.book')" x-bind:disabled="n.bookable === 0" @click.prevent="run('bookable', 'book')"><span x-text="label('Book', n.bookable)">Book</span></x-ui.button>
                <x-ui.button size="sm" type="submit" :formaction="route('shipments.cancel')" x-bind:disabled="n.cancellable === 0" @click.prevent="confirmCancel()"><span x-text="label('Cancel shipments', n.cancellable)">Cancel shipments</span></x-ui.button>
                <x-ui.button size="sm" @click="clear()">Deselect</x-ui.button>
            </div>
            <div class="fl-alert" role="alert" x-show="printError" x-cloak>
                <x-ui.icon name="alert-triangle" size="16" class="fl-alert__icon" />
                <div>
                    <b x-text="printError ? printError.title : ''"></b>
                    <ul class="fl-alert__list" x-show="printError && printError.items.length">
                        <template x-for="item in (printError ? printError.items : [])" :key="item.id">
                            <li x-text="item.line"></li>
                        </template>
                    </ul>
                </div>
            </div>
        @endif

        @if($shipments->isEmpty())
            @if($filtered)
                <x-ui.empty title="No shipments">
                    <x-slot:action><x-ui.button :href="route('fulfillment')">Clear filters</x-ui.button></x-slot:action>
                </x-ui.empty>
            @else
                <x-ui.empty title="No shipments" />
            @endif
        @else
            <div class="fl-table" role="table" aria-label="Shipments">
                <div class="fl-table__head" role="rowgroup">
                    <div class="fl-row fl-row--head" role="row">
                        <div class="fl-th" role="columnheader"><span class="x-sr">Select</span></div>
                        <div class="fl-th" role="columnheader">Recipient</div>
                        <div class="fl-th" role="columnheader">Courier</div>
                        <div class="fl-th fl-th--amt" role="columnheader">Amount</div>
                        <div class="fl-th" role="columnheader">Status</div>
                        <div class="fl-th" role="columnheader"><span class="x-sr">Next step</span></div>
                    </div>
                </div>
                <div role="rowgroup">
                    @foreach($shipments as $shipment)
                        @include('fulfillment._row', ['shipment' => $shipment])
                    @endforeach
                </div>
            </div>
        @endif
    </form>

    @if($hasActions)
        <form id="bulk-run" method="POST" action="{{ route('shipments.book') }}" hidden>@csrf</form>
    @endif

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
