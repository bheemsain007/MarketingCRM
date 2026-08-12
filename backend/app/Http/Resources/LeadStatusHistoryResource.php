<?php

namespace App\Http\Resources;

use App\Enums\StatusSource;
use App\Models\LeadStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in the append-only status audit (BR-STAT-03, FR-STAT-03).
 *
 * @mixin LeadStatusHistory
 */
class LeadStatusHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $source = StatusSource::tryFrom((string) $this->source_channel);

        return [
            'id' => $this->id,

            // Null on the lead's first entry - there was no previous status.
            'from' => $this->from_status?->value,
            'from_label' => $this->from_status?->label(),
            'to' => $this->to_status->value,
            'to_label' => $this->to_status->label(),

            'source' => $this->source_channel,
            'source_label' => $source?->label(),
            // A null actor means the system moved it, not that the actor is
            // unknown - the distinction matters for attribution.
            'is_automatic' => $this->changed_by === null,

            'reason' => $this->reason,

            'changed_by' => $this->whenLoaded('changedBy', fn () => $this->changedBy ? [
                'id' => $this->changedBy->id,
                'name' => $this->changedBy->name,
            ] : null),

            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
