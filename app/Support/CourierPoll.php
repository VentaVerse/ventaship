<?php

namespace App\Support;

use App\Shipments\Poller;
use Carbon\CarbonImmutable;
use Throwable;

/** The courier status check: how often it runs (set in Settings, not .env) and what its last run did. */
final class CourierPoll
{
    public const MINUTES_KEY = 'couriers.poll_minutes';

    public const LAST_KEY = 'couriers.poll_last';

    public const DEFAULT_MINUTES = 10;

    /** 1 to 59: the schedule is "every N minutes" within the hour. The default while no database answers (install). */
    public static function minutes(Settings $settings): int
    {
        try {
            $value = $settings->get(self::MINUTES_KEY);
        } catch (Throwable) {
            return self::DEFAULT_MINUTES;
        }

        return is_int($value) && $value >= 1 && $value <= 59 ? $value : self::DEFAULT_MINUTES;
    }

    /** Runs the check now and remembers when and how many shipments it asked about. */
    public static function run(Poller $poller, Settings $settings): int
    {
        $count = $poller->run();
        $settings->set(self::LAST_KEY, ['at' => now()->toIso8601String(), 'count' => $count]);

        return $count;
    }

    /** @return array{at: CarbonImmutable, count: int}|null */
    public static function last(Settings $settings): ?array
    {
        $value = $settings->get(self::LAST_KEY);
        if (! is_array($value) || ! is_string($value['at'] ?? null)) {
            return null;
        }
        try {
            return ['at' => CarbonImmutable::parse($value['at']), 'count' => (int) ($value['count'] ?? 0)];
        } catch (Throwable) {
            return null;
        }
    }
}
