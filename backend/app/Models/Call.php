<?php

namespace App\Models;

use App\Enums\CallStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $lead_id
 * @property int|null $user_id
 * @property int|null $product_id
 * @property string $direction
 * @property CallStatus|null $status
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property int $duration_seconds
 * @property string|null $notes
 * @property string $dial_source
 * @property int|null $auto_dialer_session_id
 * @property int|null $campaign_id
 * @property int|null $follow_up_id
 * @property string|null $external_call_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead|null $lead
 * @property-read User|null $user
 * @property-read Product|null $product
 * @property-read CallRecording|null $recording
 */
class Call extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'lead_id', 'user_id', 'product_id', 'direction', 'status',
        'started_at', 'ended_at', 'duration_seconds', 'notes',
        'dial_source', 'auto_dialer_session_id', 'campaign_id', 'follow_up_id',
        'external_call_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => CallStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function recording(): HasOne
    {
        return $this->hasOne(CallRecording::class);
    }

    /**
     * Only connected calls count toward talk time and Average Call Duration
     * (GLOSSARY §2.2) - dividing by all attempts would penalise an agent given
     * a list of unreachable numbers.
     */
    public function scopeConnected($query)
    {
        return $query->where('status', CallStatus::Connected->value);
    }

    public function scopeInPeriod($query, $from, $to)
    {
        return $query->whereBetween('started_at', [$from, $to]);
    }
}
