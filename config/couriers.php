<?php

return [
    // Merges stored waybills into one PDF for mass print (poppler-utils). A missing binary falls back to a ZIP.
    'pdfunite' => env('COURIERS_PDFUNITE', '/usr/bin/pdfunite'),

    // How often booked shipments are polled for their courier status.
    'poll_minutes' => (int) env('COURIERS_POLL_MINUTES', 10),

    // Registers only the fake "Test courier" (App\Couriers\FakeCourier), for driving the screens in a browser.
    'fake' => (bool) env('COURIERS_FAKE', false),
];
