<?php

namespace App\Couriers\TrackingNumbers;

use App\Couriers\TrackingNumbers\TrackingNumber;
use App\Couriers\TrackingNumbers\TrackingNumberSeries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Issues tracking numbers from a block the courier assigned to the store.
 *
 * The courier does not check uniqueness for us, so this is the only thing
 * standing between two parcels and one tracking number. Every number moves
 * through three states (see TrackingNumber):
 *
 *   reserve()  takes the lowest released number, or the next unused one,
 *              and marks it reserved. Done under a row lock on the series,
 *              so two concurrent bookings can never take the same number.
 *   spend()    the courier saved the request, or the outcome is unknown
 *              (timeout, network error, unreadable answer): it may exist on
 *              the courier's side, so it is never issued again.
 *   release()  the courier definitely rejected the request and saved
 *              nothing: the number goes back and is the next one issued.
 *
 * A reservation that is never settled (the process died mid-request) stays
 * reserved and is never issued again, which is the safe reading of "we do
 * not know".
 *
 * Numbers never leave the block: past the last number every claim refuses,
 * and a warning is logged once the remaining count falls to
 * WARN_REMAINING_FRACTION of the block, because a new block is requested
 * from the courier by hand and takes time.
 */
class TrackingNumberSeriesService
{
    /** Warn when this share of the block, or less, is still unissued. */
    public const WARN_REMAINING_FRACTION = 0.2;

    /** Transaction attempts when the database reports a lock conflict. */
    private const ATTEMPTS = 5;

    /** @param string|null $connection database connection; null uses the models' default */
    public function __construct(private readonly ?string $connection = null)
    {
    }

    /**
     * The number reserve() would hand out now, without taking it. For dry
     * runs: nothing is written.
     */
    public function peek(string $courier, int $first, int $last): IssuedTrackingNumber
    {
        $this->assertRange($first, $last);

        $series = $this->series()->where('courier', $courier)->where('first_number', $first)->first();

        if ($series === null) {
            $this->assertNoOverlap($courier, $first, $last, null);

            return $this->issued(null, $first, $first, $last);
        }

        $this->assertSameEnd($series, $last);

        $released = $this->numbers()
            ->where('series_id', $series->id)
            ->where('status', TrackingNumber::RELEASED)
            ->min('number');

        $number = $released !== null ? (int) $released : $this->nextUnused($series);

        return $this->issued(null, $number, $first, $last, $series);
    }

    /**
     * How much of a block is left, for the settings page. Reads only.
     *
     * `remaining` counts every number that can still be issued, the next one
     * included: the never-issued tail of the block plus any released number.
     *
     * @return array{first:int, last:int, size:int, remaining:int, next:?int, near_end:bool}
     */
    public function status(string $courier, int $first, int $last): array
    {
        $this->assertRange($first, $last);

        $size = $last - $first + 1;
        $series = $this->series()->where('courier', $courier)->where('first_number', $first)->first();

        if ($series === null) {
            $this->assertNoOverlap($courier, $first, $last, null);
            $remaining = $size;
            $next = $first;
        } else {
            $this->assertSameEnd($series, $last);

            $released = $this->numbers()
                ->where('series_id', $series->id)
                ->where('status', TrackingNumber::RELEASED);

            $unissued = $series->last_issued_number === null ? $size : max(0, $last - $series->last_issued_number);
            $remaining = $unissued + (clone $released)->count();

            $lowestReleased = (clone $released)->min('number');
            $next = $lowestReleased !== null
                ? (int) $lowestReleased
                : ($unissued > 0 ? ($series->last_issued_number === null ? $first : $series->last_issued_number + 1) : null);
        }

        return [
            'first' => $first,
            'last' => $last,
            'size' => $size,
            'remaining' => $remaining,
            'next' => $next,
            'near_end' => $remaining <= (int) ceil($size * self::WARN_REMAINING_FRACTION),
        ];
    }

    /**
     * Take the next number for a request about to be sent.
     *
     * @throws TrackingNumberSeriesException when the block is used up or misconfigured
     */
    public function reserve(string $courier, int $first, int $last, ?int $orderId = null): IssuedTrackingNumber
    {
        $this->assertRange($first, $last);

        $issued = $this->db()->transaction(function () use ($courier, $first, $last, $orderId) {
            $series = $this->lockedSeries($courier, $first, $last);

            $released = $this->numbers()
                ->where('series_id', $series->id)
                ->where('status', TrackingNumber::RELEASED)
                ->orderBy('number')
                ->lockForUpdate()
                ->first();

            if ($released !== null) {
                // Conditional on the state read under the lock, so a second
                // writer can never flip the same row to reserved twice.
                $taken = $this->numbers()
                    ->whereKey($released->id)
                    ->where('status', TrackingNumber::RELEASED)
                    ->update([
                        'status' => TrackingNumber::RESERVED,
                        'order_id' => $orderId,
                        'outcome' => null,
                        'updated_at' => now(),
                    ]);

                if ($taken !== 1) {
                    throw new TrackingNumberSeriesException('A released tracking number was taken by another request. Try again.');
                }

                return $this->issued($released->id, (int) $released->number, $first, $last, $series);
            }

            $number = $this->nextUnused($series);

            // Conditional on the value read under the lock: if anything moved
            // the counter in between, nothing is written and nothing issued.
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
                'order_id' => $orderId,
            ]);

            $series->last_issued_number = $number;

            return $this->issued($row->id, $number, $first, $last, $series);
        }, self::ATTEMPTS);

        if ($issued->nearEnd) {
            Log::warning('Tracking number series is running out', [
                'courier' => $courier,
                'first_number' => $first,
                'last_number' => $last,
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

    /** The series row, created on first use, locked for this transaction. */
    private function lockedSeries(string $courier, int $first, int $last): TrackingNumberSeries
    {
        $series = $this->series()->where('courier', $courier)->where('first_number', $first)->lockForUpdate()->first();

        if ($series === null) {
            $this->assertNoOverlap($courier, $first, $last, null);

            // insertOrIgnore: two first claims racing on an empty table both
            // land here, and the unique key lets exactly one row exist.
            $this->series()->insertOrIgnore([
                'courier' => $courier,
                'first_number' => $first,
                'last_number' => $last,
                'last_issued_number' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $series = $this->series()->where('courier', $courier)->where('first_number', $first)->lockForUpdate()->firstOrFail();
        }

        $this->assertSameEnd($series, $last);

        return $series;
    }

    /** The next never-issued number, refusing past the end of the block. */
    private function nextUnused(TrackingNumberSeries $series): int
    {
        $next = $series->last_issued_number === null ? $series->first_number : $series->last_issued_number + 1;

        if ($next > $series->last_number) {
            throw new TrackingNumberSeriesException(
                "The tracking number series {$series->first_number} to {$series->last_number} is used up. Request a new block from the courier."
            );
        }

        return $next;
    }

    private function issued(?int $id, int $number, int $first, int $last, ?TrackingNumberSeries $series = null): IssuedTrackingNumber
    {
        // Numbers not yet issued, other than this one. Released numbers are
        // issued before new ones, so they count as still available.
        $highest = max($number, (int) ($series?->last_issued_number ?? 0));
        $remaining = max(0, $last - $highest);
        if ($series !== null) {
            $remaining += (int) $this->numbers()
                ->where('series_id', $series->id)
                ->where('status', TrackingNumber::RELEASED)
                ->where('number', '!=', $number)
                ->count();
        }

        $size = $last - $first + 1;

        return new IssuedTrackingNumber(
            id: $id,
            number: $number,
            remaining: $remaining,
            nearEnd: $remaining <= (int) ceil($size * self::WARN_REMAINING_FRACTION),
        );
    }

    private function assertRange(int $first, int $last): void
    {
        if ($first <= 0 || $last < $first) {
            throw new TrackingNumberSeriesException('The tracking number series needs a first and a last number, with the first not above the last.');
        }
    }

    /** A block's end is fixed when it is issued; a changed end is a typing mistake, not a new block. */
    private function assertSameEnd(TrackingNumberSeries $series, int $last): void
    {
        if ($series->last_number !== $last) {
            throw new TrackingNumberSeriesException(
                "The tracking number series starting at {$series->first_number} was recorded as ending at {$series->last_number}, not {$last}."
            );
        }
    }

    private function assertNoOverlap(string $courier, int $first, int $last, ?int $exceptId): void
    {
        $overlap = $this->series()
            ->where('courier', $courier)
            ->where('first_number', '<=', $last)
            ->where('last_number', '>=', $first)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($overlap) {
            throw new TrackingNumberSeriesException('This tracking number series overlaps a block already in use.');
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
