@extends('layouts.app')

@section('width', 'form')
@section('title', $customer ? 'Edit customer' : 'Add customer')
@section('crumb')<a href="{{ route('addresses.index', ['tab' => 'customers']) }}">Addresses</a>@endsection

@section('content')
<section class="x-panel addr-page">
    <form method="POST" action="{{ $customer ? route('addresses.customers.update', $customer) : route('addresses.customers.store') }}" novalidate>
        @csrf
        @if($customer)@method('PUT')@endif
        <div class="st-sec">
            <div class="st-row">
                <x-ui.field label="First name" name="first_name">
                    <x-ui.input id="f-first-name" name="first_name" class="st-ctl" value="{{ old('first_name', $values['first_name'] ?? '') }}" maxlength="255" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <x-ui.field label="Last name" name="last_name">
                    <x-ui.input id="f-last-name" name="last_name" class="st-ctl" value="{{ old('last_name', $values['last_name'] ?? '') }}" maxlength="255" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <x-ui.field label="Phone" name="phone" required>
                    <x-ui.input type="tel" id="f-phone" name="phone" class="st-ctl" value="{{ old('phone', $customer?->phone) }}" maxlength="32" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <x-ui.field label="Street" name="address_1" required>
                    <x-ui.input id="f-address-1" name="address_1" class="st-ctl" value="{{ old('address_1', $values['address_1'] ?? '') }}" maxlength="255" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <x-ph-address prefix="address" :values="old('address', $values)" />
            </div>
        </div>
        <div class="st-bar">
            @if($customer)
                <x-ui.button class="addr-delete" x-on:click="$dispatch('confirm', {{ Js::from(['title' => 'Delete '.$customer->name.'?', 'confirm' => 'Delete customer', 'dismiss' => 'Keep customer', 'form' => 'delete-customer']) }})">Delete</x-ui.button>
            @endif
            <x-ui.button :href="route('addresses.index', ['tab' => 'customers'])">Discard</x-ui.button>
            <x-ui.button variant="primary" type="submit">Save</x-ui.button>
        </div>
    </form>
    @if($customer)
        <form id="delete-customer" method="POST" action="{{ route('addresses.customers.destroy', $customer) }}">@csrf @method('DELETE')</form>
    @endif
</section>
@endsection
