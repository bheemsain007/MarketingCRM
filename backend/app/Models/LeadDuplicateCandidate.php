<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pair of leads that may be the same person (BR-DUP-03).
 *
 * Always ordered so `lead_id < duplicate_lead_id` - the pair is a question, and
 * asking it twice from opposite ends produces two rows a reviewer must answer
 * separately.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $lead_id
 * @property int $duplicate_lead_id
 * @property string $match_type
 * @property string|null $match_value
 * @property string $status
 * @property string|null $resolution_note
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead|null $lead
 * @property-read Lead|null $duplicate
 * @property-read User|null $resolver
 */
class LeadDuplicateCandidate extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'lead_id', 'duplicate_lead_id', 'match_type', 'match_value',
        'status', 'resolution_note', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function duplicate(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'duplicate_lead_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
