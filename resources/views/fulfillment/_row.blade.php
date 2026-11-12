@php
    use App\Fulfillment\Couriers;
    use App\Fulfillment\RowActions;
    use App\Fulfillment\ShipmentBadges;
    use App\Fulfillment\ShipmentList;
    use App\Fulfillment\StatusFilter;
    use App\Fulfillment\Views;
    use App\Shipments\TrackingLink;
    use App\Support\Money;

    $r = is_array($shipment->recipient) ? $shipment->recipient : null;
    $name = $r ? trim(((string) ($r['first_name'] ?? '')).' '.((string) ($r['last_name'] ?? ''))) : '';
    $name = $name !== '' ? $name : null;
    $isCod = ShipmentBadges::isCod($shipment);
    $amount = $isCod ? $shipment->cod_amount : $shipment->total;
    $tracking = $shipment->tracking_number ? TrackingLink::for($shipment) : null;
    $courierName = $shipment->courier ? Couriers::name($shipment->courier) : null;
    $ref = $shipment->client_reference ?: $shipment->courier_ref;
    $items = (int) $shipment->items_count;
    $flags = RowActions::flags($shipment);
    $next = RowActions::nextStep($shipment);
    $need = Views::needsAction($shipment);
    $show = route('shipments.show', $shipment->public_id);
@endphp
<div class="fl-row{{ $need ? ' is-need' : '' }}" role="row" data-id="{{ $shipment->public_id }}" data-status="{{ StatusFilter::keyFor($shipment) }}" data-printable="{{ (int) $flags['printable'] }}" data-bookable="{{ (int) $flags['bookable'] }}" data-cancellable="{{ (int) $flags['cancellable'] }}">
    <div class="fl-cell fl-cell--check" role="cell">
        <label class="fl-check">
            <input type="checkbox" class="fl-check__box" name="ids[]" value="{{ $shipment->public_id }}">
            <span class="x-sr">Select {{ $name ?: 'shipment' }}</span>
        </label>
    </div>
    <div class="fl-cell fl-cell--who" role="cell">
        <a class="fl-row__name fl-row__link{{ $name ? '' : ' is-removed' }}" href="{{ $show }}">{{ $name ?: 'Removed' }}</a>
        <div class="fl-row__meta">
            @if($ref)<span class="fl-mono">{{ $ref }}</span> · @endif{{ ShipmentList::sourceLabel($shipment) }} · {{ $items }} {{ \Illuminate\Support\Str::plural('item', $items) }}
        </div>
    </div>
    <div class="fl-cell fl-cell--courier" role="cell">
        @if($courierName)
            <span class="fl-row__courier fl-row__courier--{{ $shipment->courier }}"><span class="fl-row__dot" aria-hidden="true"></span>{{ $courierName }}</span>
        @endif
        @if($shipment->tracking_number)
            @if($tracking && preg_match('#^https?://#i', $tracking))
                <a class="fl-row__track" href="{{ $tracking }}" target="_blank" rel="noopener">{{ $shipment->tracking_number }}</a>
            @else
                <span class="fl-row__track is-plain">{{ $shipment->tracking_number }}</span>
            @endif
        @elseif(! $courierName)
            <span class="fl-row__none">No courier</span>
        @endif
    </div>
    <div class="fl-cell fl-cell--amt" role="cell">
        <span class="fl-row__amt x-num">{{ $amount !== null ? Money::peso((string) $amount) : '—' }}</span>
        @if($isCod)<span class="fl-row__cod">COD</span>@endif
    </div>
    <div class="fl-cell fl-cell--status" role="cell">
        <x-ui.badge :tone="ShipmentBadges::statusTone($shipment)">{{ StatusFilter::labelFor($shipment) }}</x-ui.badge>
    </div>
    <div class="fl-cell fl-cell--act" role="cell">
        @if($next && $next['kind'] === 'book')
            <x-ui.button size="sm" :variant="$next['variant']" type="submit" :form="'book-'.$shipment->public_id" class="fl-next">{{ $next['label'] }}</x-ui.button>
        @elseif($next)
            <x-ui.button size="sm" :variant="$next['variant']" :href="$next['href']" class="fl-next">{{ $next['label'] }}</x-ui.button>
        @endif
        <a class="fl-view" href="{{ $show }}" aria-label="View {{ $name ?: ($shipment->client_reference ?: $shipment->courier_ref) }}"><x-ui.icon name="eye" size="18" /></a>
    </div>
</div>
