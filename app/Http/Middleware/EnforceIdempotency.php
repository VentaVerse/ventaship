<?php

namespace App\Http\Middleware;

use App\Api\ApiError;
use App\Api\ClientContext;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes every write safe to retry. A repeat under the same key gets the stored
 * answer; the key is scoped to the calling client app. Server failures and
 * "not now" refusals are not stored, so the same key can be retried.
 * Must run after AuthenticateClient.
 */
class EnforceIdempotency
{
    private const WRITES = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const MAX_KEY_LENGTH = 100;

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getMethod(), self::WRITES, true)) {
            return $next($request);
        }

        $key = (string) $request->header('Idempotency-Key', '');
        if ($key === '' || strlen($key) > self::MAX_KEY_LENGTH) {
            throw new ApiError(422, 'invalid_request', 'The request is invalid.', false, [
                'Idempotency-Key' => ['The Idempotency-Key header is required and must be at most '.self::MAX_KEY_LENGTH.' characters.'],
            ]);
        }

        $appId = ClientContext::app()->getKey();
        $hash = hash('sha256', $request->getMethod().' '.$request->getRequestUri()."\n".$request->getContent());

        try {
            $row = IdempotencyKey::query()->create([
                'client_app_id' => $appId,
                'key' => $key,
                'request_hash' => $hash,
                'status' => 'processing',
            ]);
        } catch (UniqueConstraintViolationException) {
            // The insert is the arbiter: whoever lost only reads.
            return $this->answerForExisting($appId, $key, $hash);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $row->delete();
            throw $e;
        }

        // The router has already rendered any ApiError into this response; its body says whether it is retryable.
        if ($response->getStatusCode() >= 500 || $this->isRetryable($response)) {
            $row->delete();

            return $response;
        }

        $this->store($row, $response);

        return $response;
    }

    private function isRetryable(Response $response): bool
    {
        if ($response->getStatusCode() < 400) {
            return false;
        }
        $body = json_decode((string) $response->getContent(), true);

        return is_array($body) && ($body['retryable'] ?? false) === true;
    }

    private function store(IdempotencyKey $row, Response $response): void
    {
        $row->update([
            'status' => 'completed',
            'response' => json_encode([
                'status' => $response->getStatusCode(),
                'content_type' => $response->headers->get('Content-Type', 'application/json'),
                'body' => (string) $response->getContent(),
            ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }

    private function answerForExisting(int $appId, string $key, string $hash): Response
    {
        $existing = IdempotencyKey::query()->where('client_app_id', $appId)->where('key', $key)->first();

        // The owner released the key between our insert and this read.
        if ($existing === null) {
            throw new ApiError(409, 'idempotency_in_progress', 'This request is already being processed.', true);
        }

        if (! hash_equals($existing->request_hash, $hash)) {
            throw new ApiError(422, 'idempotency_key_reused', 'Idempotency-Key was already used with a different request');
        }

        $stored = $existing->status === 'completed' && $existing->response !== null
            ? json_decode($existing->response, true)
            : null;
        if (! is_array($stored)) {
            throw new ApiError(409, 'idempotency_in_progress', 'This request is already being processed.', true);
        }

        return response($stored['body'], $stored['status'], [
            'Content-Type' => $stored['content_type'],
            'Idempotent-Replayed' => 'true',
        ]);
    }
}
