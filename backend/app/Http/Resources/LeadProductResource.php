<?php

namespace App\Http\Resources;

use App\Models\LeadProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Per-product interest on a lead (BR-PROD-01).
 *
 * @mixin LeadProduct
 */
class LeadProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'code' => $this->product->code,
                'name' => $this->product->name,
            ]),
            // Independent of the lead-level status - a lead may be negotiating
            // one product while uninterested in another.
            'interest_status' => $this->interest_status->value,
            'temperature' => $this->temperature->value,
            'score' => $this->score,
            'quoted_value' => $this->quoted_value,
            'currency' => $this->currency,
            'first_interest_at' => $this->first_interest_at?->toIso8601String(),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
        ];
    }
}
