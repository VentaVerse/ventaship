@extends('layouts.app')

@section('width', 'form')
@section('title', 'Connect courier')

@section('content')
@php
    use App\Fulfillment\Couriers;

    $modeWords = ['staging' => 'Staging', 'production' => 'Live'];
    $selected = old('courier', $courier);
@endphp

<section class="x-panel ac-form ac-page">
    <form method="POST" action="{{ route('accounts.store') }}" novalidate>
        @csrf
        <div class="st-sec">
            <h2 class="st-h">Account</h2>
            <div class="st-row">
                <x-ui.field label="Courier" name="courier">
                    <x-ui.select id="f-courier" name="courier" class="st-ctl" wrapClass="st-ctl">
                        <option value="">Choose a courier</option>
                        @foreach($couriers as $key)
                            <option value="{{ $key }}" @selected($selected === $key)>{{ Couriers::name($key) }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
            <div class="st-row">
                <x-ui.field label="Name" name="label">
                    <x-ui.input id="f-label" name="label" class="st-ctl" value="{{ old('label') }}" maxlength="255" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <x-ui.field label="Mode" name="mode">
                    <x-ui.select id="f-mode" name="mode" class="st-ctl" wrapClass="st-ctl">
                        @foreach($modes as $mode)
                            <option value="{{ $mode }}" @selected(old('mode', 'staging') === $mode)>{{ $modeWords[$mode] }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
            <div class="st-row st-row--switch">
                <label class="st-switch" for="f-default">
                    <span class="st-switch__label">Default for this courier</span>
                    <input type="hidden" name="default" value="0">
                    <input type="checkbox" class="st-switch__input" role="switch" id="f-default" name="default" value="1" @checked(old('default') === '1')>
                    <span class="st-switch__track" aria-hidden="true"></span>
                </label>
            </div>
        </div>
        <div class="st-bar">
            <x-ui.button :href="route('accounts.index')">Cancel</x-ui.button>
            <x-ui.button variant="primary" type="submit">Connect</x-ui.button>
        </div>
    </form>
</section>
@endsection
