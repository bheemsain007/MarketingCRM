<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Attendance session - the basis for active/idle/office-hours reporting
 * (FR-ATT-01..04, GLOSSARY §2.3).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property string|null $end_reason
 * @property string $source
 * @property int $active_seconds
 * @property int $idle_seconds
 * @property int $break_seconds
 * @property Carbon|null $break_started_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $device_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Collection<int, UserActivityPing> $pings
 */
class UserWorkSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'user_id', 'started_at', 'ended_at', 'end_reason',
        'source', 'active_seconds', 'idle_seconds', 'break_seconds',
        'break_started_at', 'ip_address', 'user_agent', 'device_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'break_started_at' => 'datetime',
            'active_seconds' => 'integer',
            'idle_seconds' => 'integer',
            'break_seconds' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pings(): HasMany
    {
        return $this->hasMany(UserActivityPing::class, 'work_session_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('ended_at');
    }

    /** Total logged-in time in seconds (GLOSSARY §2.3). */
    public function loggedInSeconds(): int
    {
        return (int) $this->started_at->diffInSeconds($this->ended_at ?? now());
    }

    public function isOnBreak(): bool
    {
        return $this->break_started_at !== null;
    }
}
