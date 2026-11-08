<?php

namespace App\Api;

use App\Models\ApiKey;
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

    public static function key(): ApiKey
    {
        return request()->attributes->get('api_key')
            ?? throw new LogicException('No authenticated API key on this request.');
    }
}
