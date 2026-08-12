<?php

namespace App\Http\Resources;

use App\Enums\CampaignSkipReason;
use App\Models\CampaignRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignRecipient
 */
class CampaignRecipientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $reason = $this->skip_reason !== null
            ? CampaignSkipReason::tryFrom($this->skip_reason)
            : null;

        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'lead_id' => $this->lead_id,
            'message_id' => $this->message_id,
            'status' => $this->status,

            'skip_reason' => $this->skip_reason,
            // The code is for filtering, the label is for reading. A report
            // that says "no_contact_detail" makes its reader do the translating.
            'skip_reason_label' => $reason?->label(),
            // Whether a re-run might reach this lead: a cap lifts tomorrow, a
            // missing phone number does not appear by itself.
            'skip_is_transient' => $reason?->isTransient(),

            'processed_at' => $this->processed_at?->toIso8601String(),
            'lead' => new LeadResource($this->whenLoaded('lead')),
            'message' => new MessageResource($this->whenLoaded('message')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
