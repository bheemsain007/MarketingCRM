<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The user-facing lead timeline (FR-LEAD-09). Not an audit log - see AuditLog
 * for the compliance-grade trail (SEC-AUD-04).
 *
 * @property int $id
 * @property int $lead_id
 * @property int|null $user_id
 * @property string $activity_type
 * @property string $title
 * @property string|null $description
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array|null $meta
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead|null $lead
 * @property-read User|null $user
 */
class LeadActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id', 'user_id', 'activity_type', 'title', 'description',
        'subject_type', 'subject_id', 'meta', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject()
    {
        return $this->morphTo();
    }
}
