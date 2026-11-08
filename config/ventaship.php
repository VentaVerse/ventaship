<?php

return [
    // base64-encoded 32-byte key for CredentialVault. Never derived from APP_KEY.
    'vault_key' => env('VENTASHIP_VAULT_KEY'),

    // Seconds a signed API request stays valid.

    // The outgoing webhook outbox stops retrying after this many hours.
    'outbox_give_up_hours' => 72,
];
