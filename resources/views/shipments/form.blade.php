@extends('layouts.app')

@section('title', 'Add shipment')

@section('crumb')
    <a href="{{ route('fulfillment') }}">Shipments</a>
@endsection

@section('content')
@php
    $personFields = [
        'first_name' => ['label' => 'First name', 'required' => true],
        'last_name' => ['label' => 'Last name'],
        'phone' => ['label' => 'Phone', 'type' => 'tel', 'required' => true, 'max' => 32],
        'email' => ['label' => 'Email', 'type' => 'email'],
        'address_1' => ['label' => 'Address', 'required' => true],
        'address_2' => ['label' => 'Address line 2'],
        'barangay' => ['label' => 'Barangay'],
        'city' => ['label' => 'City', 'required' => true],
        'state' => ['label' => 'Province', 'required' => true],
        'postcode' => ['label' => 'Postcode', 'class' => 'st-narrow', 'max' => 16],
    ];
    $senderFields = [
        'contact_name' => ['label' => 'Name', 'required' => true],
        'contact_phone' => ['label' => 'Phone', 'type' => 'tel', 'required' => true, 'max' => 32],
        'address_1' => ['label' => 'Address', 'required' => true],
        'address_2' => ['label' => 'Address line 2'],
        'barangay' => ['label' => 'Barangay'],
        'city' => ['label' => 'City', 'required' => true],
        'state' => ['label' => 'Province', 'required' => true],
        'postcode' => ['label' => 'Postcode', 'class' => 'st-narrow', 'max' => 16],
    ];
    $oldItems = old('items');
    $rows = is_array($oldItems) && $oldItems !== []
        ? array_map(fn ($i) => ['name' => (string) ($i['name'] ?? ''), 'quantity' => (string) ($i['quantity'] ?? '1'), 'price' => (string) ($i['price'] ?? '')], array_values($oldItems))
        : [['name' => '', 'quantity' => '1', 'price' => '']];
    $itemErrors = [];
    foreach (array_keys($rows) as $n) {
        foreach (['name', 'quantity', 'price'] as $f) {
            $itemErrors[$n][$f] = $errors->first("items.{$n}.{$f}");
        }
    }
    $method = old('payment.method', 'prepaid');
    $accountId = old('account_id', $accounts[0]['id'] ?? '');
@endphp

@if($accounts === [])
    <section class="x-panel">
        <x-ui.empty title="No courier accounts" />
    </section>
@else
<section class="x-panel st-panel">
    <form method="POST" action="{{ route('shipments.store') }}" novalidate>
        @csrf
        <input type="hidden" name="form_token" value="{{ $token }}">

        <div class="st-sec" x-data="serviceSelect(@js(['services' => $services, 'account' => $accountId, 'service' => (string) old('service', '')]))">
            <h2 class="st-h">Courier</h2>
            <div class="st-row">
                <x-ui.field label="Account" name="account_id" :required="true">
                    <x-ui.select wrapClass="st-ctl" id="f-account-id" name="account_id" x-model="account">
                        @foreach($accounts as $a)
                            <option value="{{ $a['id'] }}" @selected($accountId === $a['id'])>{{ $a['label'] }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
            <div class="st-row" x-show="options.length > 0" @if(($services[$accountId] ?? []) === []) x-cloak @endif>
                <x-ui.field label="Service" name="service">
                    <x-ui.select wrapClass="st-ctl" id="f-service" name="service" x-model="service">
                        <option value="">Default</option>
                        <template x-for="o in options" :key="o.value">
                            <option :value="o.value" x-text="o.label" :selected="o.value === service"></option>
                        </template>
                    </x-ui.select>
                </x-ui.field>
            </div>
        </div>

        <div class="st-sec">
            <h2 class="st-h">Customer</h2>
            @include('shipments._form-fields', ['prefix' => 'recipient', 'fields' => $personFields, 'values' => []])
        </div>

        <div class="st-sec" x-data="itemRows(@js(['rows' => $rows, 'errors' => $itemErrors]))">
            <h2 class="st-h">Items</h2>
            @error('items')<div class="fm-error">{{ $message }}</div>@enderror
            <div class="sh-items">
                <div class="sh-items__head" aria-hidden="true">
                    <span>Name</span><span>Qty</span><span>Price</span><span></span>
                </div>
                <template x-for="(row, n) in rows" :key="row.key">
                    <div class="sh-item">
                        <div class="fm-field">
                            <label class="x-sr" :for="'f-items-' + n + '-name'">Item name</label>
                            <input class="x-input" type="text" :id="'f-items-' + n + '-name'" :name="'items[' + n + '][name]'" x-model="row.name" maxlength="255" required autocomplete="off">
                            <div class="fm-error" x-show="row.errors.name" x-text="row.errors.name"></div>
                        </div>
                        <div class="fm-field">
                            <label class="x-sr" :for="'f-items-' + n + '-quantity'">Quantity</label>
                            <input class="x-input" type="number" min="1" step="1" :id="'f-items-' + n + '-quantity'" :name="'items[' + n + '][quantity]'" x-model="row.quantity" required>
                            <div class="fm-error" x-show="row.errors.quantity" x-text="row.errors.quantity"></div>
                        </div>
                        <div class="fm-field">
                            <label class="x-sr" :for="'f-items-' + n + '-price'">Price</label>
                            <input class="x-input" type="text" inputmode="decimal" :id="'f-items-' + n + '-price'" :name="'items[' + n + '][price]'" x-model="row.price" autocomplete="off">
                            <div class="fm-error" x-show="row.errors.price" x-text="row.errors.price"></div>
                        </div>
                        <x-ui.button size="sm" class="sh-item__remove" ::disabled="rows.length === 1" @click="remove(n)" aria-label="Remove item">
                            <x-ui.icon name="trash" size="14" /> Remove
                        </x-ui.button>
                    </div>
                </template>
            </div>
            <x-ui.button size="sm" @click="add()"><x-ui.icon name="plus" size="14" /> Add item</x-ui.button>
        </div>

        <div class="st-sec">
            <h2 class="st-h">Parcel</h2>
            <div class="st-row">
                <x-ui.field label="Weight (kg)" name="parcel.weight_kg" :required="true">
                    <x-ui.input type="text" inputmode="decimal" id="f-parcel-weight-kg" name="parcel[weight_kg]" class="st-ctl st-narrow" value="{{ old('parcel.weight_kg') }}" required autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <x-ui.field label="Type" name="parcel.type">
                    <x-ui.input id="f-parcel-type" name="parcel[type]" class="st-ctl" value="{{ old('parcel.type') }}" maxlength="64" autocomplete="off" />
                </x-ui.field>
            </div>
            @foreach(['length_cm' => 'Length (cm)', 'width_cm' => 'Width (cm)', 'height_cm' => 'Height (cm)'] as $key => $label)
                <div class="st-row">
                    <x-ui.field :label="$label" :name="'parcel.'.$key">
                        <x-ui.input type="text" inputmode="decimal" id="f-parcel-{{ str_replace('_', '-', $key) }}" name="parcel[{{ $key }}]" class="st-ctl st-narrow" value="{{ old('parcel.'.$key) }}" autocomplete="off" />
                    </x-ui.field>
                </div>
            @endforeach
        </div>

        <div class="st-sec" x-data="{ method: @js($method) }">
            <h2 class="st-h">Payment</h2>
            <div class="st-row">
                <div class="fm-field">
                    <span class="fm-label" id="pay-method-label">Method</span>
                    <div class="sh-radios" role="radiogroup" aria-labelledby="pay-method-label">
                        @foreach(['prepaid' => 'Prepaid', 'cod' => 'COD'] as $value => $label)
                            <label class="sh-radio">
                                <input type="radio" name="payment[method]" value="{{ $value }}" x-model="method" @checked($method === $value)>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('payment.method')<div class="fm-error">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="st-row" x-show="method === 'cod'" @if($method !== 'cod') x-cloak @endif>
                <x-ui.field label="COD amount" name="payment.cod_amount" :required="true">
                    <x-ui.input type="text" inputmode="decimal" id="f-payment-cod-amount" name="payment[cod_amount]" class="st-ctl st-narrow" value="{{ old('payment.cod_amount') }}" x-bind:required="method === 'cod'" x-bind:disabled="method !== 'cod'" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <x-ui.field label="Declared value" name="payment.declared_value">
                    <x-ui.input type="text" inputmode="decimal" id="f-payment-declared-value" name="payment[declared_value]" class="st-ctl st-narrow" value="{{ old('payment.declared_value') }}" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <label class="sh-check">
                    <input type="hidden" name="insure" value="0">
                    <input type="checkbox" name="insure" value="1" @checked(filter_var(old('insure'), FILTER_VALIDATE_BOOLEAN))>
                    <span>Insure</span>
                </label>
            </div>
        </div>

        <div class="st-sec">
            <h2 class="st-h">Sender</h2>
            @include('shipments._form-fields', ['prefix' => 'pickup', 'fields' => $senderFields, 'values' => $sender])
        </div>

        <div class="st-sec">
            <h2 class="st-h">Order reference</h2>
            <div class="st-row">
                <x-ui.field label="Reference" name="client_reference">
                    <x-ui.input id="f-client-reference" name="client_reference" class="st-ctl x-mono" value="{{ old('client_reference') }}" maxlength="64" autocomplete="off" />
                </x-ui.field>
            </div>
        </div>

        <div class="st-bar">
            <x-ui.button :href="route('fulfillment')">Cancel</x-ui.button>
            <x-ui.button variant="primary" type="submit">Book shipment</x-ui.button>
        </div>
    </form>
</section>
@endif
@endsection
