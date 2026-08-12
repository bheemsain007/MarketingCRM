<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A call recording. Never exposed by path - access is via short-lived signed
 * URLs to authorised roles only, and every access is logged (BR-REC-01).
 *
 * @property int $id
 * @property int $call_id
 * @property int $lead_id
 * @property int|null $user_id
 * @property string $storage_disk
 * @property string|null $storage_path
 * @property int|null $file_size_bytes
 * @property int|null $duration_seconds
 * @property string|null $mime_type
 * @property string|null $checksum
 * @property string $upload_status
 * @property string|null $failure_reason
 * @property int $upload_attempts
 * @property Carbon|null $device_captured_at
 * @property Carbon|null $uploaded_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Call|null $call
 * @property-read Lead|null $lead
 * @property-read User|null $user
 */
class CallRecording extends Model
{
    use HasFactory;

    protected $fillable = [
        'call_id', 'lead_id', 'user_id', 'storage_disk', 'storage_path',
        'file_size_bytes', 'duration_seconds', 'mime_type', 'checksum',
        'upload_status', 'failure_reason', 'upload_attempts',
        'device_captured_at', 'uploaded_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'upload_attempts' => 'integer',
            'device_captured_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Rows the retention purge job should delete (BR-REC-02). */
    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    public function scopePendingUpload($query)
    {
        return $query->whereIn('upload_status', ['pending', 'uploading', 'failed']);
    }

    /**
     * The device/OS blocked recording. Not an error - the call itself still
     * logged normally (BR-REC-03).
     */
    public function isUnavailable(): bool
    {
        return $this->upload_status === 'unavailable';
    }

    /**
     * Whether there is audio to play right now.
     *
     * False covers three different histories - never captured, still uploading,
     * and purged on retention (BR-REC-02) - which the API reports separately via
     * `upload_status`. Playback only cares that there are no bytes to serve.
     */
    public function isPlayable(): bool
    {
        return $this->upload_status === 'uploaded' && $this->storage_path !== null;
    }

    /** Audio deleted by the retention sweep; the row survives as the record. */
    public function isPurged(): bool
    {
        return $this->upload_status === 'purged';
    }
}
