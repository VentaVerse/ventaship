@extends('layouts.app')

@php
    use App\Shipments\CourierFormFields;

    $id = $shipment->public_id;
    $str = fn (string $key, mixed $default = '') => is_scalar($x = old($key, $default)) ? (string) $x : (string) $default;
    $values = (array) old('recipient', $recipient);
    $parcelType = $str('parcel.type', (string) ($parcel['type'] ?? ''));
    $dims = [
        'length' => $str('parcel.length_cm', (string) ($parcel['length_cm'] ?? '')),
        'width' => $str('parcel.width_cm', (string) ($parcel['width_cm'] ?? '')),
        'height' => $str('parcel.height_cm', (string) ($parcel['height_cm'] ?? '')),
    ];
    $slotValue = $str('pickup_slot', $slot);
    $acct = $account->public_id;
    $config = [
        'services' => [], 'account' => $acct, 'service' => '', 'names' => [$acct => 'SPX'],
        'item' => $str('item', $item), 'value' => '', 'method' => $shipment->payment_method === 'cod' ? 'cod' : 'prepaid', 'insure' => false,
        'couriers' => [$acct => 'spx'], 'defaultParcels' => [$acct => null], 'parcelSizes' => [$acct => $sizes], 'firstDate' => '',
        'slotsUrl' => route('shipments.options.spx-slots'),
        'courier' => $dims + [
            'parcel' => $parcelType,
            'weight' => $str('parcel.weight_kg', (string) ($parcel['weight_kg'] ?? '')),
            'sizeOpen' => true,
            'shippingPayment' => '',
            'category' => (string) ($parcel['category'] ?? CourierFormFields::DEFAULT_CATEGORY),
            'handover' => $pickup ? 'pickup' : 'dropoff',
            'slot' => $slotValue,
            'slots' => $slotValue !== '' && $slotLabel !== null ? [['value' => $slotValue, 'label' => $slotLabel]] : [],
            'slotState' => 'idle',
            'pickupDate' => '',
            'note' => $str('note', $note),
        ],
        'pickupId' => 'current', 'pickups' => ['current' => (array) $shipment->pickup],
        'parcelLabels' => [$acct => collect($parcels)->mapWithKeys(fn ($o) => [$o['value'] => $o['label']])->all()],
        'to' => ['first' => '', 'last' => '', 'place' => ''],
        'checkUrl' => '', 'payers' => [], 'handovers' => [],
    ];
    $personFields = [
        'first_name' => ['label' => 'First name', 'required' => true],
        'last_name' => ['label' => 'Last name'],
        'phone' => ['label' => 'Phone', 'type' => 'phone', 'required' => true],
        'address_1' => ['label' => 'Street', 'required' => true, 'class' => 'sh-span'],
    ];
    $groupError = fn (string $key, string $errorId) => $errors->has($key) ? 'aria-invalid="true" aria-describedby="'.$errorId.'"' : '';
@endphp

@section('doc_title', 'Edit booking · '.($shipment->client_reference ?: $shipment->courier_ref))
@section('title', 'Edit booking')

@section('title_meta')
    <div class="sd-refs">
        <span class="sd-ref"><span class="sd-ref__k">SPX</span> <span class="sd-mono">{{ $shipment->tracking_number }}</span></span>
        <span class="sd-ref"><span>{{ $left }} {{ $left === 1 ? 'edit' : 'edits' }} left</span></span>
    </div>
@endsection

@section('crumb')
    <x-ui.back :href="route('shipments.show', $id)">{{ $shipment->client_reference ?: $shipment->courier_ref }}</x-ui.back>
@endsection

@section('content')
<form class="sh-form" method="POST" action="{{ route('shipments.booking.update', $id) }}" novalidate x-data="shipmentForm(@js($config))">
    @csrf
    @method('PUT')

    <div class="sh-main">
        @if($pickup)
            <section class="x-panel sh-sec" aria-labelledby="sec-pickup-time">
                <h2 class="st-h" id="sec-pickup-time">Pickup time</h2>
                <div class="sh-slots">
                    <div class="sh-grid">
                        <x-ui.field label="Pickup time" name="pickup_slot" :required="true">
                            <x-ui.select id="f-pickup-slot" name="pickup_slot" x-model="slot" ::disabled="slotState !== 'ready' && slots.length === 0">
                                <template x-if="slots.length === 0">
                                    <option value="" x-text="slotState === 'error' ? 'Couldn\'t load' : 'Loading…'"></option>
                                </template>
                                <template x-for="o in slots" :key="o.value">
                                    <option :value="o.value" x-text="o.label" :selected="o.value === slot"></option>
                                </template>
                            </x-ui.select>
                        </x-ui.field>
                    </div>
                    <div class="sh-slots__row" x-show="slotState === 'error'" x-cloak>
                        <span class="fm-error" role="alert"><x-ui.icon name="alert-triangle" size="14" /> Couldn't load.</span>
                        <x-ui.button size="sm" @click="loadSlots()"><x-ui.icon name="refresh-cw" size="14" /> Retry</x-ui.button>
                    </div>
                    <span class="x-sr" role="status" x-text="slotState === 'loading' ? 'Loading' : ''"></span>
                </div>
            </section>
        @endif

        <section class="x-panel sh-sec" aria-labelledby="sec-customer">
            <h2 class="st-h" id="sec-customer">Customer</h2>
            <div class="sh-grid">
                @include('shipments._form-fields', ['prefix' => 'recipient', 'fields' => $personFields, 'values' => $values])
            </div>
            <x-ph-address prefix="recipient" :values="$values" />
        </section>

        <section class="x-panel sh-sec" aria-labelledby="sec-parcel">
            <h2 class="st-h" id="sec-parcel">Parcel</h2>
            <x-ui.field label="Item" name="item" :required="true">
                <x-ui.input type="text" id="f-item" name="item" value="{{ $str('item', $item) }}" maxlength="255" required autocomplete="off" />
            </x-ui.field>
            <fieldset class="sh-fs sh-fs--group{{ $errors->has('parcel.type') ? ' is-invalid' : '' }}" {!! $groupError('parcel.type', 'f-parcel-type-error') !!}>
                <legend class="fm-label">Packaging<span class="fm-req" aria-hidden="true">*</span><span class="x-sr">(required)</span></legend>
                <div class="sh-pack">
                    @foreach($parcels as $o)
                        <label class="sh-pack__opt">
                            <input type="radio" class="sh-pack__input" name="parcel[type]" value="{{ $o['value'] }}" x-model="parcel" @checked($parcelType === $o['value'])>
                            <span class="sh-pack__label">{{ $o['label'] }}</span>
                            <span class="sh-pack__tick" aria-hidden="true"><x-ui.icon name="check" size="14" /></span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <x-ui.field-error name="parcel.type" />
            <div class="sh-grid">
                <x-ui.field label="Weight (kg)" name="parcel.weight_kg" :required="true">
                    <x-ui.input type="text" inputmode="decimal" id="f-parcel-weight-kg" name="parcel[weight_kg]" value="{{ $config['courier']['weight'] }}" x-model="weight" required autocomplete="off" />
                </x-ui.field>
            </div>
            <fieldset class="sh-fs sh-grid sh-grid--3">
                @foreach(['length' => 'Length (cm)', 'width' => 'Width (cm)', 'height' => 'Height (cm)'] as $key => $label)
                    @php($sizeKey = 'parcel.'.$key.'_cm')
                    <div class="fm-field{{ $errors->has($sizeKey) ? ' fm-field--invalid' : '' }}">
                        <label class="fm-label" for="f-parcel-{{ $key }}-cm">{{ $label }}<span class="fm-req" aria-hidden="true">*</span></label>
                        <input class="x-input{{ $errors->has($sizeKey) ? ' is-invalid' : '' }}" type="text" inputmode="decimal" id="f-parcel-{{ $key }}-cm" name="parcel[{{ $key }}_cm]" value="{{ $dims[$key] }}" x-model="{{ $key }}" required aria-required="true" autocomplete="off"
                               @if($errors->has($sizeKey)) aria-invalid="true" aria-describedby="f-parcel-{{ $key }}-cm-error" @endif>
                        <x-ui.field-error :name="$sizeKey" />
                    </div>
                @endforeach
            </fieldset>
            <x-ui.field label="Delivery note" name="note">
                <x-ui.textarea class="sh-note" id="f-note" name="note" rows="2" :maxlength="CourierFormFields::NOTE_MAX" autocomplete="off" :value="$config['courier']['note']" />
            </x-ui.field>
        </section>
    </div>

    <aside class="sh-summary" aria-labelledby="sec-booking">
        <div class="x-panel sh-summary__box">
            <h2 class="st-h" id="sec-booking">Booking</h2>
            <dl class="sh-sum">
                @foreach($summary as $label => $value)
                    <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
            <div class="sh-summary__actions">
                <x-ui.button variant="primary" type="submit">Save changes</x-ui.button>
                <x-ui.button :href="route('shipments.show', $id)">Back</x-ui.button>
            </div>
        </div>
    </aside>
</form>
@endsection
