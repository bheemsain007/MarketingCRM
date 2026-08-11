<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Assignment history and the source for conversion attribution (GLOSSARY §2.6).
 *
 * @property int $id
 * @property int $lead_id
 * @property int $assigned_to
 * @property int|null $assigned_by
 * @property string $assignment_method
 * @property Carbon $assigned_at
 * @property Carbon|null $unassigned_at
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead|null $lead
 * @property-read User|null $assignee
 * @property-read User|null $assigner
 */
class LeadAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id', 'assigned_to', 'assigned_by', 'assignment_method',
        'assigned_at', 'unassigned_at', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'unassigned_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** The assignment currently in force. */
    public function scopeCurrent($query)
    {
        return $query->whereNull('unassigned_at');
    }
}
