@extends('layouts.app')

@section('width', 'form')
@section('title', $pickup ? 'Edit address' : 'Add address')
@section('crumb')<a href="{{ route('addresses.index', ['tab' => 'pickup']) }}">Addresses</a>@endsection

@section('content')
<section class="x-panel addr-page">
    <form method="POST" action="{{ $pickup ? route('addresses.pickup.update', $pickup) : route('addresses.pickup.store') }}" novalidate>
        @csrf
        @if($pickup)@method('PUT')@endif
        <div class="st-sec">
            <div class="st-row">
                <x-ui.field label="Label" name="label" required>
                    <x-ui.input id="f-label" name="label" class="st-ctl" value="{{ old('label', $values['label'] ?? '') }}" maxlength="255" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <x-ui.field label="Contact name" name="contact_name" required>
                    <x-ui.input id="f-contact-name" name="contact_name" class="st-ctl" value="{{ old('contact_name', $values['contact_name'] ?? '') }}" maxlength="255" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <x-ui.field label="Phone" name="contact_phone" required>
                    <x-ui.input type="tel" id="f-contact-phone" name="contact_phone" class="st-ctl" value="{{ old('contact_phone', $values['contact_phone'] ?? '') }}" maxlength="32" autocomplete="off" />
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
            @if($pickup)
                <x-ui.button class="addr-delete" x-on:click="$dispatch('confirm', {{ Js::from(['title' => 'Delete '.$pickup->label.'?', 'confirm' => 'Delete address', 'dismiss' => 'Keep address', 'form' => 'delete-address']) }})">Delete</x-ui.button>
            @endif
            <x-ui.button :href="route('addresses.index', ['tab' => 'pickup'])">Discard</x-ui.button>
            <x-ui.button variant="primary" type="submit">Save</x-ui.button>
        </div>
    </form>
    @if($pickup)
        <form id="delete-address" method="POST" action="{{ route('addresses.pickup.destroy', $pickup) }}">@csrf @method('DELETE')</form>
    @endif
</section>
@endsection
