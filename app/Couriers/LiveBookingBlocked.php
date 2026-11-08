<?php

namespace App\Couriers;

use RuntimeException;

/**
 * A real courier request that one or more live-booking locks forbid (see LiveGuard). Nothing was sent.
 */
final class LiveBookingBlocked extends RuntimeException
{
    /** @param  list<string>  $missing  the locks that are off, by name */
    public function __construct(
        public readonly string $courier,
        public readonly string $operation,
        public readonly array $missing,
    ) {
        parent::__construct(sprintf(
            'Live %s %s is blocked: %s.',
            strtoupper($courier),
            $operation,
            implode(', ', $missing),
        ));
    }
}
