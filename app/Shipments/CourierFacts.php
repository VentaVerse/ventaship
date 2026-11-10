<?php

namespace App\Shipments;

use App\Models\Shipment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * What a courier tells us about a booked parcel besides its status: the actual fee it charges, and details kept in
 * the shipment's meta (tracking links, delivery estimate, driver, proof-of-delivery photos). Tracking polls,
 * refreshes and webhooks all land here, one transaction with the shipment row locked.
 *
 * Rules, so a report can only ever add knowledge:
 *  - an absent or null value never replaces a stored one;
 *  - the actual fee follows the newest report (by when the courier observed it); an older report is ignored;
 *  - a zero never replaces a positive actual fee (SPX's own examples answer 0 for "not known yet");
 *  - meta merges two levels deep: a top-level map (tracking_links, edd, driver, proofs) keeps its other keys, a list
 *    is replaced whole.
 *
 * Nothing here is part of the client API's shipment resource, so no outgoing webhook is written.
 */
final class CourierFacts
{
    /**
     * @param  array{actual_fee?: mixed, meta?: array<string, mixed>}  $facts
     * @return bool whether the shipment changed
     */
    public function apply(Shipment $s, array $facts, CarbonInterface $observedAt, string $source): bool
    {
        $fee = Money::decimal($facts['actual_fee'] ?? null);
        $meta = self::withoutNulls(is_array($facts['meta'] ?? null) ? $facts['meta'] : []);
        if ($fee === null && $meta === []) {
            return false;
        }

        return DB::transaction(function () use ($s, $fee, $meta, $observedAt, $source) {
            $shipment = Shipment::query()->whereKey($s->getKey())->lockForUpdate()->first();
            if ($shipment === null) {
                return false;
            }

            $current = is_array($shipment->meta) ? $shipment->meta : [];
            $merged = self::merge($current, $meta);

            if ($fee !== null && $this->feeWins($shipment, $current, $fee, $observedAt)) {
                $shipment->actual_fee = $fee;
                $merged['actual_fee_at'] = CarbonImmutable::instance($observedAt)->toIso8601String();
                $merged['actual_fee_source'] = $source;
            }

            $shipment->meta = $merged;
            if (! $shipment->isDirty()) {
                return false;
            }

            $shipment->save();
            $s->setRawAttributes($shipment->getAttributes(), true);

            return true;
        });
    }

    /** @param  array<string, mixed>  $meta */
    private function feeWins(Shipment $s, array $meta, string $fee, CarbonInterface $observedAt): bool
    {
        $stored = $s->actual_fee;
        if ($stored === null) {
            return true;
        }

        $at = is_string($meta['actual_fee_at'] ?? null) ? CarbonImmutable::parse($meta['actual_fee_at']) : null;
        if ($at !== null && $observedAt->lessThan($at)) {
            return false;
        }

        return ! (bccomp($fee, '0', 2) === 0 && bccomp((string) $stored, '0', 2) > 0);
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $new
     * @return array<string, mixed>
     */
    private static function merge(array $current, array $new): array
    {
        foreach ($new as $key => $value) {
            $old = $current[$key] ?? null;
            $current[$key] = is_array($value) && ! array_is_list($value) && is_array($old) && ! array_is_list($old)
                ? array_replace($old, $value)
                : $value;
        }

        return $current;
    }

    /**
     * Nulls and empty maps dropped at both levels, so "not reported" never erases what an earlier report said.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $meta): array
    {
        $clean = [];
        foreach ($meta as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                $value = array_filter($value, fn ($v) => $v !== null && $v !== []);
            }
            if ($value === null || $value === [] || $value === '') {
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }
}
