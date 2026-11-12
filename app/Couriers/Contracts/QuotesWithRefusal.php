<?php

namespace App\Couriers\Contracts;

use App\Couriers\CourierOrder;
use App\Couriers\Support\FeeQuote;

/**
 * A courier that can say, before anything is booked, both what a shipment costs and why it would refuse it.
 * estimateFee() stays as it is (null on any failure) for the API's quotes; this is the dashboard's final check.
 *
 * Only an answer that the booking itself would also be refused is a refusal. Anything else (no credentials, the
 * courier unreachable, a figure it did not give) is FeeQuote::unavailable(), so a courier outage never blocks a booking.
 */
interface QuotesWithRefusal
{
    /** @param  array<string, mixed>  $options  the same shape as createShipment()'s $opts */
    public function quoteFee(CourierOrder $order, array $options): FeeQuote;
}
