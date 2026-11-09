@extends('layouts.app')

@section('width', 'form')
@section('title', 'Edit customer')
@section('crumb')<a href="{{ route('addresses.index', ['tab' => 'customers']) }}">Addresses</a>@endsection

@section('content')
<section class="x-panel addr-page">
    <form method="POST" action="{{ route('addresses.customers.update', $customer) }}" novalidate>
        @csrf
        @method('PUT')
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
                    <x-ui.input type="tel" id="f-phone" name="phone" class="st-ctl" value="{{ old('phone', $customer->phone) }}" maxlength="32" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <x-ui.field label="Email" name="email">
                    <x-ui.input type="email" id="f-email" name="email" class="st-ctl" value="{{ old('email', $values['email'] ?? '') }}" maxlength="255" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <x-ui.field label="Street" name="address_1" required>
                    <x-ui.input id="f-address-1" name="address_1" class="st-ctl" value="{{ old('address_1', $values['address_1'] ?? '') }}" maxlength="255" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <x-ui.field label="Address line 2" name="address_2">
                    <x-ui.input id="f-address-2" name="address_2" class="st-ctl" value="{{ old('address_2', $values['address_2'] ?? '') }}" maxlength="255" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <x-ph-address prefix="address" :values="old('address', $values)" />
            </div>
        </div>
        <div class="st-bar">
            <x-ui.button class="addr-delete" x-on:click="$dispatch('confirm', {{ Js::from(['title' => 'Delete '.$customer->name.'?', 'confirm' => 'Delete customer', 'dismiss' => 'Keep customer', 'form' => 'delete-customer']) }})">Delete</x-ui.button>
            <x-ui.button :href="route('addresses.index', ['tab' => 'customers'])">Discard</x-ui.button>
            <x-ui.button variant="primary" type="submit">Save</x-ui.button>
        </div>
    </form>
    <form id="delete-customer" method="POST" action="{{ route('addresses.customers.destroy', $customer) }}">@csrf @method('DELETE')</form>
</section>
@endsection
