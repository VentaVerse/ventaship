<?php

namespace App\Fulfillment;

use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;

class ShipmentList
{
    /** @param array<string, mixed> $filters view, account, source, status */
    public static function query(array $filters): Builder
    {
        return self::scoped($filters, view: true, status: true)->with(['clientApp', 'courierAccount'])->orderByDesc('id');
    }

    /**
     * Per view. Account, source and status apply; the view does not.
     *
     * @param array<string, mixed> $filters
     * @return array{all:int,active:int,finished:int}
     */
    public static function counts(array $filters): array
    {
        $row = self::scoped($filters, view: false, status: true)->reorder()
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN '.Views::finishedSql().' THEN 1 ELSE 0 END), 0) as done')
            ->first();
        $all = (int) ($row->total ?? 0);
        $finished = (int) ($row->done ?? 0);

        return ['all' => $all, 'active' => $all - $finished, 'finished' => $finished];
    }

    public static function sourceLabel(Shipment $s): string
    {
        if ($s->source === 'csv') {
            return 'CSV import';
        }

        return $s->clientApp?->name ?? 'Manual';
    }

    /** Account and source always; view and status when asked. Unknown values are ignored. */
    public static function scoped(array $filters, bool $view, bool $status): Builder
    {
        $q = Shipment::query();

        if ($view) {
            Views::apply($q, $filters['view'] ?? null);
        }
        if ($status) {
            StatusFilter::apply($q, $filters['status'] ?? null);
        }

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
