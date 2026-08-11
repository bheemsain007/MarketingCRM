<?php

namespace App\Http\Resources;

use App\Models\LeadImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeadImport
 */
class LeadImportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->original_filename,
            'file_size' => $this->file_size,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_finished' => $this->status->isFinished(),
            'progress' => $this->progress(),

            // The four numbers the operator actually reads (FR-LEAD-07).
            'totals' => [
                'rows' => $this->total_rows,
                'processed' => $this->processed_rows,
                'imported' => $this->imported_rows,
                'duplicates' => $this->duplicate_rows,
                'invalid' => $this->invalid_rows,
            ],

            // Lets a client show "we read your 'Mobile No' column as the phone".
            'column_map' => $this->column_map,
            'options' => $this->options,

            'failure_reason' => $this->failure_reason,

            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader ? [
                'id' => $this->uploader->id,
                'name' => $this->uploader->name,
            ] : null),

            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
