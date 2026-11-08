<?php

namespace App\Couriers;

use App\Api\ApiError;
use App\Models\CourierAccount;

/**
 * Which courier account a client request means: `account_id`, or the default account for `courier`.
 * Accounts are install-wide (no tenancy), so every client app may use every account.
 */
final class AccountResolver
{
    /** @throws ApiError 422 account_not_found */
    public static function resolve(?string $accountId, ?string $courier): CourierAccount
    {
        $accountId = $accountId === '' ? null : $accountId;
        $courier = $courier === '' ? null : $courier;

        $account = match (true) {
            $accountId !== null => CourierAccount::query()->where('public_id', $accountId)->first(),
            $courier !== null => CourierAccount::query()->where('default_slot', $courier)->first(),
            default => null,
        };

        if ($account === null) {
            throw new ApiError(422, 'account_not_found', 'No such courier account.', false, [
                $accountId !== null ? 'account_id' : 'courier' => [
                    $accountId !== null ? 'There is no courier account with this id.' : 'This courier has no default account.',
                ],
            ]);
        }

        if ($accountId !== null && $courier !== null && $account->courier !== $courier) {
            throw new ApiError(422, 'invalid_request', 'The request is invalid.', false, [
                'courier' => ['The account belongs to a different courier.'],
            ]);
        }

        if (! CourierRegistry::has((string) $account->courier)) {
            throw new ApiError(422, 'account_misconfigured', 'This courier account cannot be used.', false, [
                'account' => ['This courier is not installed.'],
            ]);
        }

        return $account;
    }

    /**
     * An account that can book now: active, with no configuration issues.
     *
     * @throws ApiError 422 account_misconfigured
     */
    public static function assertBookable(CourierAccount $account): void
    {
        $issues = [];
        if ($account->status !== 'active') {
            $issues[] = 'The account is '.$account->status.'.';
        }

        $issues = array_merge($issues, array_map('strval', CourierRegistry::for($account)->configurationIssues()));

        if ($issues !== []) {
            throw new ApiError(422, 'account_misconfigured', 'This courier account cannot book shipments.', false, [
                'account' => array_values($issues),
            ]);
        }
    }

    /** @throws ApiError 422 account_misconfigured */
    public static function assertActive(CourierAccount $account): void
    {
        if ($account->status !== 'active') {
            throw new ApiError(422, 'account_misconfigured', 'This courier account cannot be used.', false, [
                'account' => ['The account is '.$account->status.'.'],
            ]);
        }
    }
}
