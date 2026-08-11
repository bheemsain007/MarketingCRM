<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\FollowUpStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $lead_id
 * @property int|null $product_id
 * @property int|null $assigned_to
 * @property Channel $channel
 * @property Carbon $scheduled_at
 * @property FollowUpStatus $status
 * @property string|null $subject
 * @property string|null $notes
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 * @property string|null $outcome
 * @property int|null $rescheduled_from_id
 * @property bool $reminder_sent
 * @property Carbon|null $reminder_sent_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Lead|null $lead
 * @property-read Product|null $product
 * @property-read User|null $assignee
 * @property-read User|null $completedBy
 * @property-read FollowUp|null $rescheduledFrom
 */
class FollowUp extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'lead_id', 'product_id', 'assigned_to', 'channel',
        'scheduled_at', 'status', 'subject', 'notes',
        'rescheduled_from_id', 'created_by',
        'completed_at', 'completed_by', 'outcome',
        'reminder_sent', 'reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'status' => FollowUpStatus::class,
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminder_sent' => 'boolean',
            'reminder_sent_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** The follow-up this one replaced, preserving the original schedule. */
    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', FollowUpStatus::Open->value);
    }

    public function scopeDue($query)
    {
        return $query->where('status', FollowUpStatus::Open->value)->where('scheduled_at', '<=', now());
    }

    /** Past due and never completed (BR-FUP-02). */
    public function isMissed(): bool
    {
        return $this->status === FollowUpStatus::Open && $this->scheduled_at?->isPast();
    }
}
