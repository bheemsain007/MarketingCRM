<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Raw activity signal. A gap longer than IDLE_THRESHOLD_MINUTES between
 * consecutive pings counts as idle time.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $work_session_id
 * @property string $action_type
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property Carbon $occurred_at
 * @property-read User|null $user
 * @property-read UserWorkSession|null $session
 */
class UserActivityPing extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'work_session_id', 'action_type',
        'reference_type', 'reference_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(UserWorkSession::class, 'work_session_id');
    }
}
