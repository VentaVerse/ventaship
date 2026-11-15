@extends('layouts.app')

@section('title', 'Dashboard')

@section('actions')
    <x-ui.button variant="primary" :href="route('shipments.create')">Add shipment</x-ui.button>
@endsection

@php
    use App\Fulfillment\Couriers;
    use App\Fulfillment\ShipmentBadges;
    use App\Fulfillment\StatusFilter;
    use App\Support\Money;

    $diff = $kpis['booked'] - $kpis['booked_yesterday'];
    $vsYesterday = $diff === 0 ? 'Same as yesterday' : ($diff > 0 ? '+' : '').$diff.' vs yesterday';
    $cards = [
        ['package', 'Booked today', $kpis['booked'], $vsYesterday, 'cool', route('fulfillment')],
        ['truck', 'In transit', $kpis['in_transit'], $kpis['to_handover'].' to hand over', 'cool', route('fulfillment', ['view' => 'active'])],
        ['check', 'Delivered today', $kpis['delivered'], $kpis['delivered_month'].' this month', 'good', route('fulfillment', ['view' => 'finished'])],
        ['alert-triangle', 'Needs action', $kpis['needs'], 'Open list', 'attn', route('fulfillment', ['view' => 'needs'])],
    ];

    // Chart geometry: one stacked bar per day, a 2px surface gap between a day's courier segments.
    $W = 640; $H = 220; $pl = 28; $pr = 8; $pt = 10; $pb = 24;
    $days = $trend['days'];
    $cw = ($W - $pl - $pr) / count($days);
    $bw = $cw - 6;
    $y = fn (float $v) => $pt + ($H - $pt - $pb) * (1 - $v / $trend['max']);
    $ticks = range(0, $trend['max'], $trend['max'] / 4);
    $statusMax = max(1, max(array_column($statuses, 'count') ?: [0]));
@endphp

@section('content')
<div class="db" data-dashboard>
    <div class="db-kpis">
        @foreach($cards as [$icon, $label, $value, $sub, $tone, $href])
            <a class="db-kpi" href="{{ $href }}">
                <span class="db-kpi__icon db-kpi__icon--{{ $tone }}"><x-ui.icon :name="$icon" size="20" /></span>
                <span class="db-kpi__body">
                    <span class="db-kpi__label">{{ $label }}</span>
                    <strong class="db-kpi__value{{ $tone === 'attn' && $value > 0 ? ' is-attn' : '' }}">{{ number_format($value) }}</strong>
                    <span class="db-kpi__sub">{{ $sub }}</span>
                </span>
            </a>
        @endforeach
    </div>

    <div class="db-row">
        <section class="x-panel db-card" aria-labelledby="db-h-trend">
            <header class="db-card__head">
                <h2 class="db-card__h" id="db-h-trend">Shipments, last {{ \App\Fulfillment\Dashboard::DAYS }} days</h2>
                @if($trend['couriers'] !== [])
                    <span class="db-legend">
                        @foreach($trend['couriers'] as $i => $c)
                            <span><i class="db-key db-key--{{ array_search($c, \App\Fulfillment\Dashboard::SERIES, true) + 1 }}"></i>{{ Couriers::name($c) }} {{ number_format($trend['totals'][$c]) }}</span>
                        @endforeach
                    </span>
                @endif
            </header>
            <div class="db-chart-wrap" x-data="{ tip: '', x: 0 }">
                <svg viewBox="0 0 {{ $W }} {{ $H }}" class="db-chart" role="img" aria-label="Shipments booked per day for the last {{ \App\Fulfillment\Dashboard::DAYS }} days, by courier">
                    @foreach($ticks as $t)
                        <line x1="{{ $pl }}" x2="{{ $W - $pr }}" y1="{{ $y($t) }}" y2="{{ $y($t) }}" class="db-grid" />
                        <text x="{{ $pl - 6 }}" y="{{ $y($t) + 4 }}" class="db-axis" text-anchor="end">{{ (int) $t }}</text>
                    @endforeach
                    @foreach($days as $i => $d)
                        @php
                            $x = $pl + $i * $cw + 3;
                            $tip = $d['label'].': '.collect($d['counts'])->map(fn ($n, $c) => Couriers::name($c).' '.$n)->implode(' · ');
                            $base = 0;
                        @endphp
                        <g class="db-bar" x-on:mouseenter="tip = @js($tip); x = {{ round(($x + $bw / 2) / $W * 100, 2) }}" x-on:mouseleave="tip = ''">
                            <rect class="db-hit" x="{{ $x - 3 }}" y="{{ $pt }}" width="{{ $cw }}" height="{{ $H - $pt - $pb }}" />
                            @foreach($d['counts'] as $c => $n)
                                @if($n > 0)
                                    @php $top = $y($base + $n); $bottom = $y($base) - ($base > 0 ? 2 : 0); $base += $n; @endphp
                                    <rect class="db-s{{ array_search($c, \App\Fulfillment\Dashboard::SERIES, true) + 1 }}" x="{{ $x }}" y="{{ $top }}" width="{{ $bw }}" height="{{ max(0, $bottom - $top) }}" rx="{{ $base === array_sum($d['counts']) ? 2 : 0 }}" />
                                @endif
                            @endforeach
                        </g>
                        @if($i % 7 === 2 || $i === count($days) - 1)
                            <text x="{{ $x + $bw / 2 }}" y="{{ $H - 6 }}" class="db-axis" text-anchor="middle">{{ $d['label'] }}</text>
                        @endif
                    @endforeach
                </svg>
                <div class="db-tip" role="status" x-show="tip" x-text="tip" x-effect="$el.style.left = Math.min(Math.max(x, 12), 88) + '%'" x-cloak></div>
            </div>
        </section>

        <section class="x-panel db-card" aria-labelledby="db-h-status">
            <header class="db-card__head"><h2 class="db-card__h" id="db-h-status">By status</h2></header>
            <ul class="db-meters">
                @foreach($statuses as $s)
                    <li class="db-meter">
                        <span class="db-meter__name">{{ $s['name'] }}</span>
                        <svg class="db-meter__track" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true"><rect class="db-meter__bg" width="100" height="8" rx="4" /><rect class="db-meter__fill" width="{{ $s['count'] > 0 ? max(2, round($s['count'] / $statusMax * 100, 1)) : 0 }}" height="8" rx="4" /></svg>
                        <span class="db-meter__val">{{ number_format($s['count']) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <div class="db-row">
        <section class="x-panel db-card" aria-labelledby="db-h-recent">
            <header class="db-card__head">
                <h2 class="db-card__h" id="db-h-recent">Recent shipments</h2>
                <a class="db-link" href="{{ route('fulfillment') }}">View all</a>
            </header>
            @if($recent->isEmpty())
                <x-ui.empty title="No shipments yet" />
            @else
                <div class="db-scroll">
                    <table class="db-table">
                        <thead><tr><th scope="col">Recipient</th><th scope="col">Courier</th><th scope="col">Status</th><th scope="col" class="db-right">Updated</th></tr></thead>
                        <tbody>
                            @foreach($recent as $s)
                                @php
                                    $r = is_array($s->recipient) ? $s->recipient : [];
                                    $name = trim(((string) ($r['first_name'] ?? '')).' '.((string) ($r['last_name'] ?? ''))) ?: 'Removed';
                                    $at = $s->updated_at;
                                @endphp
                                <tr>
                                    <td class="db-strong"><a class="db-row-link" href="{{ route('shipments.show', $s->public_id) }}">{{ $name }}</a></td>
                                    <td>@if($s->courier)<span class="db-cour"><x-courier-tile :courier="$s->courier" :logo="true" />{{ Couriers::name($s->courier) }}</span>@endif</td>
                                    <td><x-ui.badge :tone="ShipmentBadges::statusTone($s)">{{ StatusFilter::labelFor($s) }}</x-ui.badge></td>
                                    <td class="db-muted db-right">{{ $at?->isToday() ? $at->format('g:i A') : $at?->format('j M') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <div class="db-stack">
            <section class="x-panel db-card" aria-labelledby="db-h-couriers">
                <header class="db-card__head"><h2 class="db-card__h" id="db-h-couriers">Couriers</h2></header>
                @if($couriers === [])
                    <x-ui.empty title="No couriers set up">
                        <x-slot:action><x-ui.button variant="primary" :href="route('accounts.index')">Couriers</x-ui.button></x-slot:action>
                    </x-ui.empty>
                @else
                    <ul class="db-list">
                        @foreach($couriers as $c)
                            @php
                                $a = $c['account'];
                                $check = $a->last_check_ok === null ? ['Not checked', 'mute'] : ($a->last_check_ok ? ['Connected', 'good'] : ['Check failed', 'bad']);
                            @endphp
                            <li class="db-c">
                                <a class="db-c__top" href="{{ route('accounts.show', $c['courier']) }}">
                                    <x-courier-tile :courier="$c['courier']" :logo="true" />
                                    <span class="db-c__text">
                                        <span class="db-strong">{{ Couriers::name($c['courier']) }}</span>
                                        <span class="db-muted db-c__sub">{{ $a->mode === 'production' ? 'Production' : 'Sandbox' }} · <x-ui.badge :tone="$check[1]">{{ $check[0] }}</x-ui.badge></span>
                                    </span>
                                </a>
                                <div class="db-c__stats">
                                    <span class="db-c__stat"><strong>{{ number_format($c['active']) }}</strong><span>Active</span></span>
                                    <span class="db-c__stat"><strong>{{ Money::peso($c['cod_unpaid']) }}</strong><span>COD out</span></span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="x-panel db-card" id="db-needs" aria-labelledby="db-h-needs">
                <header class="db-card__head">
                    <h2 class="db-card__h" id="db-h-needs">Needs action</h2>
                    @if($needs !== [])<span class="db-count">{{ count($needs) }}</span>@endif
                </header>
                @if($needs === [])
                    <p class="db-clear"><x-ui.icon name="check" size="16" />All clear</p>
                @else
                    <ul class="db-list">
                        @foreach($needs as $n)
                            <li class="db-need">
                                <span class="db-need__dot" aria-hidden="true"><x-ui.icon name="alert-triangle" size="14" /></span>
                                <span class="db-need__text"><span class="db-strong">{{ $n['what'] }}</span><span class="db-muted">{{ $n['why'] }}</span></span>
                                <x-ui.button size="sm" :href="$n['href']">{{ $n['action'] }}</x-ui.button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</div>
@endsection
