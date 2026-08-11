<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'delivery_type' => $this->delivery_type,
            'base_price' => $this->base_price,
            'currency' => $this->currency,
            'is_active' => $this->is_active,
            'is_archived' => $this->trashed(),
            'sort_order' => $this->sort_order,
            // Counted only when the caller asked for it, so a plain list does
            // not run an aggregate per row.
            'lead_interest_count' => $this->whenCounted('leadProducts'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
