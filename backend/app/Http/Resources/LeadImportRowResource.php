<?php

namespace App\Http\Resources;

use App\Models\LeadImportRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the per-row import report (FR-LEAD-07).
 *
 * @mixin LeadImportRow
 */
class LeadImportRowResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'row_number' => $this->row_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'message' => $this->message,

            // For an imported row this is the new lead; for a duplicate it is
            // the lead that already held the number, so the operator can open
            // it directly instead of searching.
            'lead_id' => $this->lead_id,

            // Present only for rejected rows, and cleared with the source file
            // once retention expires (SEC-PII-05).
            'data' => $this->data,
        ];
    }
}
