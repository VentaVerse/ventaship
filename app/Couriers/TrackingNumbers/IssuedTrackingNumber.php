<?php

namespace App\Couriers\TrackingNumbers;

/**
 * A number from a tracking number series: reserved (id set) or only
 * previewed by a dry run (id null).
 */
final class IssuedTrackingNumber
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $number,
        /** Numbers still available after this one. */
        public readonly int $remaining,
        /** True once the remaining count is low enough to request a new block. */
        public readonly bool $nearEnd,
    ) {
    }
}
