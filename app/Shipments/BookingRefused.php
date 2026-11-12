<?php

namespace App\Shipments;

use App\Couriers\Support\CourierRefusal;
use RuntimeException;

/** The courier refused a booking at the final check, before anything was saved. */
final class BookingRefused extends RuntimeException
{
    public function __construct(public readonly CourierRefusal $refusal, public readonly ?string $courierText = null)
    {
        parent::__construct($refusal->message());
    }
}
