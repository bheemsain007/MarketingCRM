<?php

namespace App\Http\Resources;

use App\Models\LeadDuplicateCandidate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeadDuplicateCandidate
 */
class LeadDuplicateCandidateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'match_type' => $this->match_type,
            'match_value' => $this->match_value,
            'status' => $this->status,

            'resolution_note' => $this->resolution_note,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolver' => $this->whenLoaded('resolver', fn () => [
                'id' => $this->resolver?->id,
                'name' => $this->resolver?->name,
            ]),

            /*
             * Both sides are summarised inline rather than left as ids. The
             * decision this row exists for is "are these the same person?", and
             * it cannot be made without seeing both - a queue that shows two
             * numbers sends the reviewer to two other screens before they can
             * answer.
             */
            'lead' => $this->summarise($this->lead),
            'duplicate' => $this->summarise($this->duplicate),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function summarise(mixed $lead): ?array
    {
        if ($lead === null) {
            return null;
        }

        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'phone_e164' => $lead->phone_e164,
            'email' => $lead->email,
            'company' => $lead->company,
            'city' => $lead->city,
            'status' => $lead->status?->value,
            'status_label' => $lead->status?->label(),
            // The two facts that most often settle it: an older record with
            // real activity is usually the one to keep.
            'is_suppressed' => (bool) $lead->is_suppressed,
            'created_at' => $lead->created_at?->toIso8601String(),
        ];
    }
}
