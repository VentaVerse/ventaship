<?php

namespace App\Api;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class ApiError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
        public readonly array $errors = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json([
            'error' => $this->getMessage(),
            'code' => $this->errorCode,
            'retryable' => $this->retryable,
            'errors' => $this->errors === [] ? (object) [] : $this->errors,
        ], $this->status, $this->headers);
    }
}
