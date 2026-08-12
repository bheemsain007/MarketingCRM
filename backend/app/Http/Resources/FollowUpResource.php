<?php

namespace App\Http\Resources;

use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FollowUp
 */
class FollowUpResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // The model casts `status`, so this is always the enum. The defensive
        // branch that used to be here was written before the cast existed and
        // static analysis has since proved it unreachable - dead code that
        // implies a case which cannot occur is worse than none.
        $status = $this->status;

        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'lead' => $this->whenLoaded('lead', fn () => $this->lead?->only(['id', 'name'])),
            'product' => $this->whenLoaded('product', fn () => $this->product?->only(['id', 'name'])),

            'channel' => $this->channel->value,
            'subject' => $this->subject,
            'notes' => $this->notes,

            'status' => $status->value,
            'status_label' => $status->label(),

            // Derived rather than stored: a follow-up becomes overdue by the
            // passage of time, and a stored flag would be wrong between ticks
            // of the scheduler.
            'is_overdue' => $status === FollowUpStatus::Open
                && $this->scheduled_at !== null
                && $this->scheduled_at->isPast(),

            'scheduled_at' => $this->scheduled_at->toIso8601String(),
            'assigned_to' => $this->whenLoaded('assignee', fn () => $this->assignee?->only(['id', 'name'])),

            'completed_at' => $this->completed_at?->toIso8601String(),
            'completed_by' => $this->whenLoaded('completedBy', fn () => $this->completedBy?->only(['id', 'name'])),
            'outcome' => $this->outcome,

            // The chain that makes reschedule history readable (FR-FUP-04).
            'rescheduled_from_id' => $this->rescheduled_from_id,

            'reminder_sent' => $this->reminder_sent,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
