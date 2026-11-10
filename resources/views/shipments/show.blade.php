@extends('layouts.app')

@php
    use App\Fulfillment\ShipmentBadges;
    use App\Fulfillment\ShipmentDetail;
    use App\Fulfillment\StatusFilter;

    $id = $shipment->public_id;
    $title = $detail->title();
    $act = $detail->actions();
    $alert = $detail->alert();
    $trackingUrl = $detail->trackingUrl();
    $name = $detail->name();
    $phone = $detail->phone();
    $address = $detail->address();
    $note = $detail->note();
    $purged = $detail->recipient() === null;
    $pay = ShipmentBadges::payment($shipment);
    $courierName = $detail->courierName();
    // A plain value for the Copy button's credentialField: nothing secret, nothing fetched.
    $copy = fn (string $value) => ['url' => null, 'value' => $value, 'mask' => $value, 'isSet' => true, 'name' => null];
@endphp

@section('doc_title', $title.' · Shipment')
@section('title', $title)

@section('title_after')
    <span class="sd-title-badge"><x-ui.badge :tone="ShipmentBadges::statusTone($shipment)">{{ StatusFilter::labelFor($shipment) }}</x-ui.badge></span>
@endsection

@section('title_meta')
    <div class="sd-refs">
        @if($shipment->client_reference)
            <span class="sd-ref">
                <span class="sd-ref__k">Order</span>
                <span class="sd-mono">{{ $shipment->client_reference }}</span>
                <span class="sd-copy" x-data="credentialField({{ Js::from($copy((string) $shipment->client_reference)) }})"><x-ui.copy-button label="Copy order reference" /></span>
            </span>
        @endif
        <span class="sd-ref"><span class="sd-ref__k">From</span> <span>{{ $detail->source() }}</span></span>
        @if($shipment->created_at)
            <span class="sd-ref"><span class="sd-ref__k">Created</span> <span class="x-num">{{ ShipmentDetail::at($shipment->created_at) }}</span></span>
        @endif
        @if($shipment->tracking_number)
            <span class="sd-ref">
                <span class="sd-ref__k">Tracking</span>
                @if($trackingUrl)
                    <a class="sd-mono sd-link" href="{{ $trackingUrl }}" target="_blank" rel="noopener">{{ $shipment->tracking_number }}</a>
                @else
                    <span class="sd-mono">{{ $shipment->tracking_number }}</span>
                @endif
                <span class="sd-copy" x-data="credentialField({{ Js::from($copy((string) $shipment->tracking_number)) }})"><x-ui.copy-button label="Copy tracking number" /></span>
            </span>
        @endif
    </div>
@endsection

@section('crumb')
    <a class="sd-crumb" href="{{ route('fulfillment') }}">Shipments</a>
@endsection

@if(in_array(true, [$act['print'], $act['retry'], $act['book'], $act['edit'], $act['cancel'], $act['delete']], true))
@section('actions')
    <div class="sd-actions">
        @if($act['print'])
            <x-ui.button variant="primary" type="submit" class="sd-print" form="sd-labels">Print waybill</x-ui.button>
        @endif
        @if($act['retry'])
            <x-ui.button variant="primary" type="submit" class="sd-retry" form="sd-retry">Retry</x-ui.button>
        @endif
        @if($act['book'])
            <x-ui.button variant="primary" type="submit" class="sd-book" form="sd-book">Book</x-ui.button>
        @endif
        @if($act['edit'])
            <x-ui.button class="sd-edit" :href="route('shipments.edit', $id)">Edit</x-ui.button>
        @endif
        @if($act['cancel'])
            <x-ui.button class="sd-cancel" @click="$dispatch('confirm', {{ Js::from(['title' => 'Cancel shipment '.$title.'?', 'confirm' => 'Cancel shipment', 'dismiss' => 'Keep shipment', 'form' => 'sd-cancel', 'tone' => 'danger']) }})">Cancel</x-ui.button>
        @endif
        @if($act['delete'])
            <x-ui.button class="sd-delete" @click="$dispatch('confirm', {{ Js::from(['title' => 'Delete shipment '.$title.'?', 'confirm' => 'Delete shipment', 'dismiss' => 'Keep shipment', 'form' => 'sd-delete', 'tone' => 'danger']) }})">Delete</x-ui.button>
        @endif
    </div>
@endsection
@endif

@section('content')
@if($alert)
    <div class="sd-alert sd-alert--{{ $alert['kind'] === 'failed' ? 'bad' : 'attn' }}" data-alert="{{ $alert['kind'] }}">
        <x-ui.icon :name="$alert['kind'] === 'unknown' ? 'clock' : 'alert-triangle'" size="16" />
        <div class="sd-alert__body">
            @if($alert['title'])
                <p class="sd-alert__title">{{ $alert['title'] }}</p>
            @endif
            @foreach($alert['lines'] as $line)
                <p class="sd-alert__msg">{{ $line }}</p>
            @endforeach
            @if($alert['kind'] === 'unknown' && $act['resolve'])
                @php
                    $trackingError = $errors->first('tracking');
                    $trackingLabel = 'Tracking number from '.$courierName;
                @endphp
                <form method="POST" action="{{ route('shipments.resolve', $id) }}" class="sd-resolve" novalidate
                      x-data="{ busy: false }" @submit="if (busy) { $event.preventDefault() } busy = true">
                    @csrf
                    <input type="hidden" name="outcome" value="booked">
                    <input type="text" id="sd-tracking" name="tracking" value="{{ old('tracking') }}" maxlength="64"
                           class="x-input sd-resolve__input{{ $trackingError !== '' ? ' is-invalid' : '' }}"
                           aria-label="{{ $trackingLabel }}" placeholder="{{ $trackingLabel }}"
                           autocomplete="off" spellcheck="false" autocapitalize="off"
                           @if($trackingError !== '') aria-invalid="true" aria-describedby="sd-tracking-error" @endif>
                    <x-ui.button variant="primary" type="submit" class="sd-resolve-booked">Courier has it</x-ui.button>
                    <x-ui.button class="sd-resolve-not-booked" @click="$dispatch('confirm', {{ Js::from(['title' => 'Mark '.$title.' as not booked?', 'confirm' => 'Not booked', 'dismiss' => 'Back', 'form' => 'sd-not-booked']) }})">Not booked</x-ui.button>
                    <x-ui.field-error name="tracking" id="sd-tracking-error" class="sd-resolve__error" />
                </form>
            @endif
        </div>
    </div>
@endif

<div class="sd-grid">
    <div class="sd-col">
        <section class="x-panel sd-card" aria-labelledby="sd-h-customer">
            <h2 class="sd-card__h" id="sd-h-customer">Customer</h2>
            <dl class="sd-list">
                @if($purged)
                    <dt>Name</dt><dd class="is-removed">Removed</dd>
                @else
                    @if($name)
                        <dt>Name</dt><dd>{{ $name }}</dd>
                    @endif
                    @if($phone)
                        <dt>Phone</dt>
                        <dd class="sd-dd-copy">
                            <span class="x-num">{{ $detail->phoneShown() }}</span>
                            <span class="sd-copy" x-data="credentialField({{ Js::from($copy($phone)) }})"><x-ui.copy-button label="Copy phone number" /></span>
                        </dd>
                    @endif
                    @if($address)
                        <dt>Address</dt><dd>{{ $address }}</dd>
                    @endif
                    @if($note)
                        <dt>Note</dt><dd>{{ $note }}</dd>
                    @endif
                @endif
            </dl>
        </section>

        <section class="x-panel sd-card" aria-labelledby="sd-h-parcel">
            <h2 class="sd-card__h" id="sd-h-parcel">Parcel</h2>
            <dl class="sd-list">
                @foreach($detail->parcelRows() as $label => $value)
                    <dt>{{ $label }}</dt>
                    @if(in_array($label, ['Item', 'Packaging'], true))
                        <dd>{{ $value }}</dd>
                    @else
                        <dd class="x-num">{{ $value }}</dd>
                    @endif
                @endforeach
                <dt>Payment</dt><dd><x-ui.badge :tone="$pay[1]">{{ $pay[0] }}</x-ui.badge></dd>
                <dt>Insured</dt><dd>{{ $detail->insured() }}</dd>
            </dl>
        </section>
    </div>

    <div class="sd-col">
        <section class="x-panel sd-card" aria-labelledby="sd-h-courier">
            <h2 class="sd-card__h" id="sd-h-courier">Courier</h2>
            <dl class="sd-list">
                @foreach($detail->courierRows() as $label => $value)
                    <dt>{{ $label }}</dt>
                    @if($label === 'Tracking')
                        <dd>
                            @if($trackingUrl)
                                <a class="sd-mono sd-link" href="{{ $trackingUrl }}" target="_blank" rel="noopener">{{ $value }}</a>
                            @else
                                <span class="sd-mono">{{ $value }}</span>
                            @endif
                        </dd>
                    @elseif(in_array($label, ['Booked at', 'Fee'], true))
                        <dd class="x-num">{{ $value }}</dd>
                    @else
                        <dd>{{ $value }}</dd>
                    @endif
                @endforeach
            </dl>
        </section>

        <section class="x-panel sd-card" aria-labelledby="sd-h-timeline">
            <h2 class="sd-card__h" id="sd-h-timeline">Timeline</h2>
            <ol class="sd-timeline">
                @foreach($detail->timeline() as $event)
                    <li class="sd-event">
                        <span class="sd-event__time x-num">{{ $event['at'] }}</span>
                        <span class="sd-event__label">{{ $event['label'] }}</span>
                        @if($event['phase'])
                            <x-ui.badge :tone="ShipmentBadges::tone($event['phase'])">{{ \Illuminate\Support\Str::ucfirst(str_replace('_', ' ', $event['phase'])) }}</x-ui.badge>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    </div>
</div>

@push('forms')
    @if($act['book'])
        <form id="sd-book" method="POST" action="{{ route('shipments.book') }}">@csrf<input type="hidden" name="ids[]" value="{{ $id }}"></form>
    @endif
    @if($act['retry'])
        <form id="sd-retry" method="POST" action="{{ route('shipments.retry', $id) }}" x-data="{ busy: false }" @submit="if (busy) { $event.preventDefault() } busy = true">@csrf</form>
    @endif
    @if($act['print'])
        <form id="sd-labels" method="POST" action="{{ route('shipments.labels') }}" target="_blank">@csrf<input type="hidden" name="ids[]" value="{{ $id }}"></form>
    @endif
    @if($act['cancel'])
        <form id="sd-cancel" method="POST" action="{{ route('shipments.cancel') }}">@csrf<input type="hidden" name="ids[]" value="{{ $id }}"></form>
    @endif
    @if($act['delete'])
        <form id="sd-delete" method="POST" action="{{ route('shipments.destroy', $id) }}">@csrf @method('DELETE')</form>
    @endif
    @if($act['resolve'])
        <form id="sd-not-booked" method="POST" action="{{ route('shipments.resolve', $id) }}">@csrf<input type="hidden" name="outcome" value="not_booked"></form>
    @endif
@endpush
@endsection
