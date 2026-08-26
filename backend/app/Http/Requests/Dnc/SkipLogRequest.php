<?php

namespace App\Http\Requests\Dnc;

use App\Enums\Channel;
use App\Enums\DncReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query parameters for the skip log (BR-DNC-05).
 *
 * The `array:` rule names the only filters this endpoint accepts, so an unknown
 * one is a 422 rather than being ignored - the same choice QueryOptions makes,
 * and for the same reason: a silently dropped filter on a scoped list shows the
 * caller MORE than they asked for.
 */
class SkipLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // The route's permission middleware is the gate.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],

            // Lead name or phone. Not an email search: the log lists the person,
            // not the address a particular send would have used.
            'q' => ['nullable', 'string', 'max:100'],

            'filter' => ['nullable', 'array:reason,dnc_reason,channel,source'],

            // Free-form on purpose - skip reasons come from three writers
            // (campaign, dialer, BR-DNC-03's post-queue window) and no single
            // enum holds them all.
            'filter.reason' => ['nullable', 'string', 'max:100'],

            // Why the lead is on the list, which IS a closed set.
            'filter.dnc_reason' => ['nullable', Rule::enum(DncReason::class)],

            'filter.channel' => ['nullable', Rule::enum(Channel::class)],
            'filter.source' => ['nullable', Rule::in(['manual', 'campaign', 'dialer'])],
        ];
    }

    /**
     * The filters actually supplied, blank values dropped.
     *
     * @return array<string, string>
     */
    public function filters(): array
    {
        $filters = (array) $this->query('filter', []);

        return array_filter(
            $filters,
            fn (mixed $value): bool => is_string($value) && $value !== '',
        );
    }
}
