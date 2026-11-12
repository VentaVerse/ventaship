@extends('layouts.app')

@section('title', 'Courier accounts')

@section('content')
@php
    use App\Fulfillment\Couriers;

    $envWords = ['sandbox' => 'Sandbox', 'production' => 'Production'];
@endphp

<section class="x-panel ac-page" aria-label="Couriers">
    <ul class="ac-rows" role="list">
        @foreach($rows as $row)
            @php
                $account = $row['account'];
                $name = Couriers::name($row['courier']);
                $failed = $account && $account->last_check_ok === false;
                $check = $account && $account->last_check_ok !== null ? ($account->last_check_ok ? ['Connected', 'good'] : ['Check failed', 'bad']) : ['Not checked', 'mute'];
                $action = ! $account ? 'Set up' : ($failed ? 'Fix' : 'Open');
            @endphp
            <li class="ac-row{{ $account ? '' : ' ac-row--off' }}">
                <x-courier-tile :courier="$row['courier']" />
                <div class="ac-row__main">
                    <div class="ac-row__line">
                        <a class="ac-row__name" href="{{ route('accounts.show', $row['courier']) }}">{{ $name }}</a>
                        <span class="ac-row__badges">
                            @if($account)
                                {{-- Amber marks a live account (real parcels); green only ever means the connection check passed. --}}
                                <x-ui.badge :tone="$account->mode === 'production' ? 'attn' : 'cool'">{{ $envWords[$account->mode] ?? ucfirst($account->mode) }}</x-ui.badge>
                                @if(! $account->isActive())
                                    <x-ui.badge>Inactive</x-ui.badge>
                                @endif
                                <x-ui.badge :tone="$check[1]">{{ $check[0] }}</x-ui.badge>
                            @else
                                <x-ui.badge>Not set up</x-ui.badge>
                            @endif
                        </span>
                    </div>
                    @if($account?->last_check_at)
                        <div class="ac-row__sub">Checked <time datetime="{{ $account->last_check_at->toIso8601String() }}">{{ $account->last_check_at->format('M j, g:i A') }}</time></div>
                    @endif
                </div>
                <div class="ac-row__act">
                    @if($action === 'Open')
                        <x-ui.icon-link icon="pencil" :href="route('accounts.show', $row['courier'])" :label="'Edit '.$name" />
                    @else
                        <x-ui.button size="sm" :variant="$account ? 'danger-outline' : 'primary'" :href="route('accounts.show', $row['courier'])" aria-label="{{ $action }} {{ $name }}">{{ $action }}</x-ui.button>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</section>
@endsection
