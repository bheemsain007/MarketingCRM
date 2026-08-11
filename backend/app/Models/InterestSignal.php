<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\InterestSignalType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One scored event in a lead's history (BR-INT-03, BR-SCORE-01).
 *
 * Append-only in practice: nothing in the application updates a signal. The
 * score is recomputed from these rows, so editing one would rewrite history
 * rather than correct it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $lead_id
 * @property int|null $product_id
 * @property InterestSignalType $type
 * @property Channel|null $channel
 * @property string $source
 * @property string|null $evidence_type
 * @property int|null $evidence_id
 * @property string|null $excerpt
 * @property string|null $confidence
 * @property bool $acted_on
 * @property string $points_awarded
 * @property int|null $user_id
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead|null $lead
 * @property-read Product|null $product
 * @property-read User|null $user
 * @property-read Model|null $evidence
 */
class InterestSignal extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'lead_id', 'product_id', 'type', 'channel', 'source',
        'evidence_type', 'evidence_id', 'excerpt', 'confidence', 'acted_on',
        'points_awarded', 'user_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => InterestSignalType::class,
            'channel' => Channel::class,
            'confidence' => 'decimal:3',
            'acted_on' => 'boolean',
            'points_awarded' => 'decimal:2',
            'occurred_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The call, message or follow-up this signal came from (BR-INT-03). */
    public function evidence(): MorphTo
    {
        return $this->morphTo();
    }
}
