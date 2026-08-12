<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'campaign_id' => $this->campaign_id,

            'channel' => $this->channel->value,
            'channel_label' => $this->channel->label(),
            'direction' => $this->direction,

            'recipient' => $this->recipient,
            'subject' => $this->subject,
            'body' => $this->body,

            'status' => $this->status,
            'failure_reason' => $this->failure_reason,

            // Present only on a skip, and always populated when it is - a skip
            // is never silent (BR-DNC-05).
            'skip_reason' => $this->skip_reason,

            // "log" means no provider was configured and nothing actually left
            // the building. Surfaced so "why did nobody receive this?" is
            // answerable without reading application logs.
            'provider' => $this->provider,

            'template' => $this->whenLoaded('template', fn () => $this->template?->only(['id', 'name', 'code'])),
            'sent_by' => $this->whenLoaded('user', fn () => $this->user?->only(['id', 'name'])),

            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
