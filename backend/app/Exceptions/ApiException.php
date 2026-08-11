<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\Support\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Base class for domain failures that map to a known API error.
 *
 * Services throw these instead of returning error flags, so a business rule
 * violation cannot be silently ignored by a caller that forgot to check a
 * return value. The handler renders them through the standard envelope.
 *
 * Implements HttpExceptionInterface so the same exception also carries a real
 * status on WEB routes (Phase 8). Services are shared between the API and the
 * Blade pages, and the exception handler only wraps `api/*` in the envelope -
 * without this, a permission failure on a web page rendered as a 500 instead
 * of a 403. The API path is unaffected: the handler matches ApiException
 * before it ever reaches the generic HTTP branch.
 */
class ApiException extends Exception implements HttpExceptionInterface
{
    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly array $errors = [],
        public readonly mixed $context = null,
    ) {
        parent::__construct($message ?? $errorCode->defaultMessage(), $errorCode->httpStatus());
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error(
            $this->errorCode,
            $this->getMessage(),
            $this->errors,
            $this->context,
        );
    }

    public function getStatusCode(): int
    {
        return $this->errorCode->httpStatus();
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [];
    }
}
