<?php

return [
    /*
     * Reverse proxies in front of PHP whose X-Forwarded-* headers are believed, so request IPs (rate limits, logs,
     * audit) are the real callers'. Read by Laravel's TrustProxies middleware (config key `trustedproxy.proxies`).
     *
     * TRUSTED_PROXIES: comma-separated IPs or CIDR ranges, e.g. 127.0.0.1 for nginx on the same host. Empty (the
     * default): no proxy is trusted and the connecting IP is used. Never `*` on a host reachable without the proxy:
     * any caller could then choose its own IP.
     */
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
