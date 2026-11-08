<?php

return [
    // Merges stored waybills into one PDF for mass print (poppler-utils). A missing binary falls back to a ZIP.
    'pdfunite' => env('COURIERS_PDFUNITE', '/usr/bin/pdfunite'),

    // LBC has no test system: every call reaches the live gateway. Off unless switched on here; a lock is on only when
    // it is exactly true, and Http::preventStrayRequests() backs it up under tests (see App\Couriers\LiveGuard).
    'lbc_live' => (bool) env('COURIERS_LBC_LIVE', false),

    // SPX production-mode accounts are gated the same way; UAT and QuadX are not.
    'spx_live' => (bool) env('COURIERS_SPX_LIVE', false),

    // How often booked shipments are polled for their courier status.
    'poll_minutes' => (int) env('COURIERS_POLL_MINUTES', 10),

    // Registers only the fake "Test courier" (App\Couriers\FakeCourier), for driving the screens in a browser.
    'fake' => (bool) env('COURIERS_FAKE', false),
];
