<?php

namespace App\Http\Requests\Leads;

use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Accepts the same filter/search shape as the lead list endpoint (FR-LEAD-12),
 * so an export always matches what the requester was looking at on screen.
 */
class StoreLeadExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('export', Lead::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filter' => ['nullable', 'array'],
            'q' => ['nullable', 'string', 'max:190'],
            'with_archived' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The filter shape stored on the export row and re-applied by the queued
     * job. Only these three keys - `sort` and `include` are not part of what
     * an export accepts (LeadExportService::ALLOWED_FILTERS's own docblock
     * explains why), so they are not carried through even if present.
     *
     * @return array<string, mixed>
     */
    public function exportFilters(): array
    {
        return array_filter([
            'filter' => $this->input('filter'),
            'q' => $this->input('q'),
            'with_archived' => $this->boolean('with_archived') ? true : null,
        ], fn ($value) => $value !== null);
    }
}
