@extends('layouts.app')

@section('width', 'form')
@section('title', ($shipment->courier_ref ?: $shipment->public_id).' · Shipment')

@section('crumb')
    <a href="{{ route('fulfillment') }}">Shipments</a>
@endsection

@section('content')
@php
    use App\Fulfillment\Couriers;
    use App\Fulfillment\ShipmentBadges;
    use App\Fulfillment\ShipmentList;
    use App\Fulfillment\StatusFilter;
    use App\Shipments\Canceller;
    use App\Shipments\ItemsText;
    use App\Shipments\TrackingLink;
    use App\Support\Money;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Str;

    $at = fn ($t) => $t ? $t->copy()->timezone('Asia/Manila')->format('M j, Y · g:i A') : null;
    $peso = fn ($v) => $v !== null ? Money::peso((string) $v) : null;

    $r = is_array($shipment->recipient) ? $shipment->recipient : null;
    $name = $r ? trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')) : '';
    $name = $name !== '' ? $name : null;
    $address = $r ? collect([$r['address_1'] ?? null, $r['address_2'] ?? null, $r['barangay'] ?? null, $r['district'] ?? null, $r['city'] ?? null, $r['state'] ?? null, $r['postcode'] ?? null])->filter()->implode(', ') : '';
    $pay = ShipmentBadges::payment($shipment);
    $refs = collect([$shipment->courier_ref, $shipment->client_reference])->filter()->unique()->implode(' · ');
    $tracking = $shipment->tracking_number ? TrackingLink::for($shipment) : null;
    $parcel = is_array($shipment->options['parcel'] ?? null) ? $shipment->options['parcel'] : [];
    $insured = (bool) ($shipment->options['insure'] ?? false);
    $service = $shipment->service ? Str::title(str_replace('_', ' ', $shipment->service)) : null;
    $account = $shipment->courierAccount;
    $accountLabel = $account ? collect([Couriers::name((string) $account->courier), $account->label])->filter()->implode(' · ') : null;

    $canEdit = $shipment->isEditable() && Route::has('shipments.edit');
    $canDelete = $shipment->isEditable() && Route::has('shipments.destroy');
    $canBook = $shipment->state === 'draft' && Route::has('shipments.book');
    $canPrint = $shipment->state === 'booked' && Route::has('shipments.labels');
    // What the bulk Cancel acts on; a draft is booked or deleted from here instead.
    $canCancel = Canceller::accepts($shipment) && $shipment->state !== 'draft' && Route::has('shipments.cancel');

    $dim = fn ($k, $unit) => isset($parcel[$k]) ? $parcel[$k].' '.$unit : null;
    $size = collect([$parcel['length_cm'] ?? null, $parcel['width_cm'] ?? null, $parcel['height_cm'] ?? null])->filter()->implode(' × ');
@endphp
<section class="x-panel sd-panel">
    <div class="st-sec sd-head">
        <div class="sd-head__main">
            <h2 class="sd-name {{ $name ? '' : 'is-removed' }}">{{ $name ?: 'Removed' }}</h2>
            <div class="sd-refs x-num">{{ $refs }}</div>
        </div>
        <div class="sd-head__badges">
            <x-ui.badge :tone="ShipmentBadges::statusTone($shipment)">{{ StatusFilter::labelFor($shipment) }}</x-ui.badge>
            <x-ui.badge :tone="$pay[1]">{{ $pay[0] }}</x-ui.badge>
        </div>
        <div class="sd-actions">
            @if($canPrint)
                <x-ui.button size="sm" type="submit" class="sd-print" form="sd-labels">Print waybill</x-ui.button>
            @endif
            @if($canEdit)
                <x-ui.button size="sm" class="sd-edit" :href="route('shipments.edit', $shipment->public_id)">Edit</x-ui.button>
            @endif
            @if($canBook)
                <x-ui.button size="sm" variant="primary" type="submit" class="sd-book" form="sd-book">Book</x-ui.button>
            @endif
            @if($canCancel)
                <x-ui.button size="sm" class="sd-cancel" @click="$dispatch('confirm', {{ Js::from(['title' => 'Cancel shipment '.$shipment->courier_ref.'?', 'confirm' => 'Cancel shipment', 'dismiss' => 'Keep shipment', 'form' => 'sd-cancel']) }})">Cancel</x-ui.button>
            @endif
            @if($canDelete)
                <x-ui.button size="sm" class="sd-delete" @click="$dispatch('confirm', {{ Js::from(['title' => 'Delete shipment '.$shipment->courier_ref.'?', 'confirm' => 'Delete shipment', 'dismiss' => 'Keep shipment', 'form' => 'sd-delete']) }})">Delete</x-ui.button>
            @endif
        </div>
    </div>

    @if($shipment->attention_reason)
        <div class="st-sec sd-attn">
            <x-ui.badge tone="attn">Attention</x-ui.badge>
            <span>{{ $shipment->attention_reason }}</span>
        </div>
    @endif

    <div class="st-sec">
        <h2 class="st-h">Customer</h2>
        <dl class="sd-list">
            @if($r)
                <dt>Name</dt><dd>{{ $name ?: '—' }}</dd>
                <dt>Phone</dt><dd class="x-num">{{ $r['phone'] ?? '—' }}</dd>
                <dt>Address</dt><dd>{{ $address !== '' ? $address : '—' }}</dd>
            @else
                <dt>Name</dt><dd class="is-removed">Removed</dd>
            @endif
        </dl>
    </div>

    <div class="st-sec">
        <h2 class="st-h">Items and parcel</h2>
        <dl class="sd-list">
            <dt>Item</dt><dd>{{ ItemsText::join($shipment->items) ?: '—' }}</dd>
            <dt>Weight</dt><dd class="x-num">{{ $dim('weight_kg', 'kg') ?? '—' }}</dd>
            <dt>Size</dt><dd class="x-num">{{ $size !== '' ? $size.' cm' : '—' }}</dd>
        </dl>
    </div>

    <div class="st-sec">
        <h2 class="st-h">Payment</h2>
        <dl class="sd-list">
            <dt>Method</dt><dd>{{ $shipment->payment_method === 'cod' ? 'Cash on delivery' : 'Prepaid' }}</dd>
            <dt>COD amount</dt><dd class="x-num">{{ $peso($shipment->cod_amount) ?? '—' }}</dd>
            <dt>Declared value</dt><dd class="x-num">{{ $peso($shipment->declared_value) ?? '—' }}</dd>
            <dt>Insured</dt><dd>{{ $insured ? 'Yes' : 'No' }}</dd>
            <dt>Payment state</dt><dd>{{ $pay[0] }}</dd>
        </dl>
    </div>

    <div class="st-sec">
        <h2 class="st-h">Courier</h2>
        <dl class="sd-list">
            <dt>Account</dt><dd>{{ $accountLabel ?? '—' }}</dd>
            <dt>Service</dt><dd>{{ $service ?? '—' }}</dd>
            <dt>Tracking</dt>
            <dd class="x-num">
                @if($tracking && preg_match('#^https?://#i', $tracking))
                    <a class="sd-link" href="{{ $tracking }}" target="_blank" rel="noopener">{{ $shipment->tracking_number }}</a>
                @else
                    {{ $shipment->tracking_number ?? '—' }}
                @endif
            </dd>
            <dt>Booked at</dt><dd class="x-num">{{ $at($shipment->booked_at) ?? '—' }}</dd>
        </dl>
    </div>

    <div class="st-sec">
        <h2 class="st-h">Source</h2>
        <dl class="sd-list">
            <dt>Source</dt><dd>{{ $shipment->source === 'manual' ? 'Manual' : ShipmentList::sourceLabel($shipment) }}</dd>
        </dl>
    </div>

    <div class="st-sec">
        <h2 class="st-h">Timeline</h2>
        <div class="sd-timeline">
            @forelse($shipment->events as $event)
                <div class="sd-event">
                    <span class="sd-event__time x-num">{{ $at($event->occurred_at) }}</span>
                    <span class="sd-event__label">{{ $event->label ?: ($shipment->courier ? StatusFilter::label($shipment->courier, $event->courier_status) : $event->courier_status) }}</span>
                    @if($event->phase)
                        <x-ui.badge :tone="ShipmentBadges::tone($event->phase)">{{ Str::ucfirst(str_replace('_', ' ', $event->phase)) }}</x-ui.badge>
                    @endif
                </div>
            @empty
                <span class="sd-none">—</span>
            @endforelse
        </div>
    </div>
</section>

@push('forms')
    @if($canBook)
        <form id="sd-book" method="POST" action="{{ route('shipments.book') }}">@csrf<input type="hidden" name="ids[]" value="{{ $shipment->public_id }}"></form>
    @endif
    @if($canPrint)
        <form id="sd-labels" method="POST" action="{{ route('shipments.labels') }}" target="_blank">@csrf<input type="hidden" name="ids[]" value="{{ $shipment->public_id }}"></form>
    @endif
    @if($canCancel)
        <form id="sd-cancel" method="POST" action="{{ route('shipments.cancel') }}">@csrf<input type="hidden" name="ids[]" value="{{ $shipment->public_id }}"></form>
    @endif
    @if($canDelete)
        <form id="sd-delete" method="POST" action="{{ route('shipments.destroy', $shipment->public_id) }}">@csrf @method('DELETE')</form>
    @endif
@endpush
@endsection
