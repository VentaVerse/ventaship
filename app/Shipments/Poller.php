<?php

namespace App\Shipments;

use App\Couriers\Contracts\Courier;
use App\Couriers\Contracts\ThrottlesStatusPolling;
use App\Couriers\CourierOrder;
use App\Couriers\CourierRegistry;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ventaship:couriers-poll (base plan §3 "Polling"). Per account, under the lock `poll:{account}`:
 *  1. SPX pending creations (`booking` + `creating`) are resolved first, through BookingOutcomes;
 *  2. every non-terminal booked shipment that is due (`next_poll_at`, and ThrottlesStatusPolling for couriers such
 *     as LBC) is asked getTracking(), and what it reports is recorded through StatusRecorder with source `poll`.
 *
 * No transaction is held during a courier call. Polling bookkeeping (last_polled_at, next_poll_at) is written
 * without touching updated_at. One shipment's courier error never stops the others.
 */
final class Poller
{
    /** Shipments asked about per account per run; the rest wait for the next run. */
    public const PER_ACCOUNT = 500;

    /** Overridable (tests). Batches rotate: least recently polled first, so nothing past the cap starves. */
    public int $perAccount = self::PER_ACCOUNT;

    private const LOCK_SECONDS = 900;

    public function __construct(private readonly BookingOutcomes $outcomes, private readonly TrackingRefresher $tracking) {}

    /** @return int the shipments the courier was asked about */
    public function run(): int
    {
        $accountIds = $this->due()->distinct()->pluck('courier_account_id')
            ->merge($this->creating()->distinct()->pluck('courier_account_id'))
            ->unique()
            ->sort()
            ->values();

        $polled = 0;
        foreach ($accountIds as $accountId) {
            $lock = Cache::lock("poll:{$accountId}", self::LOCK_SECONDS);
            if (! $lock->get()) {
                continue; // another poll still runs for this account
            }

            try {
                $polled += $this->pollAccount((int) $accountId);
            } catch (Throwable $e) {
                Log::error('Polling an account failed', ['account_id' => $accountId, 'exception' => $e::class]);
            } finally {
                $lock->release();
            }
        }

        return $polled;
    }

    private function pollAccount(int $accountId): int
    {
        $account = CourierAccount::query()->find($accountId);
        if ($account === null) {
            return 0; // deleted: nothing can be asked with it
        }

        $courier = CourierRegistry::for($account);
        $polled = 0;

        $creating = $this->leastRecentFirst($this->creating()->where('courier_account_id', $accountId))->limit($this->perAccount)->get();
        foreach ($creating as $s) {
            $this->resolveCreation($courier, $s);
            $polled++;
        }

        $due = $this->leastRecentFirst($this->due()->where('courier_account_id', $accountId))->limit($this->perAccount)->get();
        foreach ($due as $s) {
            if ($this->throttled($courier, $s)) {
                continue;
            }

            $this->track($courier, $s);
            $polled++;
        }

        return $polled;
    }

    private function resolveCreation(Courier $courier, Shipment $s): void
    {
        try {
            $answer = Context::scope(
                fn () => $courier->resolvePendingCreation(CourierOrder::fromApi($s)),
                ['account_id' => $s->courier_account_id, 'shipment_id' => $s->id, 'operation' => 'resolve_pending_creation'],
            );
        } catch (Throwable $e) {
            Log::warning('Pending creation could not be resolved', ['shipment' => $s->public_id, 'exception' => $e::class]);
            $answer = null;
        }

        $this->touch($s, $courier, null);

        if (! is_array($answer)) {
            return; // still pending at the courier
        }

        $tracking = $answer['tracking_number'] ?? null;
        if (is_string($tracking) && trim($tracking) !== '') {
            $meta = is_array($answer['meta'] ?? null) ? $answer['meta'] : [];
            $this->outcomes->creationResolved($s->id, trim($tracking), $meta, Money::decimal($answer['fee'] ?? null));

            return;
        }

        if (($answer['courier_status'] ?? null) === 'creation_failed') {
            $error = data_get($answer, 'meta.creation_error');
            $message = is_string($error) && trim($error) !== '' ? 'The courier refused the booking: '.trim($error) : 'The courier refused the booking.';
            // Settles a requested cancel too: nothing exists at the courier.
            $this->outcomes->failed($s->id, 'courier_refused', $message);
        }
    }

    private function track(Courier $courier, Shipment $s): void
    {
        $status = $s->courier_status;
        try {
            $result = Context::scope(
                fn () => $courier->getTracking((string) $s->tracking_number),
                ['account_id' => $s->courier_account_id, 'shipment_id' => $s->id, 'operation' => 'tracking'],
            );

            if (is_array($result) && ($result['success'] ?? false) === true) {
                $this->tracking->recordTracking($s, $result, 'poll');
                $status = $s->courier_status;
            } else {
                Log::info('Tracking poll refused by the courier', ['shipment' => $s->public_id]);
            }
        } catch (Throwable $e) {
            Log::warning('Tracking poll failed', ['shipment' => $s->public_id, 'exception' => $e::class]);
        }

        $this->touch($s, $courier, $status);
    }

    /** A courier that polls less often than the schedule: skipped until its interval for this status has passed. */
    private function throttled(Courier $courier, Shipment $s): bool
    {
        if (! $courier instanceof ThrottlesStatusPolling || $s->last_polled_at === null) {
            return false;
        }

        return $s->last_polled_at->copy()->addMinutes($courier->pollIntervalMinutes($s->courier_status))->isFuture();
    }

    private function touch(Shipment $s, Courier $courier, ?string $status): void
    {
        $next = $courier instanceof ThrottlesStatusPolling
            ? now()->addMinutes(max(1, $courier->pollIntervalMinutes($status)))
            : null;

        Shipment::query()->whereKey($s->id)->toBase()->update(['last_polled_at' => now(), 'next_poll_at' => $next]);
    }

    /** Non-terminal booked shipments with a tracking number whose next poll is due. */
    private function due(): Builder
    {
        return Shipment::query()
            ->where('state', 'booked')
            ->whereNull('terminal_at')
            ->whereNotNull('courier_account_id')
            ->whereNotNull('tracking_number')
            ->where('tracking_number', '!=', '')
            ->where(fn (Builder $q) => $q->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()));
    }

    /** Never polled first, then the longest ago, then by id. */
    private function leastRecentFirst(Builder $q): Builder
    {
        return $q->orderByRaw('CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END')->orderBy('last_polled_at')->orderBy('id');
    }

    /** SPX bookings submitted but not yet created at the courier. */
    private function creating(): Builder
    {
        return Shipment::query()
            ->where('state', 'booking')
            ->where('courier_status', 'creating')
            ->whereNotNull('courier_account_id');
    }
}
