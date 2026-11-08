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
 * - SPX: the path token, else every SPX account whose external_ref (its SPX app id) equals the `app-id` header.
 *   The `check-sign` signature is verified against the account's own app secret over the raw body. When several
 *   accounts share the app id and verify, the event's tracking number (then courier_ref) picks the account.
 */
final class AccountRouter
{
    public const TOKEN_MAX = 128;

    /** Accounts sharing one SPX app id that are tried; more than this is a misconfiguration. */
    private const APP_ID_CANDIDATES = 20;

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

    /** The SPX account this signed request belongs to; null when none is found or the signature fails. */
    public function spx(?string $token, Request $r): ?CourierAccount
    {
        $route = $this->routeSpx($token, $r);

        return $route['verified'] ? $route['account'] : null;
    }

    /**
     * @return array{account: ?CourierAccount, routed_by: string, verified: bool}
     *   account null: unrouted. account set and verified false: the signature failed (rejected).
     */
    public function routeSpx(?string $token, Request $r): array
    {
        if ($token !== null) {
            $account = $this->byToken($token);
            if ($account === null || $account->courier !== 'spx') {
                return ['account' => null, 'routed_by' => 'none', 'verified' => false];
            }

            return ['account' => $account, 'routed_by' => 'token', 'verified' => $this->verifies($account, $r)];
        }

        $appId = $r->header('app-id');
        if (! is_string($appId) || ! preg_match('/^\d{1,20}$/', $appId)) {
            return ['account' => null, 'routed_by' => 'none', 'verified' => false];
        }

        $candidates = CourierAccount::query()
            ->where('courier', 'spx')
            ->where('external_ref', $appId)
            ->orderBy('id')
            ->limit(self::APP_ID_CANDIDATES)
            ->get();

        if ($candidates->isEmpty()) {
            return ['account' => null, 'routed_by' => 'none', 'verified' => false];
        }

        $verified = $candidates->filter(fn (CourierAccount $a) => $this->verifies($a, $r))->values();

        if ($verified->isEmpty()) {
            return ['account' => $candidates->first(), 'routed_by' => 'app_id', 'verified' => false];
        }

        if ($verified->count() > 1) {
            $owner = $this->ownerByShipment($verified->pluck('id')->all(), $r->getContent());
            if ($owner !== null) {
                return ['account' => $verified->firstWhere('id', $owner), 'routed_by' => 'tracking', 'verified' => true];
            }
        }

        return ['account' => $verified->first(), 'routed_by' => 'app_id', 'verified' => true];
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
