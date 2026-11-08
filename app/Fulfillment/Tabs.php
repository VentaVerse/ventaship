<?php

namespace App\Fulfillment;

use App\Shipments\PhaseResolver;

class Tabs
{
    /** Tab key => phases it shows. `unpaid` is a payment filter (null phases = any). */
    public const PHASES = [
        'all' => PhaseResolver::SPINE,
        'unpaid' => null,
        'to_ship' => ['draft', 'attention', 'booking', 'to_handover'],
        'shipping' => ['in_transit'],
        'failed' => ['failed'],
        'lost_damaged' => ['lost_damaged'],
        'delivered' => ['delivered'],
        'returning' => ['returning'],
        'cancelled' => ['cancelled'],
    ];

    /** Chips under the To ship tab. */
    public const CHIPS = [
        'to_pack' => ['draft', 'attention'],
        'to_handover' => ['booking', 'to_handover'],
    ];

    public const LABELS = [
        'all' => 'All',
        'unpaid' => 'Unpaid',
        'to_ship' => 'To ship',
        'shipping' => 'Shipping',
        'failed' => 'Failed',
        'lost_damaged' => 'Lost & damaged',
        'delivered' => 'Delivered',
        'returning' => 'Returning',
        'cancelled' => 'Cancelled',
    ];

    public const CHIP_LABELS = [
        'to_pack' => 'To pack',
        'to_handover' => 'To handover',
    ];
}
