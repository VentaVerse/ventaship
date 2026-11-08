<?php

namespace App\Couriers\Contracts;

/**
 * A courier with no cancel endpoint: cancelling a booking only resets it in
 * this store, and the courier has to be told some other way.
 *
 * Optional, like ProvidesPublicTrackingUrl. Wherever a booking can be
 * cancelled (the queue's confirm dialog, the drawer, the result of the
 * cancel itself, the order history and the ERP's answer), the notice is
 * shown so nobody believes the courier was told.
 */
interface CancelsLocallyOnly
{
    /** One sentence, shown wherever a booking with this courier is cancelled. */
    public function localCancelNotice(): string;
}
