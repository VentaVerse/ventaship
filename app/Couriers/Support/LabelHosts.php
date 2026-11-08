<?php

namespace App\Couriers\Support;

/**
 * Where a courier's label link may be fetched from. The link comes out of a courier's answer, so it is never
 * followed blindly: https only, the default port, no credentials in the URL, and a host on the courier's list.
 */
final class LabelHosts
{
    /**
     * A host is allowed when it equals an entry or is a subdomain of one. QuadX serves its AWBs from one S3 bucket
     * (see tests/Fixtures/quadx/print-awb.json), listed by exact host so no other bucket is trusted.
     * SPX's UAT labels come from spx.co.th (see the SPX fulfilment tests).
     */
    private const HOSTS = [
        'quadx' => ['quadx.xyz', 'gogoxpress.com', 'quadx-awb.s3.ap-southeast-1.amazonaws.com'],
        'spx' => ['spx.ph', 'shopee.ph', 'spx.co.th'],
    ];

    public static function allows(string $courier, mixed $url): bool
    {
        if (! is_string($url) || $url === '' || ! isset(self::HOSTS[$courier])) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '') {
            return false;
        }

        foreach (self::HOSTS[$courier] as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
