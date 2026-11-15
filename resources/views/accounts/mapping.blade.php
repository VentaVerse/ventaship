@extends('layouts.app')

@php
    use App\Fulfillment\Couriers;

    $name = Couriers::name($courier);
    $envWords = ['sandbox' => 'Sandbox', 'production' => 'Production'];
    $envTones = ['sandbox' => 'cool', 'production' => 'attn'];
    $check = ! $account ? ['Not set up', 'mute'] : ($account->last_check_ok !== null ? ($account->last_check_ok ? ['Connected', 'good'] : ['Check failed', 'bad']) : ['Not checked', 'mute']);
@endphp

@section('title', $name)

@section('title_lead')<x-courier-tile :courier="$courier" :logo="true" class="ac-title-tile" />@endsection

@section('title_after')
    <span class="ac-title-badges">
        @if($account)
            <x-ui.badge :tone="$envTones[$account->mode] ?? 'cool'">{{ $envWords[$account->mode] ?? 'Sandbox' }}</x-ui.badge>
        @endif
        <x-ui.badge :tone="$check[1]">{{ $check[0] }}</x-ui.badge>
        @if($account?->status === 'inactive')
            <x-ui.badge>Inactive</x-ui.badge>
        @endif
    </span>
@endsection

@section('crumb')<x-ui.back :href="route('accounts.index')">Couriers</x-ui.back>@endsection

@if($account)
@section('actions')
    <form method="POST" action="{{ route('accounts.test', $courier) }}" class="ac-actions">
        @csrf
        <x-ui.button type="submit">Test connection</x-ui.button>
    </form>
@endsection
@endif

@section('content')
<section class="x-panel ac-page">
    @include('accounts._tabs', ['tab' => 'mapping'])
    <form method="POST" action="{{ route('accounts.mapping.update', $courier) }}" novalidate>
        @csrf
        @method('PUT')
        <div class="sm-wrap">
            <table class="sm-table">
                <thead>
                    <tr>
                        @if($courier === 'spx')<th scope="col">Code</th>@endif
                        <th scope="col">{{ $name }} status</th>
                        <th scope="col">Shipment status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        @php $id = 'f-map-'.md5($row['code']); @endphp
                        <tr>
                            @if($courier === 'spx')<td class="sm-code x-num">{{ $row['code'] }}</td>@endif
                            <td><label for="{{ $id }}">{{ $row['label'] }}</label></td>
                            <td>
                                <x-ui.select :id="$id" name="statuses[{{ $row['code'] }}]" wrapClass="sm-sel">
                                    <option value="" @selected($row['current'] === null)>Unmapped</option>
                                    @foreach($options as $key => $label)
                                        <option value="{{ $key }}" @selected($row['current'] === $key)>{{ $label }}</option>
                                    @endforeach
                                </x-ui.select>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="st-bar">
            <x-ui.button variant="primary" type="submit">Save</x-ui.button>
        </div>
    </form>
</section>
@endsection
