@php
    use App\Fulfillment\Couriers;
    use App\Support\Money;

    $account = $card['account'];
    $courierName = Couriers::name($card['courier']);
    $label = $account ? trim((string) $account->label) : '';
    $modeWord = $account ? ($account->mode === 'production' ? 'Production' : 'Sandbox') : null;
    $check = $account ? ($account->last_check_ok === null ? ['Not checked', 'mute'] : ($account->last_check_ok ? ['Connected', 'good'] : ['Check failed', 'bad'])) : null;
    $codUnpaid = $card['cod_unpaid'] !== '0.00';
@endphp

@if($account)
    <article class="x-card fl-card fl-card--{{ $card['courier'] }}">
        <x-courier-tile :courier="$card['courier']" />
        <div class="fl-card__title">
            <h2 class="fl-card__name">{{ $courierName }}</h2>
            <div class="fl-card__sub">
                @if($label !== '' && strcasecmp($label, $courierName) !== 0)<span class="fl-card__label">{{ $label }}</span><span aria-hidden="true">·</span>@endif
                <span>{{ $modeWord }}</span><span aria-hidden="true">·</span>
                <x-ui.badge :tone="$check[1]">{{ $check[0] }}</x-ui.badge>
            </div>
        </div>
        <div class="fl-stat"><strong class="x-num">{{ $card['active'] }}</strong><span>Active</span></div>
        <div class="fl-stat"><strong class="x-num">{{ $card['delivered'] }}</strong><span>Delivered</span></div>
        @if($codUnpaid)
            <div class="fl-stat fl-stat--cod"><strong class="x-num">{{ Money::peso($card['cod_unpaid']) }}</strong><span>COD unpaid</span></div>
        @endif
    </article>
@else
    <article class="x-card fl-card fl-card--off">
        <x-courier-tile :courier="$card['courier']" />
        <div class="fl-card__title">
            <h2 class="fl-card__name">{{ $card['name'] }}</h2>
            <div class="fl-card__sub">{{ $card['inactive'] ? 'Inactive' : 'No account' }}</div>
        </div>
        @if($card['inactive'])
            <x-ui.button size="sm" class="fl-card__go" :href="route('accounts.show', $card['courier'])">Open</x-ui.button>
        @else
            <x-ui.button size="sm" class="fl-card__go" :href="route('accounts.show', $card['courier'])">Connect {{ $card['name'] }}</x-ui.button>
        @endif
    </article>
@endif
