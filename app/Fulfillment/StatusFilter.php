<?php

namespace App\Fulfillment;

use App\Models\Shipment;
use App\Shipments\PhaseResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * The status a row shows and filters by: the courier's own status once it has reported one ("quadx:out_for_delivery"),
 * otherwise where Ventaship itself is ("ventaship:draft"). Queued and booking read the same, so they share a key.
 * A shipment cancelled in Ventaship reads "ventaship:cancelled" whatever the courier last reported, unless that status
 * itself means cancelled (phase `cancelled`), in which case the courier's own key stays.
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

    /** Request attribute holding the keys parse() has already checked. */
    private const PARSED = 'status_filter.parsed';

    public static function key(?string $courier, ?string $courierStatus, ?string $state): string
    {
        if ($courier !== null && $courier !== '' && $courierStatus !== null && $courierStatus !== '') {
            if ($state !== 'cancelled' || self::courierCancelled($courier, $courierStatus)) {
                return $courier.':'.$courierStatus;
            }
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

    /**
     * Splits a status key that names a real status (a known Ventaship state, or one some shipment carries); else null.
     * Remembered for the current request, so a page that parses the same key several times checks it once.
     */
    public static function parse(mixed $status): ?array
    {
        if (! is_string($status) || ! str_contains($status, ':')) {
            return null;
        }
        [$courier, $value] = explode(':', $status, 2);
        if ($courier === '' || $value === '') {
            return null;
        }
        if ($courier === self::VENTASHIP) {
            return isset(self::LABELS[$value]) ? [$courier, $value] : null;
        }

        $seen = request()->attributes->get(self::PARSED, []);
        if (! array_key_exists($status, $seen)) {
            $seen[$status] = Shipment::query()->where('courier', $courier)->where('courier_status', $value)->exists() ? [$courier, $value] : null;
            request()->attributes->set(self::PARSED, $seen);
        }

        return $seen[$status];
    }

    /** Narrows to one status key; a key no shipment carries (or a malformed one) applies no filter. */
    public static function apply(Builder $q, mixed $status): Builder
    {
        $parsed = self::parse($status);
        if ($parsed === null) {
            return $q;
        }
        [$courier, $value] = $parsed;

        if ($courier === self::VENTASHIP && $value === 'cancelled') {
            // Every cancelled row except those whose courier status itself means cancelled (they keep the courier key).
            $q->where('state', 'cancelled');
            $courierKeyed = self::courierCancelledPairs();

            return $courierKeyed === [] ? $q : $q->where(fn (Builder $w) => $w
                ->whereNull('courier_status')->orWhere('courier_status', '')->orWhereNull('courier')->orWhere('courier', '')
                ->orWhereNot(function (Builder $pairs) use ($courierKeyed) {
                    foreach ($courierKeyed as [$c, $st]) {
                        $pairs->orWhere(fn (Builder $p) => $p->where('courier', $c)->where('courier_status', $st));
                    }
                }));
        }

        if ($courier === self::VENTASHIP) {
            $states = $value === 'booking' ? ['queued', 'booking'] : [$value];

            return $q->where(fn (Builder $w) => $w->whereNull('courier_status')->orWhere('courier_status', '')
                ->orWhereNull('courier')->orWhere('courier', ''))
                ->whereIn('state', $states);
        }

        $q->where('courier', $courier)->where('courier_status', $value);

        // A Ventaship-cancelled row with this status reads ventaship:cancelled instead.
        return self::courierCancelled($courier, $value)
            ? $q
            : $q->where(fn (Builder $w) => $w->whereNull('state')->orWhere('state', '<>', 'cancelled'));
    }

    /** Whether the courier status itself means cancelled, so a cancelled row keeps the courier key. */
    private static function courierCancelled(string $courier, string $courierStatus): bool
    {
        return app(PhaseResolver::class)->phase($courier, $courierStatus) === 'cancelled';
    }

    /** @return list<array{0: string, 1: string}> the courier statuses carried by cancelled rows that mean cancelled themselves */
    private static function courierCancelledPairs(): array
    {
        return Shipment::query()->where('state', 'cancelled')
            ->whereNotNull('courier')->where('courier', '<>', '')
            ->whereNotNull('courier_status')->where('courier_status', '<>', '')
            ->distinct()->get(['courier', 'courier_status'])
            ->filter(fn (Shipment $s) => self::courierCancelled((string) $s->courier, (string) $s->courier_status))
            ->map(fn (Shipment $s) => [(string) $s->courier, (string) $s->courier_status])
            ->values()->all();
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
