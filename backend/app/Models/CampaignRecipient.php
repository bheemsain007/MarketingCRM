<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per targeted lead. Ineligible leads get status = skipped WITH a
 * reason - they are never simply left out (BR-DNC-05, FR-CAMP-03).
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $lead_id
 * @property int|null $message_id
 * @property string $status
 * @property string|null $skip_reason
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Campaign|null $campaign
 * @property-read Lead|null $lead
 * @property-read Message|null $message
 */
class CampaignRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id', 'lead_id', 'message_id', 'status', 'skip_reason', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function scopeSkipped($query)
    {
        return $query->where('status', 'skipped');
    }
}
