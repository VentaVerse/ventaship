<?php

namespace App\Fulfillment;

use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;

class ShipmentList
{
    /** @param array<string, mixed> $filters tab, chip, account, source */
    public static function query(array $filters): Builder
    {
        $q = self::scoped($filters);

        $tab = self::tab($filters);
        if ($tab === 'unpaid') {
            $q->where('payment_state', 'cod_unpaid');
        } elseif ($tab !== 'all') {
            $phases = Tabs::PHASES[$tab];
            $chip = $filters['chip'] ?? null;
            if ($tab === 'to_ship' && is_string($chip) && isset(Tabs::CHIPS[$chip])) {
                $phases = Tabs::CHIPS[$chip];
            }
            $q->whereIn('phase', $phases);
        }

        return $q->with(['clientApp', 'courierAccount'])->orderByDesc('id');
    }

    /**
     * Per tab and per chip. Courier and source filters apply; tab and chip do not.
     *
     * @param array<string, mixed> $filters
     * @return array<string, int>
     */
    public static function counts(array $filters): array
    {
        $base = self::scoped($filters);
        $byPhase = (clone $base)->reorder()
            ->selectRaw('phase, COUNT(*) as n')->groupBy('phase')
            ->pluck('n', 'phase')->map(fn ($n) => (int) $n)->all();
        $unpaid = (clone $base)->where('payment_state', 'cod_unpaid')->count();

        $sum = fn (array $phases): int => array_sum(array_map(fn ($p) => $byPhase[$p] ?? 0, $phases));

        $out = [];
        foreach (Tabs::PHASES as $tab => $phases) {
            $out[$tab] = $tab === 'unpaid' ? $unpaid : ($tab === 'all' ? array_sum($byPhase) : $sum($phases));
        }
        foreach (Tabs::CHIPS as $chip => $phases) {
            $out[$chip] = $sum($phases);
        }

        return $out;
    }

    public static function sourceLabel(Shipment $s): string
    {
        if ($s->source === 'csv') {
            return 'CSV import';
        }

        return $s->clientApp?->name ?? 'Manual';
    }

    /** @param array<string, mixed> $filters */
    private static function tab(array $filters): string
    {
        $tab = $filters['tab'] ?? null;

        return is_string($tab) && array_key_exists($tab, Tabs::PHASES) ? $tab : 'all';
    }

    /** Courier account and source filters; unknown values are ignored. */
    private static function scoped(array $filters): Builder
    {
        $q = Shipment::query();

        $account = $filters['account'] ?? null;
        if (is_string($account) && ($id = CourierAccount::query()->where('public_id', $account)->value('id')) !== null) {
            $q->where('courier_account_id', $id);
        }

        $source = $filters['source'] ?? null;
        if ($source === 'manual' || $source === 'csv') {
            $q->where('source', $source);
        } elseif (is_string($source) && str_starts_with($source, 'api:')
            && ($id = ClientApp::query()->where('public_id', substr($source, 4))->value('id')) !== null) {
            $q->where('source', 'api')->where('client_app_id', $id);
        }

        return $q;
    }
}
