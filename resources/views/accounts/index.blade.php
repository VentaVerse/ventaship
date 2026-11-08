@extends('layouts.app')

@section('title', 'Courier accounts')

@section('actions')
    <div class="ac-actions">
        <x-ui.button variant="primary" :href="route('accounts.create')"><x-ui.icon name="plus" size="14" /> Connect courier</x-ui.button>
    </div>
@endsection

@section('content')
@php
    use App\Fulfillment\Couriers;

    $modeWords = ['staging' => 'Staging', 'uat' => 'UAT', 'production' => 'Live'];
@endphp

<section class="x-panel ac-list ac-page">
    @foreach($accounts as $account)
        @php
            $name = Couriers::name($account->courier);
            $check = $account->last_check_ok === null ? ['Not checked', 'mute'] : ($account->last_check_ok ? ['Connected', 'good'] : ['Check failed', 'bad']);
        @endphp
        <div class="ac-row">
            <x-courier-tile :courier="$account->courier" />
            <div class="ac-row__main">
                <div class="ac-row__name">{{ $name }} · {{ $account->label }}</div>
                @if($account->is_default)
                    <div class="ac-row__sub">Default for {{ $name }}</div>
                @endif
            </div>
            <div class="ac-row__badges">
                <x-ui.badge tone="cool">{{ $modeWords[$account->mode] ?? ucfirst($account->mode) }}</x-ui.badge>
                @if($account->status === 'inactive')
                    <x-ui.badge>Inactive</x-ui.badge>
                @endif
                <x-ui.badge :tone="$check[1]">{{ $check[0] }}</x-ui.badge>
            </div>
            <div class="ac-row__act">
                <x-ui.button size="sm" :href="route('accounts.show', $account)" aria-label="Open {{ $name }} · {{ $account->label }}">Open</x-ui.button>
            </div>
        </div>
    @endforeach

    @foreach($unconnected as $courier)
        <div class="ac-row ac-row--off">
            <x-courier-tile :courier="$courier" />
            <div class="ac-row__main">
                <div class="ac-row__name">{{ Couriers::name($courier) }}</div>
                <div class="ac-row__sub">No account</div>
            </div>
            <div class="ac-row__badges"></div>
            <div class="ac-row__act">
                <x-ui.button size="sm" :href="route('accounts.create', ['courier' => $courier])" aria-label="Connect {{ Couriers::name($courier) }}">Connect</x-ui.button>
            </div>
        </div>
    @endforeach
</section>
@endsection
