<?php

namespace App\Support;

use App\Enums\ErrorCode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single source of the API response envelope (NFR-03).
 *
 *     { "success": bool, "message": string, "data": object|null, "errors": [] }
 *
 * EVERY response goes through here, including error paths from the exception
 * handler. Nothing else in the application may hand-build a JSON body - that is
 * how contracts drift, and both the Web CRM and the Flutter app depend on this
 * shape being identical everywhere.
 */
class ApiResponse
{
    /** 200 - successful read or update. */
    public static function success(mixed $data = null, string $message = 'OK', int $status = Response::HTTP_OK): JsonResponse
    {
        return self::build(true, $message, $data, [], $status);
    }

    /** 201 - resource created. */
    public static function created(mixed $data = null, string $message = 'Created successfully.'): JsonResponse
    {
        return self::build(true, $message, $data, [], Response::HTTP_CREATED);
    }

    /**
     * 202 - accepted for async processing.
     *
     * Used by bulk endpoints (campaign start, CSV import) which must never block
     * an HTTP request (NFR-06). The payload carries a reference the client polls.
     */
    public static function accepted(mixed $data = null, string $message = 'Request accepted for processing.'): JsonResponse
    {
        return self::build(true, $message, $data, [], Response::HTTP_ACCEPTED);
    }

    /** 204 - success with no body. */
    public static function noContent(): JsonResponse
    {
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Paginated list.
     *
     * Pagination metadata always sits at data.meta, never at the envelope root,
     * so a client can parse any list response the same way.
     */
    public static function paginated(
        LengthAwarePaginator|ResourceCollection $paginator,
        string $message = 'OK',
        ?array $extraMeta = null,
    ): JsonResponse {
        if ($paginator instanceof ResourceCollection) {
            $resource = $paginator->resource;
            $items = $paginator->resolve();
        } else {
            $resource = $paginator;
            $items = $paginator->items();
        }

        $meta = [
            'current_page' => $resource->currentPage(),
            'per_page' => $resource->perPage(),
            'total' => $resource->total(),
            'last_page' => $resource->lastPage(),
        ];

        return self::build(true, $message, [
            'items' => $items,
            'meta' => $extraMeta ? array_merge($meta, $extraMeta) : $meta,
        ], [], Response::HTTP_OK);
    }

    /**
     * Any error. The HTTP status comes from the ErrorCode itself so a given code
     * can never be returned with an inconsistent status.
     *
     * @param  array<int, array<string, mixed>>  $errors
     */
    public static function error(
        ErrorCode $code,
        ?string $message = null,
        array $errors = [],
        mixed $data = null,
        ?int $status = null,
    ): JsonResponse {
        // Always surface the machine-readable code, even when the caller passes
        // no field-level errors, so clients can branch reliably.
        if ($errors === []) {
            $errors = [[
                'code' => $code->value,
                'message' => $message ?? $code->defaultMessage(),
            ]];
        }

        return self::build(
            false,
            $message ?? $code->defaultMessage(),
            $data,
            $errors,
            $status ?? $code->httpStatus(),
        );
    }

    /**
     * 422 with per-field detail, shaped from a Laravel validator bag.
     *
     * @param  array<string, array<int, string>>  $failures
     */
    public static function validationFailed(array $failures, ?string $message = null): JsonResponse
    {
        $errors = [];

        foreach ($failures as $field => $messages) {
            foreach ((array) $messages as $text) {
                $errors[] = [
                    'field' => $field,
                    'code' => ErrorCode::ValidationFailed->value,
                    'message' => $text,
                ];
            }
        }

        return self::build(
            false,
            $message ?? ErrorCode::ValidationFailed->defaultMessage(),
            null,
            $errors,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    private static function build(bool $success, string $message, mixed $data, array $errors, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => $success,
            'message' => $message,
            // Cast to object so an empty payload serialises as {} rather than
            // [] - a client typed against an object breaks on an array.
            'data' => $data === [] ? (object) [] : $data,
            'errors' => $errors,
        ], $status);
    }
}
