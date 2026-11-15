<?php

return [
    // Merges stored waybills into one PDF for mass print (poppler-utils). A missing binary falls back to a ZIP.
    'pdfunite' => env('COURIERS_PDFUNITE', '/usr/bin/pdfunite'),
];
