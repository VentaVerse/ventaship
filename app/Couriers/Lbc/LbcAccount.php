<?php

namespace App\Couriers\Lbc;

use App\Couriers\CourierSetting;

/**
 * The account facts LBC assigns the store, read from the courier settings:
 * the client code, the shipper account number and the tracking number
 * series. None is a secret key, but the shipper account number is never
 * printed or logged in full.
 */
final class LbcAccount
{
    public function __construct(
        public readonly string $clientCode,
        public readonly string $shipperAccountNo,
        public readonly int $seriesFirst,
        public readonly int $seriesLast,
    ) {
    }

    /** @throws LbcException naming every missing value */
    public static function fromSettings(): self
    {
        $stored = CourierSetting::query()
            ->whereIn('key', ['lbc_client_code', 'lbc_shipper_account_no', 'lbc_tracking_series_start', 'lbc_tracking_series_end'])
            ->pluck('value', 'key');

        $client = trim((string) ($stored['lbc_client_code'] ?? ''));
        $account = trim((string) ($stored['lbc_shipper_account_no'] ?? ''));
        $first = trim((string) ($stored['lbc_tracking_series_start'] ?? ''));
        $last = trim((string) ($stored['lbc_tracking_series_end'] ?? ''));

        $missing = [];
        if ($client === '') {
            $missing[] = 'client code';
        }
        if ($account === '') {
            $missing[] = 'shipper account number';
        }
        if (!ctype_digit($first) || !ctype_digit($last)) {
            $missing[] = 'tracking number series (first and last number, digits only)';
        }
        if ($missing !== []) {
            throw new LbcException('LBC settings are missing: ' . implode(', ', $missing) . '.');
        }

        // LBC's Client field is nvarchar(5).
        if (mb_strlen($client) > 5) {
            throw new LbcException('The LBC client code is longer than LBC accepts (5 characters).');
        }

        return new self($client, $account, (int) $first, (int) $last);
    }
}
