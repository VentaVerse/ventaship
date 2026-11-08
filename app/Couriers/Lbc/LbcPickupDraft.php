<?php

namespace App\Couriers\Lbc;

use App\Couriers\CourierOrder;
use Carbon\CarbonInterface;
use App\Models\PickupAddress;

/** Everything one pickup request is built from, and what would stop it. */
final class LbcPickupDraft
{
    /** @param string[] $blockers reasons the request must not be sent */
    public function __construct(
        public readonly CourierOrder $order,
        public readonly PickupAddress $from,
        public readonly LbcParcel $parcel,
        public readonly LbcAccount $account,
        public readonly LbcArea $origin,
        public readonly LbcArea $destination,
        public readonly CarbonInterface $transactionAt,
        public readonly CarbonInterface $preferredAt,
        public readonly string $mode,
        public readonly array $blockers,
    ) {
    }
}
