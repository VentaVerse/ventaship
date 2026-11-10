<?php

namespace App\Couriers\Contracts;

/**
 * A courier whose shipments can be tracked on a public page addressed by
 * the tracking number alone.
 *
 * Optional, on purpose. The main contract's trackingLinks() carries the
 * per-shipment URLs a courier hands back through its API, and some hand
 * back none: QuadX answered `links: []` on a live refresh of a booked
 * parcel. A courier that also runs a public tracker implements this; one
 * that does not simply leaves it unimplemented and falls back to whatever
 * its API returned, or to plain text, which is honest.
 *
 * Where a courier implements this, its public
 * tracker WINS over the API's per-shipment links rather than standing in
 * for them. An API link can point somewhere the shopper does not recognise
 * (QuadX returns fareye.co, its logistics platform, not GoGo Xpress, the
 * brand they bought from), and the queue and the customer must never be
 * shown two different addresses for one parcel.
 */
interface ProvidesPublicTrackingUrl
{
    /**
     * @param array<string, mixed> $courierMeta the booking's own courier_meta,
     *   decoded. A courier with sandbox and live environments keeps a
     *   `mode` there from booking time, so a parcel booked against the
     *   sandbox links to the sandbox tracker even after the store goes live,
     *   and never the other way round.
     */
    public function publicTrackingUrl(string $trackingNumber, array $courierMeta): ?string;
}
