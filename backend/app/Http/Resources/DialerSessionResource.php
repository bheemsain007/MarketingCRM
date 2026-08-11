<?php

namespace App\Http\Resources;

use App\Enums\QueueItemState;
use App\Models\AutoDialerSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AutoDialerSession
 */
class DialerSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'is_active' => $this->state->isActive(),

            'filters' => $this->filters,

            'totals' => [
                'queued' => $this->total_items,
                'dialled' => $this->dialled_items,
                'skipped' => $this->skipped_items,
                'remaining' => $this->remaining(),
            ],
            'progress' => $this->progress(),

            // Present only when the caller asked for the queue - a 200-row
            // list has no business riding along with a progress poll.
            'queue' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'position' => $item->position,
                'lead_id' => $item->lead_id,
                'state' => $item->state->value,
                'state_label' => $item->state->label(),
                // FR-CALL-07: a skip is only useful if it says why.
                'skip_reason' => $item->skip_reason?->value,
                'skip_reason_label' => $item->skip_reason?->label(),
                'is_temporary_skip' => $item->skip_reason?->isTemporary() ?? false,
                'call_id' => $item->call_id,
            ])),

            'current_lead_id' => $this->whenLoaded(
                'items',
                fn () => $this->items->firstWhere('state', QueueItemState::Dialling)?->lead_id,
            ),

            'started_at' => $this->started_at?->toIso8601String(),
            'paused_at' => $this->paused_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'end_reason' => $this->end_reason,
        ];
    }
}
