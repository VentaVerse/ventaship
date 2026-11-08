@php
    use App\Fulfillment\ShipmentBadges;
    use App\Fulfillment\ShipmentList;
    use App\Fulfillment\StatusFilter;
    use App\Shipments\TrackingLink;
    use App\Support\Money;

    $r = is_array($shipment->recipient) ? $shipment->recipient : null;
    $name = $r ? trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')) : '';
    $name = $name !== '' ? $name : null;
    $city = $r['city'] ?? null;
    $isCod = str_starts_with((string) $shipment->payment_state, 'cod_');
    $amount = $isCod ? $shipment->cod_amount : $shipment->total;
    $pay = ShipmentBadges::payment($shipment);
    $statusTone = ShipmentBadges::statusTone($shipment);
    $statusKey = StatusFilter::keyFor($shipment);
    $statusLabel = StatusFilter::labelFor($shipment);
    $tracking = $shipment->tracking_number ? TrackingLink::for($shipment) : null;
    $courierName = $shipment->courier ? \App\Fulfillment\Couriers::name($shipment->courier) : null;
    $service = $shipment->service ? \Illuminate\Support\Str::title(str_replace('_', ' ', $shipment->service)) : null;
    $refs = collect([$shipment->courier_ref, $shipment->client_reference])->filter()->implode(' · ');
@endphp
<div class="fl-row" data-id="{{ $shipment->public_id }}" data-status="{{ $statusKey }}">
    <input type="checkbox" class="fl-row__check" name="ids[]" value="{{ $shipment->public_id }}" aria-label="Select {{ $name ?: 'shipment' }}">
    <div>
        <div class="fl-row__name {{ $name ? '' : 'is-removed' }}"><a class="fl-row__link" href="{{ route('shipments.show', $shipment->public_id) }}">{{ $name ?: 'Removed' }}</a></div>
        <div class="fl-row__ref">{{ $refs }}</div>
        <div class="fl-row__addr">{{ collect([$city, $shipment->items_count.' '.\Illuminate\Support\Str::plural('item', (int) $shipment->items_count)])->filter()->implode(' · ') }}</div>
    </div>
    <div>
        @if($shipment->tracking_number)
            <div class="fl-row__courier fl-row__courier--{{ $shipment->courier }}"><span class="fl-row__dot" aria-hidden="true"></span>{{ collect([$courierName, $service])->filter()->implode(' · ') }}</div>
            @if($tracking && preg_match('#^https?://#i', $tracking))
                <a class="fl-row__track" href="{{ $tracking }}" target="_blank" rel="noopener">{{ $shipment->tracking_number }}</a>
            @else
                <span class="fl-row__track is-plain">{{ $shipment->tracking_number }}</span>
            @endif
        @else
            <div class="fl-row__courier is-none">Not booked</div>
        @endif
    </div>
    <div class="fl-row__pay">
        <span class="fl-row__amt x-num">{{ $amount !== null ? Money::peso((string) $amount) : '—' }}</span>
        <x-ui.badge :tone="$pay[1]">{{ $pay[0] }}</x-ui.badge>
    </div>
    <div class="fl-row__end">
        @if($shipment->state === 'draft' && Route::has('shipments.book'))
            <x-ui.button size="sm" variant="primary" type="submit" form="book-{{ $shipment->public_id }}">Book</x-ui.button>
        @else
            <x-ui.badge :tone="$statusTone">{{ $statusLabel }}</x-ui.badge>
        @endif
        <span class="fl-row__src">{{ ShipmentList::sourceLabel($shipment) }}</span>
    </div>
</div>
