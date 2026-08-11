<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * INTERNAL notification to a CRM user (BR-NOTIF-01).
 *
 * Never route this through DncService: suppression protects leads, not staff.
 * Named `Notification` in App\Models to avoid confusion with Laravel's
 * Illuminate\Notifications\Notification, which this does not extend.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $type
 * @property string $title
 * @property string|null $body
 * @property string $channel
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $action_url
 * @property string $status
 * @property string|null $failure_reason
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $sent_at
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'user_id', 'type', 'title', 'body', 'channel',
        'reference_type', 'reference_id', 'action_url',
        'status', 'failure_reason', 'scheduled_for', 'sent_at', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference()
    {
        return $this->morphTo();
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function markAsRead(): void
    {
        $this->update(['read_at' => now()]);
    }
}
