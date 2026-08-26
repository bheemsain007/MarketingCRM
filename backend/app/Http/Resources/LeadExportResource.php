<?php

namespace App\Http\Resources;

use App\Models\LeadExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeadExport
 */
class LeadExportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_finished' => $this->status->isFinished(),

            // What was asked for, so a client can show "exporting: status =
            // Interested" without re-deriving it from the lead list state.
            'filters' => $this->filters,

            'row_count' => $this->row_count,
            'failure_reason' => $this->failure_reason,

            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester ? [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ] : null),

            'requested_at' => $this->requested_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
