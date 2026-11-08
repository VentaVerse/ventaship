@extends('layouts.app')

@section('title', 'Courier accounts')

@section('content')
@php
    use App\Fulfillment\Couriers;

    $modeWords = ['staging' => 'Staging', 'uat' => 'UAT', 'production' => 'Live'];
@endphp

<section class="x-panel ac-list ac-page">
    @foreach($rows as $row)
        @php
            $account = $row['account'];
            $name = Couriers::name($row['courier']);
            $check = $account && $account->last_check_ok !== null ? ($account->last_check_ok ? ['Connected', 'good'] : ['Check failed', 'bad']) : ['Not checked', 'mute'];
        @endphp
        <div class="ac-row {{ $account ? '' : 'ac-row--off' }}">
            <x-courier-tile :courier="$row['courier']" />
            <div class="ac-row__main">
                <div class="ac-row__name">{{ $name }}</div>
            </div>
            <div class="ac-row__badges">
                @if($account)
                    <x-ui.badge tone="cool">{{ $modeWords[$account->mode] ?? ucfirst($account->mode) }}</x-ui.badge>
                    @if($account->status === 'inactive')
                        <x-ui.badge>Inactive</x-ui.badge>
                    @endif
                    <x-ui.badge :tone="$check[1]">{{ $check[0] }}</x-ui.badge>
                @endif
            </div>
            <div class="ac-row__act">
                <x-ui.button size="sm" :href="route('accounts.show', $row['courier'])" aria-label="{{ $account ? 'Open' : 'Set up' }} {{ $name }}">{{ $account ? 'Open' : 'Set up' }}</x-ui.button>
            </div>
        </div>
    @endforeach
</section>
@endsection
