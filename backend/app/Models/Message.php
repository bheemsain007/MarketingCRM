<?php

namespace App\Models;

use App\Enums\Channel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Every message across every channel, inbound and outbound.
 *
 * `idempotency_key` is unique in the database - that constraint, not an
 * application check, is what prevents a retried queue job double-sending.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $lead_id
 * @property int|null $campaign_id
 * @property int|null $template_id
 * @property int|null $user_id
 * @property Channel $channel
 * @property string $direction
 * @property string|null $provider
 * @property string|null $provider_message_id
 * @property string|null $idempotency_key
 * @property string $recipient
 * @property string|null $subject
 * @property string|null $body
 * @property array|null $media
 * @property string $status
 * @property string|null $failure_reason
 * @property string|null $skip_reason
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 * @property Carbon|null $failed_at
 * @property string|null $cost
 * @property string $currency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead|null $lead
 * @property-read Campaign|null $campaign
 * @property-read Template|null $template
 * @property-read User|null $user
 */
class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'lead_id', 'campaign_id', 'template_id', 'user_id',
        'channel', 'direction', 'provider', 'provider_message_id',
        'idempotency_key', 'recipient', 'subject', 'body', 'media',
        'status', 'failure_reason', 'skip_reason',
        'scheduled_at', 'sent_at', 'delivered_at', 'read_at', 'failed_at',
        'cost', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'media' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'cost' => 'decimal:4',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** Who sent it. Null for campaign and system sends. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeDelivered($query)
    {
        return $query->whereIn('status', ['delivered', 'read', 'replied']);
    }

    /** Counted toward the frequency cap (BR-CAMP-04). */
    public function scopeSentToLeadOnChannel($query, int $leadId, Channel $channel, $since)
    {
        return $query->where('lead_id', $leadId)
            ->where('channel', $channel->value)
            ->where('direction', 'outbound')
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', $since);
    }
}
