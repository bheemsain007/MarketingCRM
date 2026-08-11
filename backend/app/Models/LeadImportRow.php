<?php

namespace App\Models;

use App\Enums\ImportRowStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The outcome of one row of an import (FR-LEAD-07).
 *
 * @property ImportRowStatus $status
 * @property-read LeadImport|null $import
 * @property-read Lead|null $lead
 */
class LeadImportRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_import_id',
        'row_number',
        'status',
        'lead_id',
        'message',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportRowStatus::class,
            'row_number' => 'integer',
            'data' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(LeadImport::class, 'lead_import_id');
    }

    /**
     * For an imported row this is the lead created; for a duplicate it is the
     * EXISTING lead that already held the number, which is the more useful of
     * the two to link to from a report.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
