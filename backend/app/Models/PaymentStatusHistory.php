<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only record of every payment status change (BR-PAY-02).
 *
 * No `updated_at`, and nothing in the application updates a row here. A
 * disputed refund six months later is unanswerable if the only record is the
 * current value of a column.
 *
 * @property int $id
 * @property int $payment_id
 * @property PaymentStatus|null $from_status
 * @property PaymentStatus $to_status
 * @property string|null $amount_at_change
 * @property int|null $changed_by
 * @property string $source
 * @property string|null $reason
 * @property Carbon $created_at
 * @property-read Payment|null $payment
 * @property-read User|null $changedBy
 */
class PaymentStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'payment_status_history';

    protected $fillable = [
        'payment_id', 'from_status', 'to_status', 'amount_at_change',
        'changed_by', 'source', 'reason', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => PaymentStatus::class,
            'to_status' => PaymentStatus::class,
            'amount_at_change' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
