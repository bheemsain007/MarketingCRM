<?php

namespace App\Http\Resources;

use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Template
 */
class TemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'channel' => $this->channel->value,
            'channel_label' => $this->channel->label(),
            'subject' => $this->subject,
            'body' => $this->body,
            'variables' => $this->variables,
            'media' => $this->media,
            'provider' => $this->provider,
            'provider_template_id' => $this->provider_template_id,
            'approval_status' => $this->approval_status,
            'rejection_reason' => $this->rejection_reason,
            'is_active' => $this->is_active,

            // Whether a send may actually use it: active AND approved, which on
            // WhatsApp and RCS means approved by the provider rather than by
            // anyone here (T-31). Exposed because "saved" and "sendable" are
            // different states and an editor must be able to show which one it
            // is looking at.
            'is_sendable' => $this->isSendable(),

            // Counted only when the caller asked for it, so a plain list does
            // not run two aggregates per row. These are also the reason a
            // template is retired rather than deleted.
            'campaign_count' => $this->whenCounted('campaigns'),
            'message_count' => $this->whenCounted('messages'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
