<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\DncReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A suppression record - the source of truth for contactability (BR-DNC-01).
 *
 * `channel = null` means the entry blocks every channel. A set channel blocks
 * only that one (e.g. an SMS opt-out leaves email contactable).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $lead_id
 * @property string|null $phone_e164
 * @property string|null $email
 * @property DncReason $reason
 * @property Channel|null $channel
 * @property string $source
 * @property string|null $note
 * @property bool $active
 * @property int|null $created_by
 * @property int|null $removed_by
 * @property Carbon|null $removed_at
 * @property string|null $removal_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead|null $lead
 * @property-read User|null $createdBy
 * @property-read User|null $removedBy
 */
class DncEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'phone_e164',
        'email',
        'reason',
        'channel',
        'source',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'reason' => DncReason::class,
            'channel' => Channel::class,
            'active' => 'boolean',
            'removed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Whether this entry blocks the given channel.
     *
     * A null `channel` on the entry means "all channels", so the decision falls
     * back to what the reason itself blocks (BR-DNC-02).
     */
    public function blocks(Channel $channel): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->channel !== null) {
            return $this->channel === $channel;
        }

        return $this->reason->blocks($channel);
    }
}
