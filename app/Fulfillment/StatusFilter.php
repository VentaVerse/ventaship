<?php

namespace App\Fulfillment;

use App\Models\Shipment;
use App\Shipments\PhaseResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * The status a row shows and filters by: the courier's own status once it has reported one ("quadx:out_for_delivery"),
 * otherwise where Ventaship itself is ("ventaship:draft"). Queued and booking read the same, so they share a key.
 */
final class StatusFilter
{
    public const VENTASHIP = 'ventaship';

    public const LABELS = [
        'draft' => 'Not booked',
        'booking' => 'Booking',
        'failed' => 'Booking failed',
        'unknown' => 'Booking result unknown',
        'booked' => 'Booked',
        'cancelled' => 'Cancelled',
    ];

    private const COURIER_ORDER = ['quadx', 'spx', 'lbc'];

    public static function key(?string $courier, ?string $courierStatus, ?string $state): string
    {
        if ($courier !== null && $courier !== '' && $courierStatus !== null && $courierStatus !== '') {
            return $courier.':'.$courierStatus;
        }

        return self::VENTASHIP.':'.self::pseudo($state);
    }

    public static function keyFor(Shipment $s): string
    {
        return self::key($s->courier, $s->courier_status, $s->state);
    }

    public static function label(string $courier, string $courierStatus): string
    {
        if ($courier === self::VENTASHIP) {
            return self::LABELS[$courierStatus] ?? ucfirst($courierStatus);
        }

        return app(PhaseResolver::class)->label($courier, $courierStatus);
    }

    public static function labelFor(Shipment $s): string
    {
        $key = self::keyFor($s);
        [$courier, $status] = explode(':', $key, 2);

        return self::label($courier, $status);
    }

    /**
     * Built from the shipments matching every filter except status.
     *
     * @param array<string, mixed> $filters
     * @return list<array{key:string,label:string,courier:?string,count:int}>
     */
    public static function options(array $filters): array
    {
        $rows = ShipmentList::scoped($filters, view: true, status: false)->reorder()
            ->selectRaw('courier, courier_status, state, COUNT(*) as n')
            ->groupBy('courier', 'courier_status', 'state')->get();

        $merged = [];
        foreach ($rows as $r) {
            $key = self::key($r->courier, $r->courier_status, $r->state);
            $merged[$key] = ($merged[$key] ?? 0) + (int) $r->n;
        }

        $out = [];
        foreach ($merged as $key => $count) {
            [$courier, $status] = explode(':', $key, 2);
            $out[] = [
                'key' => $key,
                'label' => self::label($courier, $status),
                'courier' => $courier === self::VENTASHIP ? null : $courier,
                'count' => $count,
            ];
        }

        $rank = fn (?string $c): int => $c === null ? 1000 : ((($i = array_search($c, self::COURIER_ORDER, true)) === false) ? 100 : $i);
        usort($out, fn ($a, $b) => [$rank($a['courier']), $b['count'], $a['label']] <=> [$rank($b['courier']), $a['count'], $b['label']]);

        return $out;
    }

    /** Narrows to one status key; a key no shipment carries (or a malformed one) applies no filter. */
    public static function apply(Builder $q, mixed $status): Builder
    {
        if (! is_string($status) || ! str_contains($status, ':')) {
            return $q;
        }
        [$courier, $value] = explode(':', $status, 2);
        if ($courier === '' || $value === '') {
            return $q;
        }

        if ($courier === self::VENTASHIP) {
            if (! isset(self::LABELS[$value])) {
                return $q;
            }
            $states = $value === 'booking' ? ['queued', 'booking'] : [$value];

            return $q->where(fn (Builder $w) => $w->whereNull('courier_status')->orWhere('courier_status', '')
                ->orWhereNull('courier')->orWhere('courier', ''))
                ->whereIn('state', $states);
        }

        if (! Shipment::query()->where('courier', $courier)->where('courier_status', $value)->exists()) {
            return $q;
        }

        return $q->where('courier', $courier)->where('courier_status', $value);
    }

    private static function pseudo(?string $state): string
    {
        return match ($state) {
            'queued', 'booking' => 'booking',
            null, '' => 'draft',
            default => $state,
        };
    }
}
