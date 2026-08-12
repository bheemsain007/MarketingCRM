<?php

namespace App\Http\Resources;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class CampaignResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'channel' => $this->channel->value,
            'channel_label' => $this->channel->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            // The UI should not have to reimplement the lifecycle to know which
            // buttons to show - the server owns the transition rules.
            'can_start' => $this->status->canTransitionTo(CampaignStatus::Running),
            'can_pause' => $this->status->canTransitionTo(CampaignStatus::Paused),
            'can_stop' => $this->status->canTransitionTo(CampaignStatus::Stopped),
            'is_final' => $this->status->isTerminal(),

            'template_id' => $this->template_id,
            'product_id' => $this->product_id,
            'audience_filters' => $this->audience_filters ?? [],

            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'paused_at' => $this->paused_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),

            'counts' => [
                'targeted' => $this->total_targeted,
                'queued' => $this->total_queued,
                'sent' => $this->total_sent,
                'delivered' => $this->total_delivered,
                'failed' => $this->total_failed,
                'skipped' => $this->total_skipped,
            ],

            'recipient_count' => $this->whenCounted('recipients'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
