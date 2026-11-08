<?php

namespace App\Couriers\Lbc;

use App\Couriers\AccountSettings;

/**
 * The account facts LBC assigns the shipper, read from the courier account's settings: the client code and the
 * shipper account number. Neither is a secret key, but the shipper account number is never printed or logged in
 * full. The tracking number blocks are the account's tracking_series rows (TrackingNumberSeriesService).
 */
final class LbcAccount
{
    public function __construct(
        public readonly string $clientCode,
        public readonly string $shipperAccountNo,
    ) {
    }

    /** @throws LbcException naming every missing value */
    public static function fromSettings(AccountSettings $settings): self
    {
        $client = trim((string) $settings->read('lbc_client_code'));
        $account = trim((string) $settings->read('lbc_shipper_account_no'));

        $missing = [];
        if ($client === '') {
            $missing[] = 'client code';
        }
        if ($account === '') {
            $missing[] = 'shipper account number';
        }
        if ($missing !== []) {
            throw new LbcException('LBC settings are missing: ' . implode(', ', $missing) . '.');
        }

        // LBC's Client field is nvarchar(5).
        if (mb_strlen($client) > 5) {
            throw new LbcException('The LBC client code is longer than LBC accepts (5 characters).');
        }

        return new self($client, $account);
    }
}
