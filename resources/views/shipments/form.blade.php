@extends('layouts.app')

@section('title', $editing ? 'Edit shipment' : 'Add shipment')

@section('crumb')
    <a href="{{ route('fulfillment') }}">Shipments</a>
@endsection

@section('content')
@php
    use App\Http\Requests\Ui\ShipmentFormRequest;
    use App\Shipments\CourierFormFields;
    use App\Support\Money;

    $personFields = [
        'first_name' => ['label' => 'First name', 'required' => true],
        'last_name' => ['label' => 'Last name'],
        'phone' => ['label' => 'Phone', 'type' => 'phone', 'required' => true],
    ];
    $streetFields = [
        'address_1' => ['label' => 'Street', 'required' => true, 'class' => 'sh-span'],
    ];
    $v = fn (string $key, mixed $default = '') => old($key, data_get($prefill, $key, $default));
    $item = is_string($v('item')) ? $v('item') : '';
    $valueRaw = is_string($v('value')) ? $v('value') : '';
    $value = ShipmentFormRequest::normaliseValue($valueRaw);
    $valueOk = preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value) === 1;
    $total = Money::peso($valueOk ? $value : '0');
    $method = $v('payment.method', 'prepaid');
    $insure = filter_var($v('insure'), FILTER_VALIDATE_BOOLEAN);
    $usable = array_values(array_filter($tiles, fn ($t) => $t['id'] !== null));
    $accountId = $v('account_id', $usable[0]['id'] ?? '');
    $names = collect($usable)->mapWithKeys(fn ($t) => [$t['id'] => $t['name']])->all();
    $oneStreet = function (array $party): array {
        $street = trim(implode(', ', array_filter([trim((string) ($party['address_1'] ?? '')), trim((string) ($party['address_2'] ?? ''))], fn ($p) => $p !== '')));
        unset($party['address_2']);

        return $street === '' ? $party : ['address_1' => $street] + $party;
    };
    $recipient = $oneStreet((array) old('recipient', $prefill['recipient'] ?? []));
    $oldCustomer = old('customer_id');
    $customerId = is_string($oldCustomer) ? $oldCustomer : '';
    $saveCustomer = filter_var(old('save_customer', false), FILTER_VALIDATE_BOOLEAN);
    $pickupIds = array_column($pickups, 'id');
    $oldPickup = old('pickup_address_id');
    $pickupId = is_string($oldPickup) && in_array($oldPickup, $pickupIds, true)
        ? $oldPickup
        : (collect($pickups)->firstWhere('selected', true)['id'] ?? '');
    $pickupErrorKey = collect($errors->keys())->first(fn ($k) => $k === 'pickup_address_id' || str_starts_with($k, 'pickup.'));
    $text = fn (mixed $x): string => is_scalar($x) ? trim((string) $x) : '';
    $phone = fn (mixed $x): string => preg_match('/^(\d{4})(\d{3})(\d{4})$/', $text($x), $m) ? "{$m[1]} {$m[2]} {$m[3]}" : $text($x);
    $addressLine = function (array $p) use ($text): string {
        $tail = trim($text($p['state'] ?? '').' '.$text($p['postcode'] ?? ''));

        return collect([$p['address_1'] ?? '', $p['address_2'] ?? '', $p['barangay'] ?? '', $p['district'] ?? '', $p['city'] ?? '', $tail])
            ->map($text)->filter()->implode(', ');
    };
    $str = fn (string $key, string $default = '') => is_scalar($x = $v($key, $default)) ? (string) $x : $default;
    $courier = $fields['couriers'][$accountId] ?? '';
    $dateValues = array_column($lbcDates, 'value');
    $parcel = $str('parcel.type', (string) ($fields['defaultParcels'][$accountId] ?? ''));
    $dims = ['length' => $str('parcel.length_cm'), 'width' => $str('parcel.width_cm'), 'height' => $str('parcel.height_cm')];
    // An SPX preset fills its size in until the seller types one.
    $preset = $fields['parcelSizes'][$accountId][$parcel] ?? null;
    if ($preset !== null && collect($dims)->every(fn ($d) => $d === '')) {
        $dims = ['length' => (string) $preset['length_cm'], 'width' => (string) $preset['width_cm'], 'height' => (string) $preset['height_cm']];
    }
    $sizeOpen = collect($dims)->contains(fn ($d) => $d !== '') || collect(['length_cm', 'width_cm', 'height_cm'])->contains(fn ($k) => $errors->has('parcel.'.$k));
    $show = CourierFormFields::show($courier, $parcel, $sizeOpen);
    $slotTime = CourierFormFields::spxSlotTime($str('pickup_slot'));
    $slot = $slotTime === null ? '' : $str('pickup_slot');
    $staleDate = $editing && $str('pickup_date') !== '' && ! in_array($str('pickup_date'), $dateValues, true);
    $pickupDate = in_array($str('pickup_date'), $dateValues, true) ? $str('pickup_date') : ($staleDate ? '' : ($dateValues[0] ?? ''));
    $courierState = $dims + [
        'parcel' => $parcel,
        'weight' => $str('parcel.weight_kg'),
        'sizeOpen' => $sizeOpen,
        'shippingPayment' => $str('payment.shipping_payment') ?: (string) CourierFormFields::defaultShippingPayment($courier),
        'category' => in_array($str('parcel.category'), CourierFormFields::categories(), true) ? $str('parcel.category') : CourierFormFields::DEFAULT_CATEGORY,
        'handover' => $str('handover') === 'pickup' ? 'pickup' : 'dropoff',
        'slot' => $slot,
        'slots' => is_numeric($slotTime) ? [['value' => $slot, 'label' => \Carbon\Carbon::createFromTimestamp((int) $slotTime)->timezone(config('app.timezone'))->format('D, M d')]] : [],
        'slotState' => is_numeric($slotTime) ? 'ready' : 'idle',
        'pickupDate' => $pickupDate,
        'note' => $str('note'),
    ];
    $config = [
        'services' => $services, 'account' => $accountId, 'service' => (string) $v('service'), 'names' => $names,
        'item' => $item, 'value' => $valueRaw, 'method' => $method, 'insure' => $insure,
        'couriers' => $fields['couriers'], 'defaultParcels' => $fields['defaultParcels'], 'parcelSizes' => $fields['parcelSizes'], 'firstDate' => $dateValues[0] ?? '',
        'slotsUrl' => route('shipments.options.spx-slots'), 'courier' => $courierState,
        'pickupId' => $pickupId, 'pickups' => collect($pickups)->mapWithKeys(fn ($o) => [$o['id'] => $o['pickup']])->all(),
        'checkUrl' => $editing ? route('shipments.check-edit', $shipment->public_id) : route('shipments.check'),
        'payers' => CourierFormFields::SHIPPING_PAYMENT, 'handovers' => CourierFormFields::HANDOVER,
    ];
    $config['parcelLabels'] = collect($fields['parcels'])->map(fn ($options) => collect($options)->mapWithKeys(fn ($o) => [$o['value'] => $o['label']])->all())->all();
    $place = collect([$text($recipient['city'] ?? ''), $text($recipient['state'] ?? '')])->filter()->implode(', ');
    $config['to'] = ['first' => $text($recipient['first_name'] ?? ''), 'last' => $text($recipient['last_name'] ?? ''), 'place' => $place];
    $toName = trim($config['to']['first'].' '.$config['to']['last']);
    $packagingLabel = $config['parcelLabels'][$accountId][$parcel] ?? null;
    $picked = $customerId !== '' && is_array($picked ?? null) ? $picked : null;
    $pickedMeta = $picked === null ? '' : collect([$phone($picked['phone'] ?? ''), collect([$text($picked['address']['city'] ?? ''), $text($picked['address']['state'] ?? '')])->filter()->implode(', ')])->filter()->implode(' · ');
    // A group (tiles, segments) shows its error under it and marks itself invalid, pointing at that error.
    $groupError = fn (string $key, string $id) => $errors->has($key) ? 'aria-invalid="true" aria-describedby="'.$id.'"' : '';
    foreach ($errors->keys() as $k) {
        // The pickup snapshot's own errors are summed up by the one line under the pickup.
        if ($pickupErrorKey !== null && str_starts_with($k, 'pickup.')) {
            \App\View\InlineErrors::mark($k);
        }
    }
    $off = fn (bool $shown) => $shown ? '' : 'disabled x-cloak';
@endphp

@if($usable === [])
    <section class="x-panel">
        <x-ui.empty title="No courier accounts" />
    </section>
@else
<form class="sh-form" method="POST" action="{{ $editing ? route('shipments.update', $shipment->public_id) : route('shipments.store') }}" novalidate x-data="shipmentForm(@js($config))"
      x-on:input="recipientTyped($event)" x-on:ph-place.window="placed($event)" x-on:submit="submitted($event)">
    @csrf
    @if($editing) @method('PUT') @endif
    <input type="hidden" name="form_token" value="{{ $token }}">

    <div class="sh-main">
        <section class="x-panel sh-sec" aria-labelledby="sec-courier">
            <h2 class="st-h" id="sec-courier">Courier</h2>
            <div class="sh-couriers{{ $errors->has('account_id') ? ' is-invalid' : '' }}" role="radiogroup" aria-labelledby="sec-courier" {!! $groupError('account_id', 'f-account-id-error') !!}>
                @foreach($tiles as $t)
                    @if($t['id'] === null && ! $t['inactive'])
                        <div class="sh-courier is-off" data-courier="{{ $t['courier'] }}">
                            <x-courier-tile :courier="$t['courier']" />
                            <span class="sh-courier__text">
                                <span class="sh-courier__name">{{ $t['name'] }}</span>
                                <span class="sh-courier__note">Not set up · <a class="sh-courier__link" href="{{ route('accounts.show', $t['courier']) }}">Set up</a></span>
                            </span>
                        </div>
                        @continue
                    @endif
                    <label class="sh-courier{{ $t['id'] === null ? ' is-off' : '' }}" data-courier="{{ $t['courier'] }}">
                        @if($t['id'] !== null)
                            <input type="radio" name="account_id" value="{{ $t['id'] }}" class="sh-courier__input" x-model="account" @checked($accountId === $t['id'])>
                        @else
                            <input type="radio" name="account_id" class="sh-courier__input" disabled>
                        @endif
                        <x-courier-tile :courier="$t['courier']" />
                        <span class="sh-courier__text">
                            <span class="sh-courier__name">{{ $t['name'] }}</span>
                            @if($t['inactive'])
                                <span class="sh-courier__note">Inactive</span>
                            @endif
                        </span>
                        <span class="sh-courier__tick" aria-hidden="true"><x-ui.icon name="check" size="14" /></span>
                    </label>
                @endforeach
            </div>
            <x-ui.field-error name="account_id" />
            <div class="sh-grid" x-show="serviceOptions.length > 0" @if(($services[$accountId] ?? []) === []) x-cloak @endif>
                <x-ui.field label="Service" name="service">
                    <x-ui.select id="f-service" name="service" x-model="service">
                        <option value="">Default</option>
                        <template x-for="o in serviceOptions" :key="o.value">
                            <option :value="o.value" x-text="o.label" :selected="o.value === service"></option>
                        </template>
                    </x-ui.select>
                </x-ui.field>
            </div>
        </section>

        <section class="x-panel sh-sec" aria-labelledby="sec-pickup">
            <div class="sh-sec__head">
                <h2 class="st-h" id="sec-pickup">Pickup</h2>
                @if(count($pickups) > 1)
                    <x-ui.button size="sm" aria-controls="sh-pickups" ::aria-expanded="pickupOpen ? 'true' : 'false'" x-on:click="pickupOpen = !pickupOpen">
                        <span x-text="pickupOpen ? 'Done' : 'Change'">Change</span>
                    </x-ui.button>
                @endif
            </div>
            @if($pickups === [])
                <div>
                    <x-ui.button :href="route('addresses.pickup.create')"><x-ui.icon name="plus" size="14" /> Add pickup address</x-ui.button>
                </div>
            @else
                <fieldset class="sh-fs sh-pickups{{ $pickupErrorKey !== null ? ' is-invalid' : '' }}" id="sh-pickups" x-bind:class="pickupOpen && 'is-open'" {!! $pickupErrorKey !== null ? 'aria-invalid="true" aria-describedby="f-pickup-error"' : '' !!}>
                    <legend class="x-sr">Pickup address</legend>
                    @foreach($pickups as $o)
                        @php($on = $o['id'] === $pickupId)
                        <label class="sh-addr" x-show="pickupOpen || pickupId === @js($o['id'])" @unless($on) x-cloak @endunless>
                            <input type="radio" class="sh-addr__input" name="pickup_address_id" value="{{ $o['id'] }}" x-model="pickupId" @checked($on)>
                            <span class="sh-addr__text">
                                <span class="sh-addr__name">{{ $o['label'] }}@if($o['default']) <x-ui.badge tone="good">Default</x-ui.badge>@endif</span>
                                <span class="sh-addr__line">{{ collect([$text($o['pickup']['contact_name'] ?? ''), $phone($o['pickup']['contact_phone'] ?? '')])->filter()->implode(' · ') }}</span>
                                <span class="sh-addr__line">{{ $addressLine($o['pickup']) }}</span>
                            </span>
                        </label>
                    @endforeach
                </fieldset>
            @endif
            @if($pickupErrorKey !== null)
                <x-ui.field-error :name="$pickupErrorKey" id="f-pickup-error" />
            @endif
        </section>

        <section class="x-panel sh-sec" aria-labelledby="sec-customer">
            <h2 class="st-h" id="sec-customer">Customer</h2>
            <div class="sh-customer" x-data="customerSearch(@js(['url' => route('customers.search'), 'customerId' => $customerId, 'picked' => $picked]))" x-on:input="edited($event)">
            <div class="sh-picked" x-show="picked && !searching" @if($picked === null) x-cloak @endif>
                <button type="button" class="sh-picked__main" id="f-customer-picked" x-on:click="change()" x-on:keydown="change($event)">
                    <x-ui.icon name="user" size="16" class="sh-picked__icon" />
                    <span class="sh-picked__text">
                        <span class="sh-picked__name" x-text="picked ? picked.name : ''">{{ $picked['name'] ?? '' }}</span>
                        <span class="sh-picked__meta" x-text="pickedMeta">{{ $pickedMeta }}</span>
                    </span>
                    <span class="x-sr">, change customer</span>
                </button>
                <button type="button" class="sh-picked__clear" aria-label="Clear customer" x-on:click="clearPick()"><x-ui.icon name="x" size="16" /></button>
            </div>
            <div class="sh-find" x-show="!picked || searching" @if($picked !== null) x-cloak @endif x-on:click.outside="close()">
                <label class="x-sr" for="f-customer-search">Search customers</label>
                <div class="sh-find__box">
                    <x-ui.icon name="search" size="16" class="sh-find__icon" />
                    <input class="x-input sh-find__input" id="f-customer-search" type="search" role="combobox" aria-autocomplete="list" aria-controls="f-customer-list"
                           aria-expanded="false" x-bind:aria-expanded="open ? 'true' : 'false'" x-bind:aria-activedescendant="activeId"
                           x-model="q" x-on:input="input()" x-on:keydown="key($event)" x-on:blur="blurred()" placeholder="Search customers" maxlength="100" autocomplete="off">
                </div>
                <ul class="sh-find__list" id="f-customer-list" role="listbox" aria-label="Customers" x-show="open" x-cloak>
                    <template x-for="(c, n) in results" :key="c.public_id">
                        <li class="sh-find__opt" role="option" :id="optionId(n)" :aria-selected="active === n ? 'true' : 'false'" :class="active === n && 'is-active'"
                            x-on:mousedown.prevent x-on:mousemove="active = n" x-on:click="pick(n)">
                            <span class="sh-find__name" x-text="c.name"></span>
                            <span class="sh-find__meta" x-text="meta(c)"></span>
                        </li>
                    </template>
                    <li class="sh-find__opt" role="option" :id="optionId(results.length)" :aria-selected="active === results.length ? 'true' : 'false'"
                        :class="active === results.length && 'is-active'" x-on:mousedown.prevent x-on:mousemove="active = results.length" x-on:click="pick(results.length)">
                        <span class="sh-find__name">New customer</span>
                    </li>
                </ul>
            </div>
            <span class="x-sr" role="status" aria-live="polite" x-text="status"></span>
            <div class="sh-grid">
                @include('shipments._form-fields', ['prefix' => 'recipient', 'fields' => $personFields + $streetFields, 'values' => $recipient])
            </div>
            <x-ph-address prefix="recipient" :values="$recipient" />
            <input type="hidden" name="customer_id" value="{{ $customerId }}" x-bind:value="customerId">
            <div x-show="customerId === ''" @if($customerId !== '') x-cloak @endif>
                <label class="sh-switch" for="f-save-customer">
                    <input type="hidden" name="save_customer" value="0">
                    <input type="checkbox" class="sh-switch__input" role="switch" id="f-save-customer" name="save_customer" value="1" @checked($saveCustomer)>
                    <span class="sh-switch__track" aria-hidden="true"></span>
                    <span>Save customer</span>
                </label>
            </div>
            </div>
        </section>

        <section class="x-panel sh-sec" aria-labelledby="sec-parcel">
            <h2 class="st-h" id="sec-parcel">Parcel</h2>
            <x-ui.field label="Item" name="item" :required="true">
                <x-ui.input type="text" id="f-item" name="item" value="{{ $item }}" x-model="item" maxlength="255" required autocomplete="off" />
            </x-ui.field>
            @foreach($fields['parcels'] as $acct => $options)
                @php($here = $acct === $accountId)
                <fieldset class="sh-fs sh-fs--group{{ $errors->has('parcel.type') ? ' is-invalid' : '' }}" x-show="account === @js($acct)" :disabled="account !== @js($acct)" {{ $off($here) }} {!! $groupError('parcel.type', 'f-parcel-type-error') !!}>
                    <legend class="fm-label">Packaging<span class="fm-req" aria-hidden="true">*</span><span class="x-sr">(required)</span></legend>
                    @foreach([false, true] as $more)
                        @php($group = array_values(array_filter($options, fn ($o) => $o['more'] === $more)))
                        @continue($group === [])
                        @if($more)
                            <details class="sh-fold" @if($here && in_array($parcel, array_column($group, 'value'), true)) open @endif>
                                <summary class="sh-fold__toggle"><x-ui.icon name="chevron-right" size="14" class="sh-fold__chev" /> <span>More</span></summary>
                        @endif
                        <div class="sh-pack">
                            @foreach($group as $o)
                                <label class="sh-pack__opt">
                                    <input type="radio" class="sh-pack__input" name="parcel[type]" value="{{ $o['value'] }}" x-model="parcel" @checked($here && $parcel === $o['value'])>
                                    <span class="sh-pack__label">{{ $o['label'] }}</span>
                                    <span class="sh-pack__tick" aria-hidden="true"><x-ui.icon name="check" size="14" /></span>
                                </label>
                            @endforeach
                        </div>
                        @if($more)
                            </details>
                        @endif
                    @endforeach
                </fieldset>
            @endforeach
            <x-ui.field-error name="parcel.type" />
            <fieldset class="sh-fs sh-grid" x-show="show.weight || show.category" {{ $show['weight'] || $show['category'] ? '' : 'x-cloak' }}>
                <fieldset class="sh-fs" x-show="show.weight" :disabled="!show.weight" {{ $off($show['weight']) }}>
                    <x-ui.field label="Weight (kg)" name="parcel.weight_kg" :required="true">
                        <x-ui.input type="text" inputmode="decimal" id="f-parcel-weight-kg" name="parcel[weight_kg]" value="{{ $courierState['weight'] }}" x-model="weight" required autocomplete="off" />
                    </x-ui.field>
                </fieldset>
                <fieldset class="sh-fs" x-show="show.category" :disabled="!show.category" {{ $off($show['category']) }}>
                    <x-ui.field label="Item category" name="parcel.category">
                        <x-ui.select id="f-parcel-category" name="parcel[category]" x-model="category">
                            @foreach(CourierFormFields::categories() as $category)
                                <option value="{{ $category }}" @selected($courierState['category'] === $category)>{{ $category }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </fieldset>
            </fieldset>
            <div x-show="show.addSize && !sizeOpen" {{ $show['addSize'] && ! $sizeOpen ? '' : 'x-cloak' }}>
                <x-ui.button size="sm" @click="sizeOpen = true"><x-ui.icon name="plus" size="14" /> Add size</x-ui.button>
            </div>
            <fieldset class="sh-fs sh-grid sh-grid--3" x-show="show.size" :disabled="!show.size" {{ $off($show['size']) }}>
                @foreach(['length' => 'Length (cm)', 'width' => 'Width (cm)', 'height' => 'Height (cm)'] as $key => $label)
                    @php($sizeKey = 'parcel.'.$key.'_cm')
                    <div class="fm-field{{ $errors->has($sizeKey) ? ' fm-field--invalid' : '' }}">
                        <label class="fm-label" for="f-parcel-{{ $key }}-cm">{{ $label }}<span class="fm-req" aria-hidden="true" x-show="show.sizeRequired">*</span></label>
                        <input class="x-input{{ $errors->has($sizeKey) ? ' is-invalid' : '' }}" type="text" inputmode="decimal" id="f-parcel-{{ $key }}-cm" name="parcel[{{ $key }}_cm]" value="{{ $dims[$key] }}" x-model="{{ $key }}" :required="show.sizeRequired" :aria-required="show.sizeRequired ? 'true' : 'false'" autocomplete="off"
                               @if($errors->has($sizeKey)) aria-invalid="true" aria-describedby="f-parcel-{{ $key }}-cm-error" @endif>
                        <x-ui.field-error :name="$sizeKey" />
                    </div>
                @endforeach
            </fieldset>
            <fieldset class="sh-fs" x-show="show.note" :disabled="!show.note" {{ $off($show['note']) }}>
                <x-ui.field label="Delivery note" name="note">
                    <x-ui.textarea class="sh-note" id="f-note" name="note" rows="2" :maxlength="CourierFormFields::NOTE_MAX" x-model="note" autocomplete="off" :value="$courierState['note']" />
                </x-ui.field>
            </fieldset>
        </section>

        <section class="x-panel sh-sec" aria-labelledby="sec-payment">
            <h2 class="st-h" id="sec-payment">Payment</h2>
            <div class="sh-grid">
                <x-ui.field label="Value (₱)" name="value" :required="true">
                    <x-ui.input type="text" inputmode="decimal" id="f-value" name="value" value="{{ $valueRaw }}" x-model="value" required autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="sh-seg{{ $errors->has('payment.method') ? ' is-invalid' : '' }}" role="radiogroup" aria-labelledby="sec-payment" {!! $groupError('payment.method', 'f-payment-method-error') !!}>
                @foreach(['cod' => 'COD', 'prepaid' => 'Prepaid'] as $value => $label)
                    <label class="sh-seg__opt">
                        <input type="radio" class="sh-seg__input" name="payment[method]" value="{{ $value }}" x-model="method" @checked($method === $value)>
                        <span class="sh-seg__tick" aria-hidden="true"><x-ui.icon name="check" size="14" /></span>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            <x-ui.field-error name="payment.method" />
            <dl class="sh-derived" x-show="method === 'cod'" @if($method !== 'cod') x-cloak @endif>
                <div class="sh-derived__row">
                    <dt>Collect on delivery</dt>
                    <dd class="sh-num" x-text="total">{{ $total }}</dd>
                </div>
            </dl>
            <label class="sh-check">
                <input type="hidden" name="insure" value="0">
                <input type="checkbox" name="insure" value="1" x-model="insure" @checked($insure)>
                <span>Insure</span>
            </label>
            <dl class="sh-derived" x-show="insure" @if(! $insure) x-cloak @endif>
                <div class="sh-derived__row">
                    <dt>Declared value</dt>
                    <dd class="sh-num" x-text="total">{{ $total }}</dd>
                </div>
            </dl>
            <x-ui.field-error name="payment.declared_value" />
            @foreach(CourierFormFields::SHIPPING_PAYMENT as $payer => $choices)
                <fieldset class="sh-fs sh-fs--group" x-show="courier === '{{ $payer }}'" :disabled="courier !== '{{ $payer }}'" {{ $off($courier === $payer) }}>
                    <legend class="fm-label">Who pays shipping</legend>
                    <div class="sh-seg{{ $errors->has('payment.shipping_payment') ? ' is-invalid' : '' }}" {!! $groupError('payment.shipping_payment', 'f-payment-shipping-payment-error') !!}>
                        @foreach($choices as $value => $label)
                            <label class="sh-seg__opt">
                                <input type="radio" class="sh-seg__input" name="payment[shipping_payment]" value="{{ $value }}" x-model="shippingPayment" @checked($courier === $payer && $courierState['shippingPayment'] === $value)>
                                <span class="sh-seg__tick" aria-hidden="true"><x-ui.icon name="check" size="14" /></span>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
            <x-ui.field-error name="payment.shipping_payment" />
        </section>

        <section class="x-panel sh-sec" aria-labelledby="sec-handover" x-show="show.handover || show.pickupDate" {{ $show['handover'] || $show['pickupDate'] ? '' : 'x-cloak' }}>
            <h2 class="st-h" id="sec-handover">Handover</h2>
            <fieldset class="sh-fs sh-fs--group" x-show="show.handover" :disabled="!show.handover" {{ $off($show['handover']) }}>
                <legend class="x-sr">Handover</legend>
                <div class="sh-seg">
                    @foreach(CourierFormFields::HANDOVER as $value => $label)
                        <label class="sh-seg__opt">
                            <input type="radio" class="sh-seg__input" name="handover" value="{{ $value }}" x-model="handover" @checked($courierState['handover'] === $value)>
                            <span class="sh-seg__tick" aria-hidden="true"><x-ui.icon name="check" size="14" /></span>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="sh-slots" x-show="handover === 'pickup'" {{ $courierState['handover'] === 'pickup' ? '' : 'x-cloak' }}>
                    <div class="sh-grid">
                        <x-ui.field label="Pickup time" name="pickup_slot" :required="true">
                            <x-ui.select id="f-pickup-slot" name="pickup_slot" x-model="slot" ::disabled="slotState !== 'ready' || handover !== 'pickup'">
                                <template x-if="slotState !== 'ready'">
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
                    @if(! $courierState['slots'])
                        <x-ui.field-error name="pickup_slot" />
                    @endif
                </div>
            </fieldset>
            <fieldset class="sh-fs sh-grid" x-show="show.pickupDate" :disabled="!show.pickupDate" {{ $off($show['pickupDate']) }}>
                <x-ui.field label="Pickup date" name="pickup_date" :required="true">
                    <x-ui.select id="f-pickup-date" name="pickup_date" x-model="pickupDate">
                        @if($staleDate)<option value="" selected>&nbsp;</option>@endif
                        @foreach($lbcDates as $d)
                            <option value="{{ $d['value'] }}" @selected($pickupDate === $d['value'])>{{ $d['label'] }}</option>
                        @endforeach
                    </x-ui.select>
                    @if($staleDate && ! $errors->has('pickup_date'))<div class="fm-error" id="f-pickup-date-error">Choose a date.</div>@endif
                </x-ui.field>
            </fieldset>
        </section>

        <section class="x-panel sh-sec" aria-labelledby="sec-reference">
            <h2 class="st-h" id="sec-reference">Order reference</h2>
            <div class="sh-grid">
                <x-ui.field label="Reference" name="client_reference">
                    <x-ui.input id="f-client-reference" name="client_reference" class="x-mono" value="{{ $v('client_reference') }}" maxlength="64" autocomplete="off" />
                </x-ui.field>
            </div>
        </section>
    </div>

    <aside class="sh-summary" aria-labelledby="sec-summary">
        <div class="x-panel sh-summary__box">
            <h2 class="st-h" id="sec-summary">Summary</h2>
            <dl class="sh-sum">
                <div><dt>Courier</dt><dd x-text="courierName">{{ $names[$accountId] ?? '—' }}</dd></div>
                <div>
                    <dt>To</dt>
                    <dd class="sh-sum__item">
                        <span x-text="toName">{{ $toName !== '' ? $toName : '—' }}</span>
                        <small class="sh-sum__sub" x-show="toPlace !== ''" x-text="toPlace" @if($place === '') x-cloak @endif>{{ $place }}</small>
                    </dd>
                </div>
                <div><dt>Item</dt><dd class="sh-sum__item" x-text="itemText">{{ trim($item) !== '' ? trim($item) : '—' }}</dd></div>
                <div><dt>Packaging</dt><dd class="sh-sum__item" x-text="packagingText">{{ $packagingLabel ?? '—' }}</dd></div>
                <div><dt>Value</dt><dd class="sh-num" x-text="total">{{ $total }}</dd></div>
                <div><dt>Payment</dt><dd x-text="method === 'cod' ? 'COD' : 'Prepaid'">{{ $method === 'cod' ? 'COD' : 'Prepaid' }}</dd></div>
                <div><dt>Insured</dt><dd x-text="insure ? 'Yes' : 'No'">{{ $insure ? 'Yes' : 'No' }}</dd></div>
            </dl>
            <div class="sh-summary__actions">
                @if($editing)
                    <x-ui.button type="submit">Save</x-ui.button>
                    <x-ui.button variant="primary" type="submit" name="book" value="1" data-book>Save and book</x-ui.button>
                @else
                    <x-ui.button type="submit" name="draft" value="1">Save draft</x-ui.button>
                    <x-ui.button variant="primary" type="submit" data-book>Book shipment</x-ui.button>
                @endif
                <x-ui.button :href="$editing ? route('shipments.show', $shipment->public_id) : route('fulfillment')">Cancel</x-ui.button>
            </div>
        </div>
    </aside>

    <div class="modal-backdrop" x-show="check.open" x-cloak @click.self="closeCheck()" @keydown.escape.window="closeCheck()">
        <div class="modal sh-book" role="dialog" aria-modal="true" aria-labelledby="sh-book-title" x-ref="checkDialog" tabindex="-1"
             @keydown.tab="trapCheck($event)" x-bind:aria-busy="check.state === 'loading' ? 'true' : 'false'">
            <h2 class="modal__title" id="sh-book-title">Book this shipment?</h2>
            <dl class="sh-book__sum">
                <template x-for="row in checkRows" :key="row[0]">
                    <div class="sh-book__row"><dt x-text="row[0]"></dt><dd x-text="row[1]"></dd></div>
                </template>
            </dl>
            <div class="sh-book__fee" x-show="check.state !== 'refused'" role="status" aria-live="polite">
                <span>Shipping fee</span>
                <b class="sh-num" x-show="check.state === 'ready'" x-text="check.label"></b>
                <span class="sh-book__muted" x-show="check.state === 'loading'">Checking…</span>
                <span class="sh-book__muted" x-show="check.state === 'none'">Not available</span>
            </div>
            <div class="sh-book__err" role="alert" x-show="check.state === 'refused'">
                <b x-text="check.title"></b>
                <span x-show="check.detail !== ''" x-text="check.detail"></span>
            </div>
            <div class="modal__actions">
                <x-ui.button x-ref="checkBack" x-bind:class="check.state === 'refused' && 'x-btn--primary'" @click="closeCheck()">Back to edit</x-ui.button>
                <x-ui.button x-bind:class="check.state !== 'refused' && 'x-btn--primary'" x-bind:disabled="!canBook" @click="confirmBook()">Book shipment</x-ui.button>
            </div>
        </div>
    </div>
</form>
@endif
@endsection
