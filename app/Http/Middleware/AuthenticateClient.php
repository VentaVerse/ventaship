<?php

namespace App\Http\Middleware;

use App\Api\ApiError;
use App\Api\Signature;
use App\Models\ApiKey;
use App\Models\RequestSignature;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The marker interface puts this in the framework's auth slot of the middleware priority list, so it always runs
 * before throttle: (limits are keyed by the authenticated API key) and before route model binding.
 */
class AuthenticateClient implements AuthenticatesRequests
{
    private const WINDOW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            $this->unauthorized($request, 'missing_bearer');
        }

        $key = ApiKey::query()->with('clientApp')->where('key_hash', hash('sha256', $token))->first();
        if ($key === null || $key->clientApp === null) {
            $this->unauthorized($request, 'unknown_key');
        }
        $request->attributes->set('api_key', $key);
        $request->attributes->set('client_app', $key->clientApp);

        if ($key->revoked_at !== null) {
            $this->unauthorized($request, 'revoked');
        }
        if ($key->expires_at !== null && $key->expires_at->isPast()) {
            $this->unauthorized($request, 'expired');
        }

        $ts = $request->header('X-Timestamp');
        if (! is_string($ts) || ! preg_match('/^\d{1,12}$/', $ts) || abs(time() - (int) $ts) > self::WINDOW_SECONDS) {
            $this->unauthorized($request, 'stale_timestamp');
        }

        $given = $request->header('X-Signature');
        $expected = Signature::sign($key->signing_secret, (int) $ts, $request->getMethod(), $request->getRequestUri(), $request->getContent());
        if (! is_string($given) || ! hash_equals($expected, $given)) {
            $this->unauthorized($request, 'bad_signature');
        }

        if ($key->clientApp->status !== 'active') {
            $request->attributes->set('api_error', 'app_disabled');
            throw new ApiError(403, 'app_disabled', 'This application is disabled.');
        }

        // The unique index makes the claim atomic: a second use of the same signature inserts nothing.
        $claimed = RequestSignature::query()->insertOrIgnore([
            'signature_hash' => hash('sha256', $given),
            'created_at' => now(),
        ]);
        if ($claimed === 0) {
            $this->unauthorized($request, 'replay');
        }

        ApiKey::query()->whereKey($key->getKey())->update(['last_used_at' => now()]);

        return $next($request);
    }

    private function unauthorized(Request $request, string $reason): never
    {
        $request->attributes->set('api_error', $reason);

        throw new ApiError(401, 'unauthorized', 'Unauthorized');
    }
}
