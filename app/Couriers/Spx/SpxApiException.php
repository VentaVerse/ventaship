<?php

namespace App\Couriers\Spx;

/**
 * Thrown by SpxClient::post() whenever SPX responds with a reachable HTTP
 * 200 envelope whose ret_code is non-zero (per integration-brief.md §8:
 * "Response envelope is always { ret_code, message, data }; ret_code: 0 =
 * success."). Carries the raw ret_code so callers can branch on the specific
 * SPX error family (auth 1xxx, param 11xxx, account 12xxx, order 13xxx,
 * business-rule 10xxx — see guide.md's "Error codes mapping") without
 * string-matching the message.
 */
class SpxApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $retCode)
    {
        parent::__construct($message);
    }
}
