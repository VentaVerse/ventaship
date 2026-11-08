<?php

namespace App\Couriers\TrackingNumbers;

use App\Models\CourierAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Issues tracking numbers from the blocks a courier assigned to one courier account (tracking_series rows,
 * added by ventaship:lbc-series-add).
 *
 * The courier does not check uniqueness for us, so this is the only thing standing between two parcels and one
 * tracking number. Every number moves through three states (see TrackingNumber):
 *
 *   reserve()  takes the account's lowest released number, or the next unused one from its oldest block that has
 *              any left, and marks it reserved. Done under a row lock on the account's blocks, so two concurrent
 *              bookings can never take the same number.
 *   spend()    the courier saved the request, or the outcome is unknown (timeout, network error, unreadable
 *              answer): it may exist on the courier's side, so it is never issued again.
 *   release()  the courier definitely rejected the request and saved nothing: the number goes back and is the next
 *              one issued.
 *
 * A reservation that is never settled (the process died mid-request) stays reserved and is never issued again,
 * which is the safe reading of "we do not know".
 *
 * Numbers never leave the blocks: past the last number of the last block every claim refuses, and a warning is
 * logged once the remaining count falls to the account's low-water mark (at most WARN_REMAINING_FRACTION of its
 * blocks), because a new block is requested from the courier by hand and takes time.
 *
 * Blocks never overlap, across every account of the courier: addSeries() refuses one that would.
 */
class TrackingNumberSeriesService
{
    /** Warn when this share of the blocks, or less, is still unissued (and never later than the low-water mark). */
    public const WARN_REMAINING_FRACTION = 0.2;

    /** The longest number accepted: well inside a signed 64-bit integer. */
    public const MAX_DIGITS = 18;

    /** Transaction attempts when the database reports a lock conflict. */
    private const ATTEMPTS = 5;

    /** @param string|null $connection database connection; null uses the models' default */
    public function __construct(private readonly ?string $connection = null) {}

    /**
     * Record a block the courier assigned to this account.
     *
     * @throws TrackingNumberSeriesException when the range is not a block, or overlaps any block of the courier
     */
    public function addSeries(CourierAccount $account, int $first, int $last): TrackingNumberSeries
    {
        $this->assertRange($first, $last);
        $courier = (string) $account->courier;

        return $this->db()->transaction(function () use ($account, $courier, $first, $last) {
            // Lock the courier's blocks (and, on InnoDB, the index range they sit in) before checking, so two
            // concurrent adds cannot both pass the overlap check. The loser waits, then sees the winner's block.
            $this->series()->where('courier', $courier)->lockForUpdate()->get(['id']);

            $this->assertNoOverlap($courier, $first, $last);

            return $this->series()->create([
                'courier_account_id' => $account->getKey(),
                'courier' => $courier,
                'first_number' => $first,
                'last_number' => $last,
                'last_issued_number' => null,
            ]);
        });
    }

    /**
     * The number reserve() would hand out now, without taking it. For dry runs and the booking form: nothing is
     * written.
     *
     * @throws TrackingNumberSeriesException when the account has no block, or none has a number left
     */
    public function peek(CourierAccount $account): IssuedTrackingNumber
    {
        $blocks = $this->blocks($account)->get();
        $this->assertHasBlocks($blocks);

        $released = $this->numbers()
            ->whereIn('series_id', $blocks->modelKeys())
            ->where('status', TrackingNumber::RELEASED)
            ->min('number');

        $number = $released !== null ? (int) $released : $this->nextUnused($blocks)[1];

        return $this->issued(null, $number, $blocks, excluding: $number);
    }

    /**
     * How much of the account's blocks is left, for the booking form and the account's checks. Reads only. Null
     * when the account has no block at all.
     *
     * `remaining` counts every number that can still be issued, the next one included: the never-issued tails of the
     * blocks plus any released number.
     *
     * @return array{blocks:int, size:int, remaining:int, next:?int, near_end:bool}|null
     */
    public function status(CourierAccount $account): ?array
    {
        $blocks = $this->blocks($account)->get();
        if ($blocks->isEmpty()) {
            return null;
        }

        $rows = $this->rows($blocks);
        $remaining = array_sum(array_column($rows, 'remaining'));
        $released = array_filter(array_column($rows, 'lowest_released'), fn ($n) => $n !== null);
        $next = $released !== [] ? min($released) : $this->nextUnusedOrNull($blocks)[1];

        return [
            'blocks' => $blocks->count(),
            'size' => $this->size($blocks),
            'remaining' => $remaining,
            'next' => $next,
            'near_end' => $remaining <= $this->warnAt($blocks),
        ];
    }

    /**
     * The account's blocks one by one, oldest first, for the account page. A block's `remaining` is its never-issued
     * tail plus its released numbers; its `next` is its lowest released number, else its next never-issued one.
     * Their sum is `status()['remaining']`.
     *
     * @return array<int, array{first:int, last:int, next:?int, remaining:int}>
     */
    public function blockRows(CourierAccount $account): array
    {
        return array_map(
            fn (array $r) => array_diff_key($r, ['lowest_released' => 1]),
            $this->rows($this->blocks($account)->get()),
        );
    }

    /**
     * @param  Collection<int, TrackingNumberSeries>  $blocks
     * @return array<int, array{first:int, last:int, next:?int, remaining:int, lowest_released:?int}>
     */
    private function rows(Collection $blocks): array
    {
        $released = $blocks->isEmpty() ? collect() : $this->numbers()
            ->whereIn('series_id', $blocks->modelKeys())
            ->where('status', TrackingNumber::RELEASED)
            ->selectRaw('series_id, count(*) as n, min(number) as lowest')
            ->groupBy('series_id')
            ->get()
            ->keyBy('series_id');

        return $blocks->map(function (TrackingNumberSeries $s) use ($released) {
            $unissued = $this->unissued(collect([$s]));
            $r = $released->get($s->getKey());
            $lowest = $r !== null ? (int) $r->lowest : null;
            $fresh = $unissued > 0 ? ($s->last_issued_number === null ? $s->first_number : $s->last_issued_number + 1) : null;

            return [
                'first' => $s->first_number,
                'last' => $s->last_number,
                'next' => $lowest ?? $fresh,
                'remaining' => $unissued + (int) ($r->n ?? 0),
                'lowest_released' => $lowest,
            ];
        })->values()->all();
    }

    /**
     * Take the next number for a request about to be sent.
     *
     * @throws TrackingNumberSeriesException when the account has no block, the blocks are used up, or the claim raced
     */
    public function reserve(CourierAccount $account, ?int $shipmentId = null): IssuedTrackingNumber
    {
        $issued = $this->db()->transaction(function () use ($account, $shipmentId) {
            $blocks = $this->blocks($account)->lockForUpdate()->get();
            $this->assertHasBlocks($blocks);

            $released = $this->numbers()
                ->whereIn('series_id', $blocks->modelKeys())
                ->where('status', TrackingNumber::RELEASED)
                ->orderBy('number')
                ->lockForUpdate()
                ->first();

            if ($released !== null) {
                // Conditional on the state read under the lock, so a second writer can never flip the same row to
                // reserved twice.
                $taken = $this->numbers()
                    ->whereKey($released->id)
                    ->where('status', TrackingNumber::RELEASED)
                    ->update([
                        'status' => TrackingNumber::RESERVED,
                        'shipment_id' => $shipmentId,
                        'outcome' => null,
                        'updated_at' => now(),
                    ]);

                if ($taken !== 1) {
                    throw new TrackingNumberSeriesException('A released tracking number was taken by another request. Try again.');
                }

                return $this->issued($released->id, (int) $released->number, $blocks);
            }

            [$series, $number] = $this->nextUnused($blocks);

            // Conditional on the value read under the lock: if anything moved the counter in between, nothing is
            // written and nothing issued.
            $moved = $this->series()
                ->whereKey($series->id)
                ->where(function ($q) use ($series) {
                    $series->last_issued_number === null
                        ? $q->whereNull('last_issued_number')
                        : $q->where('last_issued_number', $series->last_issued_number);
                })
                ->update(['last_issued_number' => $number, 'updated_at' => now()]);

            if ($moved !== 1) {
                throw new TrackingNumberSeriesException('The tracking number series moved during the claim. Try again.');
            }

            $row = $this->numbers()->create([
                'series_id' => $series->id,
                'number' => $number,
                'status' => TrackingNumber::RESERVED,
                'shipment_id' => $shipmentId,
            ]);

            $series->last_issued_number = $number;

            return $this->issued($row->id, $number, $blocks);
        }, self::ATTEMPTS);

        if ($issued->nearEnd) {
            Log::warning('Tracking number series is running out', [
                'courier' => (string) $account->courier,
                'account' => $account->public_id,
                'remaining' => $issued->remaining,
            ]);
        }

        return $issued;
    }

    /** The courier saved the request, or may have: the number is used for good. */
    public function spend(IssuedTrackingNumber $issued, string $outcome): void
    {
        $this->settle($issued, TrackingNumber::SPENT, $outcome);
    }

    /** The courier definitely rejected the request: the number is issued again next. */
    public function release(IssuedTrackingNumber $issued, string $outcome): void
    {
        $this->settle($issued, TrackingNumber::RELEASED, $outcome);
    }

    private function settle(IssuedTrackingNumber $issued, string $status, string $outcome): void
    {
        if ($issued->id === null) {
            throw new TrackingNumberSeriesException('A previewed tracking number was never reserved, so it cannot be settled.');
        }

        $updated = $this->numbers()
            ->whereKey($issued->id)
            ->where('status', TrackingNumber::RESERVED)
            ->update([
                'status' => $status,
                'outcome' => mb_substr($outcome, 0, 255),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new TrackingNumberSeriesException("Tracking number {$issued->number} is not reserved, so it cannot be settled again.");
        }
    }

    /** The account's blocks, oldest first: numbers are issued from the oldest block with any left. */
    private function blocks(CourierAccount $account)
    {
        return $this->series()
            ->where('courier_account_id', $account->getKey())
            ->orderBy('id');
    }

    /** @param  Collection<int, TrackingNumberSeries>  $blocks */
    private function assertHasBlocks(Collection $blocks): void
    {
        if ($blocks->isEmpty()) {
            throw new TrackingNumberSeriesException('This account has no tracking number block. Add the block the courier assigned with ventaship:lbc-series-add.');
        }
    }

    /**
     * The oldest block with a never-issued number, and that number. Refuses past the end of the last block.
     *
     * @param  Collection<int, TrackingNumberSeries>  $blocks
     * @return array{TrackingNumberSeries, int}
     */
    private function nextUnused(Collection $blocks): array
    {
        [$series, $next] = $this->nextUnusedOrNull($blocks);

        if ($series === null) {
            /** @var TrackingNumberSeries $last */
            $last = $blocks->last();

            throw new TrackingNumberSeriesException(
                "The tracking number series {$last->first_number} to {$last->last_number} is used up. Request a new block from the courier."
            );
        }

        return [$series, $next];
    }

    /**
     * @param  Collection<int, TrackingNumberSeries>  $blocks
     * @return array{?TrackingNumberSeries, ?int}
     */
    private function nextUnusedOrNull(Collection $blocks): array
    {
        foreach ($blocks as $series) {
            $next = $series->last_issued_number === null ? $series->first_number : $series->last_issued_number + 1;
            if ($next <= $series->last_number) {
                return [$series, $next];
            }
        }

        return [null, null];
    }

    /**
     * @param  Collection<int, TrackingNumberSeries>  $blocks
     * @param  int|null  $excluding  a number counted as still available that the caller is about to hand out
     */
    private function issued(?int $id, int $number, Collection $blocks, ?int $excluding = null): IssuedTrackingNumber
    {
        // Numbers still available after this one. Released numbers are issued before new ones, so they count.
        $remaining = $this->unissued($blocks) + (int) $this->numbers()
            ->whereIn('series_id', $blocks->modelKeys())
            ->where('status', TrackingNumber::RELEASED)
            ->count();

        if ($excluding !== null) {
            $remaining = max(0, $remaining - 1);
        }

        return new IssuedTrackingNumber(
            id: $id,
            number: $number,
            remaining: $remaining,
            nearEnd: $remaining <= $this->warnAt($blocks),
        );
    }

    /** @param  Collection<int, TrackingNumberSeries>  $blocks */
    private function unissued(Collection $blocks): int
    {
        return (int) $blocks->sum(fn (TrackingNumberSeries $s) => $s->last_issued_number === null
            ? $s->size()
            : max(0, $s->last_number - $s->last_issued_number));
    }

    /** @param  Collection<int, TrackingNumberSeries>  $blocks */
    private function size(Collection $blocks): int
    {
        return (int) $blocks->sum(fn (TrackingNumberSeries $s) => $s->size());
    }

    /**
     * The remaining count at which to warn: the newest block's low-water mark, but never more than
     * WARN_REMAINING_FRACTION of all the blocks, so a small block does not warn from its first number.
     *
     * @param  Collection<int, TrackingNumberSeries>  $blocks
     */
    private function warnAt(Collection $blocks): int
    {
        $lowWater = (int) ($blocks->last()?->low_water_mark ?? 200);

        return min($lowWater, (int) ceil($this->size($blocks) * self::WARN_REMAINING_FRACTION));
    }

    private function assertRange(int $first, int $last): void
    {
        $max = (int) str_repeat('9', self::MAX_DIGITS);

        if ($first <= 0 || $last < $first || $last > $max) {
            throw new TrackingNumberSeriesException('The tracking number series needs a first and a last number, with the first not above the last.');
        }
    }

    /** Any block of the same courier, on any account, sharing a number with first..last. */
    private function assertNoOverlap(string $courier, int $first, int $last): void
    {
        $overlap = $this->series()
            ->where('courier', $courier)
            ->where('first_number', '<=', $last)
            ->where('last_number', '>=', $first)
            ->exists();

        if ($overlap) {
            throw new TrackingNumberSeriesException('This tracking number series overlaps a block already recorded.');
        }
    }

    private function series()
    {
        return TrackingNumberSeries::on($this->connection);
    }

    private function numbers()
    {
        return TrackingNumber::on($this->connection);
    }

    private function db()
    {
        return DB::connection($this->connection);
    }
}
