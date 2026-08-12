<?php

namespace App\Http\Resources;

use App\Models\Opportunity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Opportunity
 */
class OpportunityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'lead' => $this->whenLoaded('lead', fn () => $this->lead?->only(['id', 'name'])),
            'customer_id' => $this->customer_id,

            'title' => $this->title,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            // Always the sum of the lines - never set directly by a caller.
            'value' => $this->value,
            'currency' => $this->currency,
            'expected_close_on' => $this->expected_close_on?->toDateString(),

            'owner' => $this->whenLoaded('owner', fn () => $this->owner?->only(['id', 'name'])),

            'products' => $this->whenLoaded('products', fn () => $this->products->map(fn ($line) => [
                'product_id' => $line->product_id,
                'name' => $line->product?->name,
                'quantity' => $line->quantity,
                // Snapshotted when added, not the product's current price.
                'unit_price' => $line->unit_price,
                'line_total' => $line->line_total,
            ])),

            'lost_reason' => $this->lost_reason?->value,
            'lost_reason_label' => $this->lost_reason?->label(),
            'lost_notes' => $this->lost_notes,
            'closed_at' => $this->closed_at?->toIso8601String(),

            'sale' => $this->whenLoaded('sale', fn () => $this->sale ? [
                'id' => $this->sale->id,
                'reference' => $this->sale->reference,
                'amount' => $this->sale->amount,
                'sold_at' => $this->sale->sold_at->toIso8601String(),
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
