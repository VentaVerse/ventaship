<?php

namespace App\Fulfillment;

use App\Models\ClientApp;
use App\Models\OutgoingEvent;
use App\Models\Shipment;
use App\Models\ShipmentStatus;
use App\Shipments\PhaseResolver;
use App\Shipments\ShipmentStatuses;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The home page's figures, all from what Ventaship already records: bookings by booked_at, deliveries by the
 * delivered status and its last courier event, statuses by display phase, and the same Needs action rule as the
 * Shipments list. Days are the app's zone (Asia/Manila).
 */
final class Dashboard
{
    public const DAYS = 30;

    /** Chart series in a fixed order: a courier keeps its colour whatever else is shown. */
    public const SERIES = ['quadx', 'spx', 'lbc'];

    private CarbonImmutable $today;

    public function __construct(?CarbonImmutable $now = null)
    {
        $this->today = ($now ?? CarbonImmutable::now())->startOfDay();
    }

    /** @return array{booked: int, booked_yesterday: int, in_transit: int, to_handover: int, delivered: int, delivered_month: int, needs: int} */
    public function kpis(): array
    {
        $phase = DisplayPhase::sql();
        $open = 'NOT '.Views::finishedSql();
        $booked = fn (CarbonImmutable $from, CarbonImmutable $to) => Shipment::query()->where('booked_at', '>=', $from)->where('booked_at', '<', $to)->count();
        $delivered = fn (CarbonImmutable $from) => Shipment::query()->where('phase', 'delivered')->where('last_event_at', '>=', $from)->where('last_event_at', '<', $this->today->addDay())->count();
        $active = fn (string $key) => Shipment::query()->whereRaw($open)->whereRaw("{$phase} = ?", [$key])->count();

        return [
            'booked' => $booked($this->today, $this->today->addDay()),
            'booked_yesterday' => $booked($this->today->subDay(), $this->today),
            'in_transit' => $active('in_transit'),
            'to_handover' => $active('to_handover'),
            'delivered' => $delivered($this->today),
            'delivered_month' => $delivered($this->today->startOfMonth()),
            'needs' => Shipment::query()->whereRaw(Views::needsSql())->count(),
        ];
    }

    /**
     * Bookings per day for the last DAYS days, per courier that booked any.
     *
     * @return array{days: list<array{date: string, label: string, counts: array<string, int>}>, couriers: list<string>, totals: array<string, int>, max: int}
     */
    public function trend(): array
    {
        $from = $this->today->subDays(self::DAYS - 1);
        $rows = Shipment::query()
            ->where('booked_at', '>=', $from)->where('booked_at', '<', $this->today->addDay())
            ->whereNotNull('courier')
            ->selectRaw('DATE(booked_at) as d, courier, COUNT(*) as n')
            ->groupBy('d', 'courier')->get();

        $couriers = array_values(array_filter(self::SERIES, fn (string $c) => $rows->contains('courier', $c)));
        $byDay = [];
        foreach ($rows as $r) {
            if (in_array($r->courier, $couriers, true)) {
                $byDay[substr((string) $r->d, 0, 10)][$r->courier] = (int) $r->n;
            }
        }

        $days = [];
        $totals = array_fill_keys($couriers, 0);
        $max = 0;
        for ($i = 0; $i < self::DAYS; $i++) {
            $day = $from->addDays($i);
            $counts = [];
            foreach ($couriers as $c) {
                $counts[$c] = $byDay[$day->toDateString()][$c] ?? 0;
                $totals[$c] += $counts[$c];
            }
            $max = max($max, array_sum($counts));
            $days[] = ['date' => $day->toDateString(), 'label' => $day->format('j M'), 'counts' => $counts];
        }

        return ['days' => $days, 'couriers' => $couriers, 'totals' => $totals, 'max' => max(4, (int) (ceil($max / 4) * 4))];
    }

    /** @return list<array{name: string, count: int}> every enabled status in its order, then Unmapped when any */
    public function byStatus(): array
    {
        $counts = Shipment::query()->selectRaw(DisplayPhase::sql().' as p, COUNT(*) as n')->groupBy('p')->pluck('n', 'p');

        $rows = ShipmentStatus::query()->where('enabled', true)->orderBy('sort')->orderBy('id')->get()
            ->map(fn (ShipmentStatus $s) => ['name' => $s->name, 'count' => (int) ($counts[$s->key] ?? 0)])->all();
        if (($unmapped = (int) ($counts[PhaseResolver::UNMAPPED] ?? 0)) > 0) {
            $rows[] = ['name' => 'Unmapped', 'count' => $unmapped];
        }

        return $rows;
    }

    /** @return Collection<int, Shipment> */
    public function recent(int $limit = 5): Collection
    {
        return Shipment::query()->latest('updated_at')->latest('id')->limit($limit)->get();
    }

    /** @return list<array{courier: string, name: string, account: mixed, active: int, cod_unpaid: string}> */
    public function couriers(): array
    {
        return array_values(array_filter(CourierCards::all(), fn (array $c) => $c['account'] !== null));
    }

    /**
     * What needs the owner's hand: shipments by the list's own rule, courier statuses left unmapped on a courier in
     * use, and apps whose webhook is failing.
     *
     * @return list<array{what: string, why: string, action: string, href: string}>
     */
    public function needs(ShipmentStatuses $statuses): array
    {
        $items = [];

        $n = Shipment::query()->whereRaw(Views::needsSql())->count();
        if ($n > 0) {
            $items[] = ['what' => $n.' '.($n === 1 ? 'shipment' : 'shipments'), 'why' => 'Failed, unconfirmed or draft', 'action' => 'Open', 'href' => route('fulfillment', ['view' => 'needs'])];
        }

        $inUse = array_column($this->couriers(), 'courier');
        foreach (ShipmentStatuses::MAPPABLE as $courier) {
            if (! in_array($courier, $inUse, true)) {
                continue;
            }
            $unmapped = array_values(array_filter($statuses->mapping($courier), fn (array $row) => $row['current'] === null));
            $href = route('accounts.mapping', $courier);
            if (count($unmapped) > 3) {
                // Many at once (a busy status switched off): one line for the courier, not a wall of rows.
                $items[] = ['what' => Couriers::name($courier).' · '.count($unmapped).' statuses', 'why' => 'Unmapped', 'action' => 'Map', 'href' => $href];
                continue;
            }
            foreach ($unmapped as $row) {
                $code = $courier === 'spx' ? $row['code'].' ' : '';
                $items[] = ['what' => Couriers::name($courier).' '.$code.$row['label'], 'why' => 'Unmapped', 'action' => 'Map', 'href' => $href];
            }
        }

        foreach (ClientApp::query()->whereNotNull('webhook_url')->where('status', 'active')->orderBy('name')->get() as $app) {
            $last = OutgoingEvent::query()->where('client_app_id', $app->id)->where('status', '!=', 'skipped')->latest('id')->first();
            if ($last !== null && ($last->status === 'failed' || ($last->status === 'pending' && $last->last_error !== null))) {
                $items[] = ['what' => $app->name.' webhook', 'why' => (string) ($last->last_error ?: 'Failing'), 'action' => 'Open', 'href' => route('developers.index', ['tab' => 'keys'])];
            }
        }

        return $items;
    }
}
