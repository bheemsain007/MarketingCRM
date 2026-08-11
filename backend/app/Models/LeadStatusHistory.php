<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only status audit (BR-STAT-03).
 *
 * UPDATED_AT is disabled: these rows are written once and never modified.
 *
 * @property int $id
 * @property int $lead_id
 * @property LeadStatus|null $from_status
 * @property LeadStatus $to_status
 * @property int|null $changed_by
 * @property string $source_channel
 * @property string|null $reason
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property Carbon $created_at
 * @property-read Lead|null $lead
 * @property-read User|null $changedBy
 */
class LeadStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'lead_status_history';

    public const UPDATED_AT = null;

    protected $fillable = [
        'lead_id', 'from_status', 'to_status', 'changed_by',
        'source_channel', 'reason', 'reference_type', 'reference_id',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => LeadStatus::class,
            'to_status' => LeadStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function reference()
    {
        return $this->morphTo();
    }
}
