@php
    $account = $card['account'];
    $names = ['quadx' => 'QuadX', 'spx' => 'SPX', 'lbc' => 'LBC'];
    $codes = ['quadx' => 'QX', 'spx' => 'SPX', 'lbc' => 'LBC'];
    $mode = $account ? ($account->mode === 'production' ? ['Live', 'good'] : [strtolower($account->mode) === 'uat' ? 'UAT' : ucfirst($account->mode), 'cool']) : null;
    $check = $account ? ($account->last_check_ok === null ? ['Not checked', 'mute'] : ($account->last_check_ok ? ['Connected', 'good'] : ['Check failed', 'bad'])) : null;
@endphp

@if($account)
    <article class="x-card fl-card fl-card--{{ $card['courier'] }}">
        <header class="fl-card__head">
            <span class="fl-card__logo">{{ $codes[$card['courier']] ?? strtoupper($card['courier']) }}</span>
            <div>
                <h2 class="fl-card__name">{{ $names[$card['courier']] ?? $card['courier'] }}</h2>
                <div class="fl-card__sub">{{ $card['name'] }}</div>
            </div>
            <div class="fl-card__badges">
                <x-ui.badge :tone="$mode[1]">{{ $mode[0] }}</x-ui.badge>
                <x-ui.badge :tone="$check[1]">{{ $check[0] }}</x-ui.badge>
            </div>
        </header>
        <div class="fl-card__stats">
            <div class="fl-big x-num">{{ $card['to_ship'] }}<small>To ship</small></div>
            <div class="fl-big x-num {{ $card['delivered'] === 0 ? 'is-clear' : '' }}">{{ $card['delivered'] }}<small>Delivered</small></div>
        </div>
        <dl class="fl-card__mid">
            <div><dt>To handover</dt><dd class="x-num">{{ $card['to_handover'] }}</dd></div>
            <div><dt>Shipping</dt><dd class="x-num">{{ $card['shipping'] }}</dd></div>
            <div><dt>Failed</dt><dd class="x-num">{{ $card['failed'] }}</dd></div>
        </dl>
        <div class="fl-card__cod"><span>COD unpaid</span><b class="x-num">{{ \App\Support\Money::peso($card['cod_unpaid']) }}</b></div>
    </article>
@else
    <article class="x-card fl-card fl-card--off">
        <header class="fl-card__head">
            <span class="fl-card__logo">{{ $codes[$card['courier']] ?? strtoupper($card['courier']) }}</span>
            <div>
                <h2 class="fl-card__name">{{ $card['name'] }}</h2>
                <div class="fl-card__sub">No account</div>
            </div>
        </header>
        @if(Route::has('accounts.create'))
            <div class="fl-card__off">
                <x-ui.button size="sm" :href="route('accounts.create', ['courier' => $card['courier']])">Connect {{ $card['name'] }}</x-ui.button>
            </div>
        @endif
    </article>
@endif
