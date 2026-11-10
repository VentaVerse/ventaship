<?php

namespace App\Shipments;

use App\Api\ApiError;
use App\Couriers\CourierRegistry;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * POST /shipments/{id}/refresh: asks the courier for the shipment's tracking now, at most once a minute per
 * shipment, and records every reported event through StatusRecorder (source `refresh`).
 */
final class TrackingRefresher
{
    public const PER_SECONDS = 60;

    private readonly CourierFacts $facts;

    public function __construct(private readonly StatusRecorder $recorder, ?CourierFacts $facts = null)
    {
        $this->facts = $facts ?? new CourierFacts;
    }

    /** @throws ApiError 409 not_trackable, 429 rate_limited, 502 courier_unavailable */
    public function refresh(Shipment $s): Shipment
    {
        $account = $s->courierAccount;
        if ($s->tracking_number === null || $s->tracking_number === '' || $account === null
            || ! in_array($s->state, ['booked', 'booking'], true)) {
            throw new ApiError(409, 'not_trackable', 'This shipment has no booking to track yet.');
        }

        $key = 'shipment-refresh:'.$s->id;
        if (! RateLimiter::attempt($key, 1, fn () => true, self::PER_SECONDS)) {
            $retry = max(1, RateLimiter::availableIn($key));

            throw new ApiError(429, 'rate_limited', 'This shipment was refreshed less than a minute ago.', true, [], ['Retry-After' => (string) $retry]);
        }

        try {
            $result = Context::scope(
                fn () => CourierRegistry::for($account)->getTracking($s->tracking_number),
                ['account_id' => $account->getKey(), 'shipment_id' => $s->id, 'operation' => 'tracking'],
            );
        } catch (Throwable $e) {
            Log::warning('Tracking refresh failed', ['shipment' => $s->public_id, 'exception' => $e::class]);

            throw $this->unavailable();
        }

        if (! is_array($result) || ($result['success'] ?? false) !== true) {
            Log::warning('Tracking refresh refused by the courier', ['shipment' => $s->public_id]);

            throw $this->unavailable();
        }

        $this->recordTracking($s, $result, 'refresh');

        return $s->refresh();
    }

    /**
     * Records every dated event of a getTracking() answer, then what else it reported (`actual_fee`, `meta`: see
     * CourierFacts); the refresh endpoint and the poller share it.
     *
     * @param  array<string, mixed>  $result  getTracking()'s answer
     */
    public function recordTracking(Shipment $s, array $result, string $source): void
    {
        $timeline = is_array($result['timeline'] ?? null) ? $result['timeline'] : [];
        $recorded = false;

        foreach ($timeline as $event) {
            $status = is_array($event) ? ($event['status'] ?? null) : null;
            $at = is_array($event) ? $this->time($event['at'] ?? null) : null;
            if (! is_string($status) || $status === '' || $at === null) {
                continue;
            }

            $remarks = is_string($event['remarks'] ?? null) ? $event['remarks'] : null;
            $this->recorder->record($s, $status, $at, $source, $remarks, $event);
            $recorded = true;
        }

        // No dated timeline: the current status is recorded only when it changed, so refreshing never piles up copies.
        $status = $result['status'] ?? null;
        if (! $recorded && is_string($status) && $status !== '' && $status !== $s->refresh()->courier_status) {
            $this->recorder->record($s, $status, now(), $source);
        }

        $this->facts->apply($s, [
            'actual_fee' => $result['actual_fee'] ?? null,
            'meta' => is_array($result['meta'] ?? null) ? $result['meta'] : [],
        ], now(), $source);
    }

    private function time(mixed $at): ?CarbonImmutable
    {
        try {
            return match (true) {
                is_int($at) => CarbonImmutable::createFromTimestamp($at),
                is_string($at) && $at !== '' => CarbonImmutable::parse($at),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private function unavailable(): ApiError
    {
        return new ApiError(502, 'courier_unavailable', 'The courier could not be reached. Try again shortly.', true);
    }
}
