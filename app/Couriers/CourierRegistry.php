<?php

namespace App\Couriers;

use App\Couriers\Contracts\Courier;
use App\Models\CourierAccount;
use InvalidArgumentException;

/**
 * The courier modules this install knows, keyed by courier ('quadx', ...). CourierServiceProvider registers them;
 * nothing here knows about a specific courier. A courier is always built for one account: its constructor takes
 * (CourierAccount, AccountSettings).
 *
 * Tests swap a courier for a FakeCourier with fake(); for() then answers the fake for every account of that courier.
 */
final class CourierRegistry
{
    /** @var array<string, class-string<Courier>> */
    private static array $registered = [];

    /** @var array<string, Courier> */
    private static array $fakes = [];

    /** @param class-string<Courier> $cls */
    public static function register(string $key, string $cls): void
    {
        if (! is_subclass_of($cls, Courier::class)) {
            throw new InvalidArgumentException("{$cls} does not implement " . Courier::class);
        }

        self::$registered[$key] = $cls;
    }

    /** The courier for this account, configured from the account's own settings. */
    public static function for(CourierAccount $account): Courier
    {
        $key = (string) $account->courier;

        if (isset(self::$fakes[$key])) {
            return self::$fakes[$key];
        }

        $cls = self::$registered[$key] ?? throw new InvalidArgumentException("Unknown courier \"{$key}\".");

        return new $cls($account, AccountSettings::for($account));
    }

    /**
     * A schema-only instance with empty settings and an unsaved account, for settingsSchema(), statusPhase() and the
     * capability list. It holds no credentials, so it cannot talk to the courier.
     */
    public static function describe(string $key): Courier
    {
        $cls = self::$registered[$key] ?? null;
        if ($cls === null) {
            return self::$fakes[$key] ?? throw new InvalidArgumentException("Unknown courier \"{$key}\".");
        }

        $account = new CourierAccount(['courier' => $key, 'mode' => 'staging', 'status' => 'active']);

        return new $cls($account, AccountSettings::empty($account));
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::$registered);
    }

    public static function has(string $key): bool
    {
        return isset(self::$registered[$key]);
    }

    public static function fake(string $key, Courier $impl): void
    {
        self::$fakes[$key] = $impl;
    }

    public static function clearFakes(): void
    {
        self::$fakes = [];
    }
}
