<?php

namespace App\Webhooks\Inbound;

use App\Couriers\CourierRegistry;
use App\Couriers\Spx\SpxFulfillment;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds the courier account an inbound courier webhook belongs to (base plan §3).
 *
 * - QuadX: `?token=` matched by sha256 against webhook_token_hash.
 * - SPX: the path token, else every SPX account whose external_ref (its SPX app id) equals the `app-id` header
 *   (with no `app-id` header: every SPX account that has an app id). The `check-sign` signature is verified against
 *   the account's own app secret over the raw body, and the signed `timestamp` must be within FRESH_SECONDS of now.
 *   When several accounts verify, the event's tracking number (then courier_ref) picks the account.
 */
final class AccountRouter
{
    public const TOKEN_MAX = 128;

    /** Accounts sharing one SPX app id that are tried; more than this is a misconfiguration. */
    private const APP_ID_CANDIDATES = 20;

    /** How far the signed `timestamp` header of an SPX push may be from now, either way. */
    public const FRESH_SECONDS = 600;

    public function quadx(string $token): ?CourierAccount
    {
        $account = $this->byToken($token);

        return $account !== null && $account->courier === 'quadx' ? $account : null;
    }

    /** The account (any courier) whose webhook token this is; soft-deleted accounts are gone. */
    public function byToken(string $token): ?CourierAccount
    {
        if ($token === '' || strlen($token) > self::TOKEN_MAX) {
            return null;
        }

        $hash = hash('sha256', $token);
        $account = CourierAccount::query()->where('webhook_token_hash', $hash)->first();

        return $account !== null && hash_equals((string) $account->webhook_token_hash, $hash) ? $account : null;
    }

    /** The SPX account this signed request belongs to; null when none is found, or it is unverified or stale. */
    public function spx(?string $token, Request $r): ?CourierAccount
    {
        $route = $this->routeSpx($token, $r);

        return $route['verified'] ? $route['account'] : null;
    }

    /**
     * @return array{account: ?CourierAccount, routed_by: string, verified: bool, problem: ?string}
     *   account null: unrouted. account set and verified false: the signature failed or the timestamp is stale
     *   (rejected); problem says which.
     */
    public function routeSpx(?string $token, Request $r): array
    {
        if ($token !== null) {
            $account = $this->byToken($token);
            if ($account === null || $account->courier !== 'spx') {
                return $this->route(null, 'none', false, 'unknown token');
            }

            return $this->checked($account, 'token', $this->verifies($account, $r), $r);
        }

        $appId = $r->header('app-id');
        $fallback = $appId === null || $appId === '';
        if (! $fallback && (! is_string($appId) || ! preg_match('/^\d{1,20}$/', $appId))) {
            return $this->route(null, 'none', false, 'malformed app-id header');
        }

        // No app-id header: every SPX account with a configured app id is a candidate, and each is checked with its
        // own current environment's app id and secret.
        $candidates = CourierAccount::query()
            ->where('courier', 'spx')
            ->when($fallback, fn ($q) => $q->whereNotNull('external_ref')->where('external_ref', '!=', ''), fn ($q) => $q->where('external_ref', $appId))
            ->orderBy('id')
            ->limit(self::APP_ID_CANDIDATES)
            ->get();
        $routedBy = $fallback ? 'app_id_fallback' : 'app_id';

        if ($candidates->isEmpty()) {
            return $this->route(null, 'none', false, $fallback ? 'no app-id header and no SPX app id configured' : 'unknown app id');
        }

        $verified = $candidates->filter(fn (CourierAccount $a) => $this->verifies($a, $r))->values();

        if ($verified->isEmpty()) {
            return $this->checked($candidates->first(), $routedBy, false, $r);
        }

        if ($verified->count() > 1) {
            $owner = $this->ownerByShipment($verified->pluck('id')->all(), $r->getContent());
            if ($owner !== null) {
                return $this->checked($verified->firstWhere('id', $owner), 'tracking', true, $r);
            }
        }

        return $this->checked($verified->first(), $routedBy, true, $r);
    }

    /**
     * A verified signature still fails when the signed `timestamp` header is more than FRESH_SECONDS from now: a
     * captured push replayed later is refused.
     *
     * @return array{account: CourierAccount, routed_by: string, verified: bool, problem: ?string}
     */
    private function checked(CourierAccount $account, string $routedBy, bool $signed, Request $r): array
    {
        if (! $signed) {
            return $this->route($account, $routedBy, false, 'signature mismatch');
        }

        $skew = abs(now()->getTimestamp() - (int) $r->header('timestamp'));
        if ($skew > self::FRESH_SECONDS) {
            return $this->route($account, $routedBy, false, "timestamp {$skew}s from now (limit ".self::FRESH_SECONDS.'s)');
        }

        return $this->route($account, $routedBy, true, null);
    }

    /** @return array{account: ?CourierAccount, routed_by: string, verified: bool, problem: ?string} */
    private function route(?CourierAccount $account, string $routedBy, bool $verified, ?string $problem): array
    {
        return ['account' => $account, 'routed_by' => $routedBy, 'verified' => $verified, 'problem' => $problem];
    }

    private function verifies(CourierAccount $account, Request $r): bool
    {
        try {
            $courier = CourierRegistry::for($account);

            return $courier instanceof SpxFulfillment && $courier->verifyWebhookSignature($r);
        } catch (Throwable $e) {
            Log::warning('SPX webhook signature could not be checked', ['account' => $account->public_id, 'exception' => $e::class]);

            return false;
        }
    }

    /**
     * Which of these accounts holds the shipment the (already verified) SPX body is about.
     *
     * @param  list<int>  $accountIds
     */
    private function ownerByShipment(array $accountIds, string $raw): ?int
    {
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return null;
        }

        foreach (['tracking_no' => 'tracking_number', 'order_id' => 'courier_ref'] as $field => $column) {
            $value = $payload[$field] ?? null;
            if (! is_string($value) || $value === '' || strlen($value) > 64) {
                continue;
            }

            $id = Shipment::query()->whereIn('courier_account_id', $accountIds)->where($column, $value)->value('courier_account_id');
            if ($id !== null) {
                return (int) $id;
            }
        }

        return null;
    }
}
