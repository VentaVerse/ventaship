<?php

namespace App\Http\Middleware;

use App\Api\ApiError;
use App\Models\ApiToken;
use App\Models\ClientApp;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:sanctum. The caller must be a client app holding one of its own tokens; a disabled app is refused.
 *
 * The marker interface puts this in the framework's auth slot of the middleware priority list, so it always runs
 * before throttle: (limits are keyed by the token) and before route model binding.
 */
class AuthenticateClient implements AuthenticatesRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $app = $request->user('sanctum');
        $token = $app?->currentAccessToken();
        if (! $app instanceof ClientApp || ! $token instanceof ApiToken) {
            $request->attributes->set('api_error', 'unauthorized');

            throw new ApiError(401, 'unauthorized', 'Unauthorized');
        }
        $request->attributes->set('api_token', $token);
        $request->attributes->set('client_app', $app);

        if ($app->status !== 'active') {
            $request->attributes->set('api_error', 'app_disabled');
            throw new ApiError(403, 'app_disabled', 'This application is disabled.');
        }

        return $next($request);
    }
}
