<?php

namespace App\Models;

use App\Enums\ExportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A requested lead CSV export and its progress (FR-LEAD-12, SEC-PII-04).
 *
 * @property int $id
 * @property ExportStatus $status
 * @property array<string, mixed>|null $filters
 * @property string|null $disk
 * @property string|null $file_path
 * @property int|null $row_count
 * @property string|null $failure_reason
 * @property Carbon $requested_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property-read User|null $requester
 */
class LeadExport extends Model
{
    use HasFactory;

    /**
     * Status, file_path and row_count are EXCLUDED from mass assignment
     * (SEC-IN-06): they are written only by LeadExportService as the job
     * progresses, never from request input. Only the request-time fields are
     * fillable.
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'filters',
        'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExportStatus::class,
            'filters' => 'array',
            'row_count' => 'integer',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Rows whose download window has closed - what the retention sweep deletes
     * (SEC-PII-05).
     *
     * Reads the STORED `expires_at` rather than re-deriving it from
     * `completed_at` plus the current config, so shortening the retention later
     * cannot retroactively re-date exports generated under the old policy
     * (the same reasoning as `CallRecording::scopeExpired()`).
     */
    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    /** True once the generated file has been removed or never existed. */
    public function fileIsGone(): bool
    {
        return $this->file_path === null;
    }
}
