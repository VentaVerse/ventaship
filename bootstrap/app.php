<?php

use App\Api\ApiError;
use App\Http\Middleware\AuthenticateClient;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\LogApiRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo('/');
        $middleware->api(prepend: [LogApiRequest::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every answer under api/* uses the one error body; nothing else (stack traces, HTML) ever leaves.
        $exceptions->render(function (ApiError $e, Request $request) {
            return $request->is('api/*') ? $e->toResponse() : null;
        });
        $exceptions->render(function (ValidationException $e, Request $request) {
            return $request->is('api/*')
                ? (new ApiError(422, 'invalid_request', 'The request is invalid.', false, $e->errors()))->toResponse()
                : null;
        });
        // A missing model is already a NotFoundHttpException by the time it is rendered.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return $request->is('api/*') ? (new ApiError(404, 'not_found', 'Not found'))->toResponse() : null;
        });
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            return $request->is('api/*')
                ? (new ApiError(405, 'method_not_allowed', 'Method not allowed', false, [], $e->getHeaders()))->toResponse()
                : null;
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $request->attributes->set('api_error', 'rate_limited');

            return (new ApiError(429, 'rate_limited', 'Too many requests', true, [], $e->getHeaders()))->toResponse();
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $status = $e->getStatusCode();

            return (new ApiError($status, 'http_' . $status, $status >= 500 ? 'Server error' : 'Request refused', $status >= 500, [], $e->getHeaders()))->toResponse();
        });
        // Anything unexpected: reported as usual, answered without detail.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || $e instanceof HttpResponseException) {
                return null;
            }

            return (new ApiError(500, 'server_error', 'Server error', true))->toResponse();
        });
    })->create();
