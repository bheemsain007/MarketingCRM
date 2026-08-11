<?php

namespace App\Support;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Applies the documented list conventions to a query (API_DOCUMENTATION §4):
 *
 *   ?page=2&per_page=50
 *   ?sort=-created_at,priority
 *   ?filter[status]=interested
 *   ?filter[status][in]=new,contacted
 *   ?filter[created_at][between]=2026-01-01,2026-03-31
 *   ?include=products,assignedUser
 *
 * Every endpoint declares an allowlist. An unknown filter, sort or include
 * field returns 422 rather than being ignored.
 *
 * That choice is deliberate: a silently dropped filter on a lead list shows the
 * caller MORE data than they asked for. On a scoped endpoint - a telecaller
 * listing "my leads" - ignoring the filter is a data-exposure bug, not a
 * cosmetic one. Failing loudly also stops a frontend typo from looking like it
 * works.
 */
class QueryOptions
{
    /**
     * @param  array<int, string>  $allowedFilters
     * @param  array<int, string>  $allowedSorts
     * @param  array<int, string>  $allowedIncludes
     */
    public function __construct(
        private readonly Request $request,
        private readonly array $allowedFilters = [],
        private readonly array $allowedSorts = [],
        private readonly array $allowedIncludes = [],
    ) {}

    public function applyTo(Builder $query): Builder
    {
        $this->applyFilters($query);
        $this->applySorts($query);
        $this->applyIncludes($query);

        return $query;
    }

    public function perPage(): int
    {
        $max = (int) config('crm.api.max_per_page', 100);
        $default = (int) config('crm.api.default_per_page', 25);

        $requested = (int) $this->request->query('per_page', $default);

        if ($requested < 1) {
            return $default;
        }

        // Capped rather than rejected: an oversized page is a performance
        // problem, not a caller error worth failing the request over.
        return min($requested, $max);
    }

    private function applyFilters(Builder $query): void
    {
        $filters = $this->request->query('filter', []);

        if (! is_array($filters)) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'The filter parameter must be an array, e.g. filter[status]=new.',
            );
        }

        foreach ($filters as $field => $value) {
            $this->guardField((string) $field, $this->allowedFilters, 'filter');

            // filter[field][operator]=value
            if (is_array($value)) {
                foreach ($value as $operator => $operand) {
                    $this->applyOperator($query, (string) $field, (string) $operator, $operand);
                }

                continue;
            }

            $query->where($field, $value);
        }
    }

    private function applyOperator(Builder $query, string $field, string $operator, mixed $operand): void
    {
        $values = is_string($operand) ? explode(',', $operand) : (array) $operand;

        match ($operator) {
            'in' => $query->whereIn($field, $values),
            'not_in' => $query->whereNotIn($field, $values),
            'between' => $this->applyBetween($query, $field, $values),
            'gt' => $query->where($field, '>', $values[0]),
            'gte' => $query->where($field, '>=', $values[0]),
            'lt' => $query->where($field, '<', $values[0]),
            'lte' => $query->where($field, '<=', $values[0]),
            'null' => filter_var($values[0], FILTER_VALIDATE_BOOLEAN)
                ? $query->whereNull($field)
                : $query->whereNotNull($field),
            default => throw new ApiException(
                ErrorCode::ValidationFailed,
                sprintf('Unsupported filter operator "%s" on field "%s".', $operator, $field),
            ),
        };
    }

    /** @param array<int, mixed> $values */
    private function applyBetween(Builder $query, string $field, array $values): void
    {
        if (count($values) !== 2) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                sprintf('Filter "%s[between]" requires exactly two comma-separated values.', $field),
            );
        }

        $query->whereBetween($field, [$values[0], $values[1]]);
    }

    private function applySorts(Builder $query): void
    {
        $sort = (string) $this->request->query('sort', '');

        if ($sort === '') {
            return;
        }

        foreach (explode(',', $sort) as $field) {
            $field = trim($field);

            if ($field === '') {
                continue;
            }

            // A leading "-" means descending, per the documented convention.
            $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
            $field = ltrim($field, '-');

            $this->guardField($field, $this->allowedSorts, 'sort');

            $query->orderBy($field, $direction);
        }
    }

    private function applyIncludes(Builder $query): void
    {
        $include = (string) $this->request->query('include', '');

        if ($include === '') {
            return;
        }

        $relations = array_filter(array_map('trim', explode(',', $include)));

        foreach ($relations as $relation) {
            $this->guardField($relation, $this->allowedIncludes, 'include');
        }

        // Eager-loaded here so API Resources never trigger queries themselves
        // (the N+1 rule in ARCHITECTURE §2).
        $query->with($relations);
    }

    /** @param array<int, string> $allowed */
    private function guardField(string $field, array $allowed, string $parameter): void
    {
        if (in_array($field, $allowed, true)) {
            return;
        }

        throw new ApiException(
            ErrorCode::ValidationFailed,
            sprintf('The %s field "%s" is not supported on this endpoint.', $parameter, $field),
            errors: [[
                'field' => $parameter,
                'code' => ErrorCode::ValidationFailed->value,
                'message' => sprintf('Unsupported %s field "%s".', $parameter, $field),
            ]],
            context: ['allowed' => array_values($allowed)],
        );
    }
}
