@extends('layouts.app')

@section('title', 'Addresses')

@section('actions')
    @if($tab === 'pickup')
        <x-ui.button variant="primary" :href="route('addresses.pickup.create')">Add address</x-ui.button>
    @endif
@endsection

@php
    $line = fn (array $a): string => collect([$a['address_1'] ?? null, $a['address_2'] ?? null, $a['barangay'] ?? null, $a['city'] ?? null, $a['state'] ?? null, $a['postcode'] ?? null])
        ->map(fn ($p) => trim((string) $p))->filter()->implode(', ');
@endphp

@section('content')
<section class="x-panel addr-page">
    <nav class="x-tabs" aria-label="Addresses">
        <a class="x-tab {{ $tab === 'pickup' ? 'is-on' : '' }}" href="{{ route('addresses.index', ['tab' => 'pickup']) }}" @if($tab === 'pickup') aria-current="page" @endif>Pickup</a>
        <a class="x-tab {{ $tab === 'customers' ? 'is-on' : '' }}" href="{{ route('addresses.index', ['tab' => 'customers']) }}" @if($tab === 'customers') aria-current="page" @endif>Customers</a>
    </nav>

    @if($tab === 'pickup')
        @if($pickups->isEmpty())
            <x-ui.empty title="No pickup addresses">
                <x-slot:action><x-ui.button variant="primary" :href="route('addresses.pickup.create')">Add address</x-ui.button></x-slot:action>
            </x-ui.empty>
        @else
            <table class="addr-table">
                <thead>
                    <tr><th scope="col">Name</th><th scope="col">Contact</th><th scope="col">Address</th><th scope="col"><span class="x-sr">Actions</span></th></tr>
                </thead>
                <tbody>
                    @foreach($pickups as $p)
                        @php $a = $p->toPickup(); @endphp
                        <tr>
                            <td class="addr-name">
                                {{ $p->label }}
                                @if($p->is_default)<x-ui.badge tone="good">Default</x-ui.badge>@endif
                            </td>
                            <td>
                                <div>{{ $a['contact_name'] }}</div>
                                <div class="addr-sub">{{ $a['contact_phone'] }}</div>
                            </td>
                            <td>{{ $line($a) }}</td>
                            <td class="addr-act">
                                @unless($p->is_default)
                                    <form method="POST" action="{{ route('addresses.pickup.default', $p) }}">
                                        @csrf
                                        <x-ui.button size="sm" type="submit" :aria-label="'Make ' . $p->label . ' the default'">Make default</x-ui.button>
                                    </form>
                                @endunless
                                <x-ui.button size="sm" :href="route('addresses.pickup.edit', $p)" :aria-label="'Edit ' . $p->label">Edit</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @else
        <form method="GET" action="{{ route('addresses.index') }}" class="addr-search" role="search">
            <input type="hidden" name="tab" value="customers">
            <x-ui.input type="search" name="q" id="f-q" value="{{ $q }}" maxlength="100" placeholder="Name or phone" aria-label="Search customers" autocomplete="off" />
            <x-ui.button type="submit">Search</x-ui.button>
        </form>

        @if($customers->isEmpty())
            <x-ui.empty title="No customers" />
        @else
            <table class="addr-table">
                <thead>
                    <tr><th scope="col">Name</th><th scope="col">Phone</th><th scope="col">Address</th><th scope="col">Shipments</th><th scope="col">Last shipped</th><th scope="col"><span class="x-sr">Actions</span></th></tr>
                </thead>
                <tbody>
                    @foreach($customers as $c)
                        <tr data-customer-row>
                            <td class="addr-name">{{ $c->name }}</td>
                            <td class="x-mono">{{ $c->phone }}</td>
                            <td>{{ $line((array) $c->address) }}</td>
                            <td class="x-num" data-label="Shipments">{{ number_format($c->shipments_count) }}</td>
                            <td data-label="Last shipped">{{ $c->last_shipped_at?->format('j M Y') ?? 'Never' }}</td>
                            <td class="addr-act"><x-ui.button size="sm" :href="route('addresses.customers.edit', $c)" :aria-label="'Edit ' . $c->name">Edit</x-ui.button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <x-ui.pager :paginator="$customers" />
        @endif
    @endif
</section>
@endsection
