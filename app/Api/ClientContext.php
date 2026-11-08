<?php

namespace App\Api;

use App\Models\ApiToken;
use App\Models\ClientApp;
use LogicException;

/** The authenticated caller of the current request, set by AuthenticateClient. */
final class ClientContext
{
    public static function app(): ClientApp
    {
        return request()->attributes->get('client_app')
            ?? throw new LogicException('No authenticated client app on this request.');
    }

    public static function token(): ApiToken
    {
        return request()->attributes->get('api_token')
            ?? throw new LogicException('No authenticated API token on this request.');
    }
}
