<?php

namespace App\Http\Resources;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tag
 */
class TagResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'color' => $this->color,
            'is_system' => $this->is_system,

            /*
             * Resolved server-side rather than left to the client.
             *
             * A screen that decides for itself which tags are editable will
             * eventually decide differently from TagPolicy, and the version
             * that is wrong is always the one drawing the buttons.
             */
            'is_editable' => ! $this->is_system,

            // Counted only when the caller asked, so a plain list does not run
            // an aggregate per row.
            'leads_count' => $this->whenCounted('leads'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
