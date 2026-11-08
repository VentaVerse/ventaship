<?php

namespace App\Webhooks\Outbound;

use Carbon\CarbonInterface;

/**
 * When a failed webhook delivery is tried again: 1, 5, 15 and 30 minutes, 1, 2 and 4 hours, then every 6 hours,
 * until `ventaship.outbox_give_up_hours` (72) have passed since the delivery window started.
 */
final class RetrySchedule
{
    private const DELAYS = [60, 300, 900, 1800, 3600, 7200, 14400];

    private const THEN_EVERY = 21600;

    /**
     * @param  int  $attempts  attempts made so far, including the one that just failed
     * @return int|null seconds until the next attempt, or null to give up
     */
    public static function delayAfter(int $attempts, CarbonInterface $windowStart): ?int
    {
        $giveUpSeconds = (int) config('ventaship.outbox_give_up_hours', 72) * 3600;
        if ($windowStart->diffInSeconds(now(), false) >= $giveUpSeconds) {
            return null;
        }

        return self::DELAYS[max(0, $attempts - 1)] ?? self::THEN_EVERY;
    }
}
