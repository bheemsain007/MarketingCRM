<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One payment against a sale (FR-PAY-01..05, BR-PAY-01..06).
 *
 * There is no `balance` attribute and deliberately no accessor for one on this
 * model - balance belongs to the SALE, not to any single payment, and asking a
 * payment for it is the mistake that produces a different answer per instalment
 * (BR-PAY-04, see PaymentService::balanceFor()).
 *
 * `status` is outside `$fillable`. It moves only through `PaymentService`,
 * which enforces the BR-PAY-02 matrix - a status settable by a PATCH body would
 * make the matrix decorative, exactly as with lead status.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $sale_id
 * @property int $customer_id
 * @property int $lead_id
 * @property int $product_id
 * @property string $reference
 * @property string $amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property string $method
 * @property Carbon|null $due_on
 * @property Carbon|null $paid_at
 * @property string|null $gateway
 * @property string|null $gateway_payment_id
 * @property string|null $failure_reason
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Sale|null $sale
 * @property-read Customer|null $customer
 * @property-read Lead|null $lead
 * @property-read Product|null $product
 * @property-read Collection<int, PaymentStatusHistory> $history
 */
class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'sale_id', 'customer_id', 'lead_id', 'product_id',
        'reference', 'amount', 'currency', 'method',
        'due_on', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'due_on' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(PaymentStatusHistory::class);
    }

    /** Payments that count toward collected revenue (GLOSSARY §2.5). */
    public function scopeCollected($query)
    {
        return $query->whereIn('status', [
            PaymentStatus::Partial->value,
            PaymentStatus::Paid->value,
        ]);
    }
}
