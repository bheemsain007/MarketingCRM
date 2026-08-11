<?php

namespace App\Models;

use App\Enums\Channel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 * @property Channel $channel
 * @property int|null $template_id
 * @property int|null $product_id
 * @property string $status
 * @property array|null $audience_filters
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $started_at
 * @property Carbon|null $paused_at
 * @property Carbon|null $completed_at
 * @property string|null $batch_id
 * @property int $total_targeted
 * @property int $total_queued
 * @property int $total_sent
 * @property int $total_delivered
 * @property int $total_failed
 * @property int $total_skipped
 * @property string $cost
 * @property string $currency
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Template|null $template
 * @property-read Collection<int, CampaignRecipient> $recipients
 * @property-read Collection<int, Message> $messages
 */
class Campaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'description', 'channel', 'template_id', 'product_id',
        'status', 'audience_filters', 'scheduled_at', 'batch_id',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'audience_filters' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'cost' => 'decimal:4',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** Stopped is terminal - a stopped campaign is cloned, never resumed. */
    public function isStopped(): bool
    {
        return $this->status === 'stopped';
    }

    public function canResume(): bool
    {
        return $this->status === 'paused';
    }
}
