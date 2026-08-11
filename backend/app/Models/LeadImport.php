<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An uploaded lead file and its progress (FR-LEAD-07).
 *
 * @property ImportStatus $status
 * @property-read Collection<int, LeadImportRow> $rows
 * @property-read User|null $uploader
 */
class LeadImport extends Model
{
    use HasFactory;

    /**
     * Counters and status are EXCLUDED from mass assignment: they are written
     * by LeadImportService via atomic increments, never from request input
     * (SEC-IN-06). A client that could set `imported_rows` could forge a
     * clean-looking report over a failed import.
     */
    protected $fillable = [
        'tenant_id',
        'original_filename',
        'disk',
        'stored_path',
        'file_size',
        'column_map',
        'options',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'column_map' => 'array',
            'options' => 'array',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'imported_rows' => 'integer',
            'duplicate_rows' => 'integer',
            'invalid_rows' => 'integer',
            'file_size' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    public function rows(): HasMany
    {
        return $this->hasMany(LeadImportRow::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Percentage of rows processed, 0-100. */
    public function progress(): int
    {
        if ($this->total_rows < 1) {
            return $this->status->isFinished() ? 100 : 0;
        }

        return (int) floor(min($this->processed_rows, $this->total_rows) / $this->total_rows * 100);
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    /** True once the uploaded file has been purged (SEC-PII-05). */
    public function fileWasPurged(): bool
    {
        return $this->stored_path === null;
    }
}
