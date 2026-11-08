<?php

namespace App\Couriers;

use App\Models\CourierAccount;
use App\Support\Settings;

/**
 * The live-booking locks. A courier with no test system (LBC), or a production SPX account, may only send a
 * request that books or cancels for real when every lock is on:
 *
 *  - config('couriers.{courier}_live') (env COURIERS_LBC_LIVE / COURIERS_SPX_LIVE)
 *  - the setting safety.allow_live_{courier}
 *  - the account's allow_live_booking latch
 *  - the account in production mode
 *  - outside tests, an app running in production
 *
 * A lock is on only when its value is the boolean true. "1", "yes" and "true" are off.
 *
 * QuadX and SPX UAT accounts are never guarded, and neither is any operation not listed in GUARDED.
 */
final class LiveGuard
{
    /** @var array<string, list<string>> courier => operations that reach the live system */
    private const GUARDED = [
        'lbc' => ['pickup', 'airwaybill'],
        'spx' => ['create', 'cancel'],
    ];

    /** @throws LiveBookingBlocked */
    public static function assertAllowed(CourierAccount $account, string $operation): void
    {
        $courier = (string) $account->courier;

        if (! in_array($operation, self::GUARDED[$courier] ?? [], true)) {
            return;
        }

        // SPX has a UAT system; only its production accounts reach real parcels.
        if ($courier === 'spx' && $account->mode !== 'production') {
            return;
        }

        $missing = [];

        if (config("couriers.{$courier}_live") !== true) {
            $missing[] = 'COURIERS_' . strtoupper($courier) . '_LIVE';
        }

        if (app(Settings::class)->get("safety.allow_live_{$courier}") !== true) {
            $missing[] = "setting safety.allow_live_{$courier}";
        }

        if ($account->allow_live_booking !== true) {
            $missing[] = 'account allow_live_booking';
        }

        if ($account->mode !== 'production') {
            $missing[] = 'account in production mode';
        }

        if (! app()->runningUnitTests() && ! app()->isProduction()) {
            $missing[] = 'APP_ENV=production';
        }

        if ($missing !== []) {
            throw new LiveBookingBlocked($courier, $operation, $missing);
        }
    }
}
