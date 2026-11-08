<?php

namespace App\Fulfillment;

use App\Models\CourierAccount;
use App\Models\Shipment;

class CourierCards
{
    private const COURIERS = ['quadx' => 'QuadX', 'spx' => 'SPX', 'lbc' => 'LBC'];

    /** @return array<int, array{courier:string, name:string, account:?CourierAccount, active:int, delivered:int, cod_unpaid:string}> */
    public static function all(): array
    {
        $accounts = CourierAccount::query()->where('status', 'active')->get()
            ->sortBy(fn (CourierAccount $a) => [array_search($a->courier, array_keys(self::COURIERS), true) ?: 0, $a->label])
            ->values();

        $expr = DisplayPhase::sql();
        $finished = Views::finishedSql();
        $byAccount = Shipment::query()
            ->whereIn('courier_account_id', $accounts->pluck('id'))
            ->selectRaw("courier_account_id, SUM(CASE WHEN {$finished} THEN 0 ELSE 1 END) as active, SUM(CASE WHEN {$expr} = 'delivered' THEN 1 ELSE 0 END) as delivered")
            ->groupBy('courier_account_id')->get()->keyBy('courier_account_id');

        $cod = Shipment::query()
            ->whereIn('courier_account_id', $accounts->pluck('id'))
            ->where('payment_state', 'cod_unpaid')->whereRaw("{$expr} <> ?", ['cancelled'])
            ->selectRaw('courier_account_id, SUM(ROUND(cod_amount * 100)) as centavos')->groupBy('courier_account_id')
            ->pluck('centavos', 'courier_account_id');

        $cards = [];
        foreach ($accounts as $a) {
            $row = $byAccount[$a->id] ?? null;
            $cards[] = [
                'courier' => $a->courier,
                'name' => $a->label,
                'account' => $a,
                'active' => (int) ($row->active ?? 0),
                'delivered' => (int) ($row->delivered ?? 0),
                'cod_unpaid' => self::money((int) ($cod[$a->id] ?? 0)),
            ];
        }

        $have = $accounts->pluck('courier')->all();
        foreach (self::COURIERS as $key => $label) {
            if (! in_array($key, $have, true)) {
                $cards[] = ['courier' => $key, 'name' => $label, 'account' => null, 'active' => 0, 'delivered' => 0, 'cod_unpaid' => '0.00'];
            }
        }

        usort($cards, fn ($a, $b) => array_search($a['courier'], array_keys(self::COURIERS), true) <=> array_search($b['courier'], array_keys(self::COURIERS), true)
            ?: ($a['account'] === null) <=> ($b['account'] === null));

        return $cards;
    }

    /** Whole centavos to "X.YY" with integer maths only. */
    private static function money(int $centavos): string
    {
        return intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }
}
