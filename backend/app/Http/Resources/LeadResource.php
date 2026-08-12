<?php

namespace App\Http\Resources;

use App\Models\Lead;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Lead
 */
class LeadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'company' => $this->company,

            // Storage stays E.164; the formatted value is for display only.
            'phone' => $this->phone_e164,
            'phone_formatted' => PhoneNumber::format($this->phone_e164),
            'alt_phone' => $this->alt_phone_e164,
            'email' => $this->email,

            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'timezone' => $this->timezone,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'temperature' => $this->temperature->value,
            'score' => $this->score,
            'priority' => $this->priority,

            // Denormalised fast-filter flag. The client may use it to show a
            // badge; it is NOT the authority on contactability - DncService is
            // (BR-DNC-01).
            'is_suppressed' => $this->is_suppressed,
            'is_archived' => $this->trashed(),

            'source' => $this->whenLoaded('source', fn () => [
                'id' => $this->source->id,
                'name' => $this->source->name,
                'category' => $this->source->category,
            ]),

            'assigned_to' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser ? [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
            ] : null),
            'assigned_at' => $this->assigned_at?->toIso8601String(),

            'products' => LeadProductResource::collection($this->whenLoaded('leadProducts')),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
            ])),

            'notes_count' => $this->whenCounted('notes'),
            'calls_count' => $this->whenCounted('calls'),

            'last_contacted_at' => $this->last_contacted_at?->toIso8601String(),
            'last_engagement_at' => $this->last_engagement_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
