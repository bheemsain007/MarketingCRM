<?php

namespace App\Http\Resources;

use App\Models\DncEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DncEntry
 */
class DncEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Nullable: a number can be suppressed before any lead exists for
            // it - an inbound STOP from a number nobody has imported yet.
            'lead' => $this->whenLoaded('lead', fn () => $this->lead ? [
                'id' => $this->lead->id,
                'name' => $this->lead->name,
            ] : null),

            'phone' => $this->phone_e164,
            'email' => $this->email,

            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),

            // null means every channel this REASON blocks (BR-DNC-02) - which
            // is not the same as "everything", so the client must not render a
            // missing channel as an absolute block.
            'channel' => $this->channel?->value,
            'channel_label' => $this->channel?->label(),
            'blocked_channels' => collect($this->channel !== null
                ? [$this->channel]
                : $this->reason->blockedChannels())
                ->map(fn ($channel) => $channel->value)
                ->values(),

            'source' => $this->source,
            'note' => $this->note,

            'active' => $this->active,

            // Surfaced so the UI can warn before lifting an explicit refusal.
            // It is NOT an authority check - see T-50.
            'requires_elevated_removal' => $this->reason->requiresElevatedRemoval(),

            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->only(['id', 'name'])),
            'created_at' => $this->created_at?->toIso8601String(),

            'removed_by' => $this->whenLoaded('removedBy', fn () => $this->removedBy?->only(['id', 'name'])),
            'removed_at' => $this->removed_at?->toIso8601String(),
            'removal_reason' => $this->removal_reason,
        ];
    }
}
