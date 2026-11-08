<?php

use App\Api\ApiError;
use App\Http\Middleware\AuthenticateClient;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\LogApiRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'client.auth' => AuthenticateClient::class,
            'idempotent' => EnforceIdempotency::class,
        ]);
        $middleware->api(prepend: [LogApiRequest::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ApiError $e, Request $request) {
            return $request->is('api/*') ? $e->toResponse() : null;
        });
        $exceptions->render(function (ValidationException $e, Request $request) {
            return $request->is('api/*')
                ? (new ApiError(422, 'invalid_request', 'The request is invalid.', false, $e->errors()))->toResponse()
                : null;
        });
    })->create();
