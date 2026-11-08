<?php

namespace App\Couriers\Contracts;

/**
 * A courier whose bookings the scheduled status poll should ask about less
 * often than the poll itself runs.
 *
 * Optional. The poll runs on one schedule for every courier; a courier that
 * implements this is skipped for an order it was asked about more recently
 * than the interval it names for that order's current status. The time of
 * the last question is kept in the order's courier_meta as
 * `last_polled_at`. A single order's Refresh status is never throttled.
 */
interface ThrottlesStatusPolling
{
    /** Minutes to wait between two polls of a booking in this status. */
    public function pollIntervalMinutes(?string $status): int;
}
