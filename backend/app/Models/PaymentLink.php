<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A gateway-hosted invitation to pay (FR-PAY-02, T-34).
 *
 * A link is NOT money. Its `status` describes the invitation - issued, paid,
 * lapsed - and says nothing about the ledger, which lives on the payment it
 * points at and moves only through `PaymentService`'s BR-PAY-02 matrix. Reading
 * `payment_links.status = paid` as "collected" would be a second, unguarded
 * definition of collected revenue; `Payment::scopeCollected()` remains the only
 * one (GLOSSARY §2.5).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $payment_id
 * @property string $gateway
 * @property string $gateway_link_id
 * @property string $short_url
 * @property string $amount
 * @property string $currency
 * @property string $status
 * @property Carbon|null $expires_at
 * @property Carbon|null $paid_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Payment|null $payment
 */
class PaymentLink extends Model
{
    use HasFactory;

    public const STATUS_CREATED = 'created';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'tenant_id', 'payment_id', 'gateway', 'gateway_link_id', 'short_url',
        'amount', 'currency', 'status', 'expires_at', 'paid_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
