<?php

namespace App\Models;

use App\Enums\DialerSkipReason;
use App\Enums\QueueItemState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lead in a dialling queue (FR-CALL-06/07).
 *
 * @property QueueItemState $state
 * @property ?DialerSkipReason $skip_reason
 * @property-read AutoDialerSession|null $session
 * @property-read Lead|null $lead
 * @property-read Call|null $call
 */
class AutoDialerQueueItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'auto_dialer_session_id', 'lead_id', 'position',
        'state', 'skip_reason', 'call_id', 'claimed_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => QueueItemState::class,
            'skip_reason' => DialerSkipReason::class,
            'position' => 'integer',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AutoDialerSession::class, 'auto_dialer_session_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
