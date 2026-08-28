<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the compliance trail (SEC-AUD-03).
 *
 * Everything the requirement asks an entry to record is surfaced, including
 * `old_values`/`new_values` - the before/after is the whole reason a
 * compliance question can be answered at all, and holding it back would leave a
 * reader knowing only that something changed.
 *
 * `actor` is null for events with no signed-in user: a failed login, a webhook,
 * a scheduled advance. That is a fact about the event, not missing data, so it
 * is rendered rather than hidden.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'description' => $this->description,

            'actor' => $this->whenLoaded('user', fn () => $this->user?->only(['id', 'name'])),

            // The subject, split into the class basename a screen can label and
            // the raw type a caller needs to filter on.
            'subject_type' => $this->auditable_type,
            'subject_label' => $this->auditable_type !== null
                ? class_basename($this->auditable_type)
                : null,
            'subject_id' => $this->auditable_id,

            'old_values' => $this->old_values,
            'new_values' => $this->new_values,

            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,

            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
