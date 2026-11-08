<?php

namespace App\Http\Middleware;

use App\Models\ApiRequest;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('api_started_at', hrtime(true));
        $request->attributes->set('request_id', 'req_' . Str::lower((string) Str::ulid()));

        $response = $next($request);
        $response->headers->set('X-Request-Id', $request->attributes->get('request_id'));

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $started = $request->attributes->get('api_started_at');

        ApiRequest::query()->create([
            'client_app_id' => $request->attributes->get('client_app')?->getKey(),
            'api_key_id' => $request->attributes->get('api_key')?->getKey(),
            'method' => $request->getMethod(),
            'path' => Str::limit($request->getPathInfo(), 255, ''),
            'status' => $response->getStatusCode(),
            'duration_ms' => $started === null ? null : (int) ((hrtime(true) - $started) / 1_000_000),
            'idempotency_key' => Str::limit((string) $request->header('Idempotency-Key'), 128, '') ?: null,
            'error' => $request->attributes->get('api_error'),
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }
}
