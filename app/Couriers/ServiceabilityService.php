<?php

namespace App\Couriers;

use App\Couriers\Support\ServiceabilityVerdict;
use App\Models\CourierAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Asks one courier account, before anything is booked, whether it will carry a parcel from this pickup point to
 * this recipient.
 *
 *  1. TIMEOUT_SECONDS is a hard per-courier budget. Each adapter bounds its own HTTP call to it, and this class
 *     independently discards any verdict that arrived late. "Answered too late" is `unknown`, never a refusal.
 *  2. Every throwable is caught. An adapter blowing up resolves to `unknown` and never fails the caller.
 *
 * Definite answers (serviceable, not_serviceable) are cached for a day per account, pickup and recipient address,
 * under `cov:{account}:{sha1(default service)}:{sha1(pickup)}:{sha1(recipient)}`. An `unknown` is never cached: it says nothing about the
 * address, and the next call should ask again.
 */
class ServiceabilityService
{
    /**
     * The hard per-courier budget, in seconds. Anything slower resolves to `unknown`, which a client can still book
     * against: the courier itself stays the authority.
     */
    public const TIMEOUT_SECONDS = 5;

    /** How long a definite verdict is kept. */
    public const CACHE_TTL_SECONDS = 86400;

    /** @param  array<string, mixed>  $parcel  the described parcel, see Courier::checkServiceability() */
    public static function check(CourierAccount $account, CourierOrder $order, PickupPoint $from, array $parcel = []): ServiceabilityVerdict
    {
        $key = self::cacheKey($account, $order, $from, $parcel);

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return ServiceabilityVerdict::fromArray($cached);
        }

        $startedAt = microtime(true);

        try {
            $verdict = CourierRegistry::for($account)->checkServiceability($order, $from, $parcel);
        } catch (\Throwable $e) {
            Log::warning('Serviceability check failed', [
                'account' => $account->public_id, 'error' => $e->getMessage(),
            ]);
            $verdict = ServiceabilityVerdict::unknown(ServiceabilityVerdict::KIND_THEIR_SIDE);
        }

        // Enforced here as well as inside the adapter: an adapter may make more than one call, or use a client that
        // does not honour the budget it was handed.
        if ((microtime(true) - $startedAt) > self::TIMEOUT_SECONDS) {
            $verdict = ServiceabilityVerdict::unknown(ServiceabilityVerdict::KIND_UNREACHABLE);
        }

        if ($verdict->status !== ServiceabilityVerdict::UNKNOWN) {
            Cache::put($key, $verdict->toArray(), self::CACHE_TTL_SECONDS);
        }

        return $verdict;
    }

    /**
     * Did every courier we could actually ask refuse? False when there was nobody to ask.
     *
     * @param  array<string, ServiceabilityVerdict>  $verdicts
     */
    public static function allRefused(array $verdicts): bool
    {
        if ($verdicts === []) {
            return false;
        }

        foreach ($verdicts as $verdict) {
            if (! $verdict->isRefusal()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The addresses this verdict is about, normalised. Two shipments between the same places share one answer, and
     * editing either address produces a different key.
     */
    public static function cacheKey(CourierAccount $account, CourierOrder $order, PickupPoint $from, array $parcel = []): string
    {
        $pickup = self::fingerprint([
            $from->address_1, $from->barangay, $from->barangay_code, $from->district, $from->city, $from->city_code,
            $from->state, $from->state_code, $from->postcode, $from->country,
        ]);

        $recipient = self::fingerprint([
            $order->shipping_address_1, $order->shipping_barangay, $order->shipping_barangay_code,
            $order->shipping_district, $order->shipping_city, $order->shipping_city_code, $order->shipping_state,
            $order->shipping_state_code, $order->shipping_postcode, $order->shipping_country,
            // A COD parcel can be refused where a prepaid one is carried.
            $order->cod ? 'cod' : 'prepaid',
        ]);

        // A courier that quotes on the account's default service (QuadX's quadx_default_service) prices a different
        // service once that setting changes, so its cached fees must not outlive the change.
        $service = (string) AccountSettings::for($account)->read($account->courier . '_default_service', '');

        $key = 'cov:' . $account->getKey() . ':' . sha1($service) . ':' . sha1($pickup) . ':' . sha1($recipient);

        // A described parcel gets its own answer (a parcel too big for one courier is not an address refusal);
        // with none, the key is the one it always was.
        if ($parcel !== []) {
            ksort($parcel);
            $key .= ':' . sha1(self::fingerprint(array_map(fn ($k, $v) => $k . '=' . (is_scalar($v) ? (string) $v : ''), array_keys($parcel), $parcel)));
        }

        return $key;
    }

    /** @param  list<mixed>  $parts */
    private static function fingerprint(array $parts): string
    {
        return mb_strtolower(implode('|', array_map(fn ($p) => trim((string) $p), $parts)));
    }
}
