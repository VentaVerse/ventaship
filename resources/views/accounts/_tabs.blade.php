@if(in_array($courier, \App\Shipments\ShipmentStatuses::MAPPABLE, true))
    <nav class="x-tabs" aria-label="{{ \App\Fulfillment\Couriers::name($courier) }}">
        <a class="x-tab {{ $tab === 'settings' ? 'is-on' : '' }}" href="{{ route('accounts.show', $courier) }}" @if($tab === 'settings') aria-current="page" @endif>Settings</a>
        <a class="x-tab {{ $tab === 'mapping' ? 'is-on' : '' }}" href="{{ route('accounts.mapping', $courier) }}" @if($tab === 'mapping') aria-current="page" @endif>Status mapping</a>
    </nav>
@endif
