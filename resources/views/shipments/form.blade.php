@extends('layouts.app')

@section('width', 'wide')
@section('title', $editing ? 'Edit shipment' : 'Add shipment')

@section('crumb')
    <a href="{{ route('fulfillment') }}">Shipments</a>
@endsection

@section('content')
@php
    use App\Http\Requests\Ui\ShipmentFormRequest;
    use App\Support\Money;

    $personFields = [
        'first_name' => ['label' => 'First name', 'required' => true],
        'last_name' => ['label' => 'Last name'],
        'phone' => ['label' => 'Phone', 'type' => 'tel', 'required' => true, 'max' => 32],
        'email' => ['label' => 'Email', 'type' => 'email'],
    ];
    $streetFields = [
        'address_1' => ['label' => 'Street', 'required' => true, 'class' => 'sh-span'],
        'address_2' => ['label' => 'Unit / building', 'class' => 'sh-span'],
    ];
    $senderFields = [
        'contact_name' => ['label' => 'Name', 'required' => true],
        'contact_phone' => ['label' => 'Phone', 'type' => 'tel', 'required' => true, 'max' => 32],
    ];
    $v = fn (string $key, mixed $default = '') => old($key, data_get($prefill, $key, $default));
    $oldItems = $v('items');
    $rows = is_array($oldItems) && $oldItems !== []
        ? array_map(fn ($i) => ['name' => (string) ($i['name'] ?? ''), 'quantity' => (string) ($i['quantity'] ?? '1')], array_values($oldItems))
        : [['name' => '', 'quantity' => '1']];
    $itemErrors = [];
    foreach (array_keys($rows) as $n) {
        foreach (['name', 'quantity'] as $f) {
            $itemErrors[$n][$f] = $errors->first("items.{$n}.{$f}");
        }
    }
    $value = ShipmentFormRequest::normaliseValue((string) $v('value'));
    $valueOk = preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value) === 1;
    $total = Money::peso($valueOk ? $value : '0');
    $count = array_sum(array_map(fn ($r) => ctype_digit($r['quantity']) ? (int) $r['quantity'] : 0, $rows));
    $method = $v('payment.method', 'prepaid');
    $insure = filter_var($v('insure'), FILTER_VALIDATE_BOOLEAN);
    $usable = array_values(array_filter($tiles, fn ($t) => $t['id'] !== null));
    $accountId = $v('account_id', $usable[0]['id'] ?? '');
    $names = collect($usable)->mapWithKeys(fn ($t) => [$t['id'] => $t['name']])->all();
    $recipient = (array) old('recipient', $prefill['recipient'] ?? []);
    $pickup = (array) old('pickup', $sender ?? []);
    $pickupErrors = collect($errors->keys())->contains(fn ($k) => str_starts_with($k, 'pickup.'));
    $senderOpen = $sender === null || $pickupErrors;
    $senderLine = collect([$pickup['address_1'] ?? '', $pickup['address_2'] ?? '', $pickup['barangay'] ?? '', $pickup['district'] ?? '', $pickup['city'] ?? '', $pickup['state'] ?? '', $pickup['postcode'] ?? ''])
        ->map(fn ($p) => trim((string) $p))->filter()->implode(', ');
    $sizeOpen = collect(['type', 'length_cm', 'width_cm', 'height_cm'])->contains(fn ($k) => (string) $v('parcel.'.$k) !== '' || $errors->has('parcel.'.$k));
    $config = [
        'services' => $services, 'account' => $accountId, 'service' => (string) $v('service'), 'names' => $names,
        'rows' => $rows, 'errors' => $itemErrors, 'value' => (string) $v('value'), 'method' => $method, 'insure' => $insure,
    ];
@endphp

@if($usable === [])
    <section class="x-panel">
        <x-ui.empty title="No courier accounts" />
    </section>
@else
<form class="sh-form" method="POST" action="{{ $editing ? route('shipments.update', $shipment->public_id) : route('shipments.store') }}" novalidate x-data="shipmentForm(@js($config))">
    @csrf
    @if($editing) @method('PUT') @endif
    <input type="hidden" name="form_token" value="{{ $token }}">

    <div class="sh-main">
        <section class="x-panel sh-sec" aria-labelledby="sec-courier">
            <h2 class="st-h" id="sec-courier">Courier</h2>
            <div class="sh-couriers" role="radiogroup" aria-labelledby="sec-courier">
                @foreach($tiles as $t)
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
                            @elseif($t['id'] === null)
                                <span class="sh-courier__note">Not set up</span>
                            @endif
                        </span>
                        <span class="sh-courier__tick" aria-hidden="true"><x-ui.icon name="check" size="14" /></span>
                    </label>
                @endforeach
            </div>
            @error('account_id')<div class="fm-error">{{ $message }}</div>@enderror
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

        <section class="x-panel sh-sec" aria-labelledby="sec-customer">
            <h2 class="st-h" id="sec-customer">Customer</h2>
            <div class="sh-grid">
                @include('shipments._form-fields', ['prefix' => 'recipient', 'fields' => $personFields + $streetFields, 'values' => $recipient])
            </div>
            <x-ph-address prefix="recipient" :values="$recipient" />
        </section>

        <section class="x-panel sh-sec" aria-labelledby="sec-items">
            <h2 class="st-h" id="sec-items">Items</h2>
            @error('items')<div class="fm-error">{{ $message }}</div>@enderror
            <div class="sh-items">
                <div class="sh-items__head" aria-hidden="true">
                    <span>Name</span><span>Qty</span><span></span>
                </div>
                <template x-for="(row, n) in rows" :key="row.key">
                    <div class="sh-item">
                        <div class="fm-field sh-item__name">
                            <label class="x-sr" :for="'f-items-' + n + '-name'">Item name</label>
                            <input class="x-input" type="text" :id="'f-items-' + n + '-name'" :name="'items[' + n + '][name]'" x-model="row.name" maxlength="255" required autocomplete="off">
                            <div class="fm-error" x-show="row.errors.name" x-text="row.errors.name"></div>
                        </div>
                        <div class="fm-field">
                            <label class="x-sr" :for="'f-items-' + n + '-quantity'">Quantity</label>
                            <input class="x-input" type="number" min="1" step="1" :id="'f-items-' + n + '-quantity'" :name="'items[' + n + '][quantity]'" x-model="row.quantity" required>
                            <div class="fm-error" x-show="row.errors.quantity" x-text="row.errors.quantity"></div>
                        </div>
                        <x-ui.button size="sm" class="sh-item__remove" ::disabled="rows.length === 1" @click="remove(n)" aria-label="Remove item">
                            <x-ui.icon name="trash" size="14" />
                        </x-ui.button>
                    </div>
                </template>
            </div>
            <div class="sh-items__foot">
                <x-ui.button size="sm" @click="add()"><x-ui.icon name="plus" size="14" /> Add item</x-ui.button>
            </div>
        </section>

        <section class="x-panel sh-sec" aria-labelledby="sec-payment">
            <h2 class="st-h" id="sec-payment">Payment</h2>
            <div class="sh-grid">
                <x-ui.field label="Value (₱)" name="value" :required="true">
                    <x-ui.input type="text" inputmode="decimal" id="f-value" name="value" value="{{ $v('value') }}" x-model="value" required autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="sh-seg" role="radiogroup" aria-labelledby="sec-payment">
                @foreach(['cod' => 'COD', 'prepaid' => 'Prepaid'] as $value => $label)
                    <label class="sh-seg__opt">
                        <input type="radio" class="sh-seg__input" name="payment[method]" value="{{ $value }}" x-model="method" @checked($method === $value)>
                        <span class="sh-seg__tick" aria-hidden="true"><x-ui.icon name="check" size="14" /></span>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            @error('payment.method')<div class="fm-error">{{ $message }}</div>@enderror
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
            @error('payment.declared_value')<div class="fm-error">{{ $message }}</div>@enderror
        </section>

        <section class="x-panel sh-sec" aria-labelledby="sec-parcel">
            <h2 class="st-h" id="sec-parcel">Parcel</h2>
            <div class="sh-grid">
                <x-ui.field label="Weight (kg)" name="parcel.weight_kg" :required="true">
                    <x-ui.input type="text" inputmode="decimal" id="f-parcel-weight-kg" name="parcel[weight_kg]" value="{{ $v('parcel.weight_kg') }}" required autocomplete="off" />
                </x-ui.field>
            </div>
            <details class="sh-fold" @if($sizeOpen) open @endif>
                <summary class="sh-fold__toggle"><x-ui.icon name="chevron-right" size="14" class="sh-fold__chev" /> Size</summary>
                <div class="sh-grid sh-grid--4">
                    <x-ui.field label="Type" name="parcel.type">
                        <x-ui.input id="f-parcel-type" name="parcel[type]" value="{{ $v('parcel.type') }}" maxlength="64" autocomplete="off" />
                    </x-ui.field>
                    @foreach(['length_cm' => 'Length (cm)', 'width_cm' => 'Width (cm)', 'height_cm' => 'Height (cm)'] as $key => $label)
                        <x-ui.field :label="$label" :name="'parcel.'.$key">
                            <x-ui.input type="text" inputmode="decimal" :id="'f-parcel-'.str_replace('_', '-', $key)" name="parcel[{{ $key }}]" value="{{ $v('parcel.'.$key) }}" autocomplete="off" />
                        </x-ui.field>
                    @endforeach
                </div>
            </details>
        </section>

        <section class="x-panel sh-sec" aria-labelledby="sec-sender" x-data="{ open: {{ $senderOpen ? 'true' : 'false' }} }">
            <h2 class="st-h" id="sec-sender">Sender</h2>
            @if($sender !== null)
                <div class="sh-sender__card" x-show="!open" @if($senderOpen) x-cloak @endif>
                    <x-ui.icon name="map-pin" size="16" class="sh-sender__icon" />
                    <div class="sh-sender__text">
                        <div class="sh-sender__name">{{ $pickup['contact_name'] ?? '' }} <span class="sh-sender__phone">{{ $pickup['contact_phone'] ?? '' }}</span></div>
                        <div class="sh-sender__addr">{{ $senderLine }}</div>
                    </div>
                    <x-ui.button size="sm" @click="open = true">Change</x-ui.button>
                </div>
            @endif
            <div class="sh-sender__fields" x-show="open" @if(! $senderOpen) x-cloak @endif>
                <div class="sh-grid">
                    @include('shipments._form-fields', ['prefix' => 'pickup', 'fields' => $senderFields + $streetFields, 'values' => $pickup])
                </div>
                <x-ph-address prefix="pickup" :values="$pickup" />
            </div>
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
                <div><dt>Items</dt><dd class="sh-num" x-text="count">{{ $count }}</dd></div>
                <div><dt>Total</dt><dd class="sh-num" x-text="total">{{ $total }}</dd></div>
                <div><dt>Payment</dt><dd x-text="method === 'cod' ? 'COD · ' + total : 'Prepaid'">{{ $method === 'cod' ? 'COD · '.$total : 'Prepaid' }}</dd></div>
                <div><dt>Insured</dt><dd x-text="insure ? 'Yes' : 'No'">{{ $insure ? 'Yes' : 'No' }}</dd></div>
            </dl>
            <div class="sh-summary__actions">
                @if($editing)
                    <x-ui.button type="submit">Save</x-ui.button>
                    <x-ui.button variant="primary" type="submit" name="book" value="1">Save and book</x-ui.button>
                @else
                    <x-ui.button variant="primary" type="submit">Book shipment</x-ui.button>
                @endif
                <x-ui.button :href="$editing ? route('shipments.show', $shipment->public_id) : route('fulfillment')">Cancel</x-ui.button>
            </div>
        </div>
    </aside>
</form>
@endif
@endsection
