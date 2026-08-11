<?php

namespace App\Http\Resources;

use App\Models\Call;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Call
 */
class CallResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'direction' => $this->direction,

            // Null means dialled, outcome not yet reported - not "unknown
            // status" and not a missing value to paper over.
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'is_pending' => $this->status === null,
            'is_connected' => $this->status?->isConnected() ?? false,

            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            // Zero on anything that did not connect: an attempt is not talk
            // time (GLOSSARY §2.2).
            'duration_seconds' => $this->duration_seconds,

            'notes' => $this->notes,
            'dial_source' => $this->dial_source,
            'follow_up_id' => $this->follow_up_id,

            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),

            'lead' => $this->whenLoaded('lead', fn () => $this->lead ? [
                'id' => $this->lead->id,
                'name' => $this->lead->name,
                'phone' => $this->lead->phone_e164,
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
