<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A closed deal (FR-SALE-01, BR-CUST-01).
 *
 * Recording one creates the Customer and is what lets the lead reach
 * `Converted`. `sold_by` is stored rather than derived from the lead's current
 * owner: the lead may be reassigned afterwards, and conversion credit must not
 * move with it (T-24, GLOSSARY §2.6).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $opportunity_id
 * @property int $customer_id
 * @property int $lead_id
 * @property int|null $quotation_id
 * @property string $reference
 * @property string $amount
 * @property string $currency
 * @property int|null $sold_by
 * @property Carbon $sold_at
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Opportunity|null $opportunity
 * @property-read Customer|null $customer
 * @property-read Lead|null $lead
 * @property-read Quotation|null $quotation
 * @property-read Collection<int, Payment> $payments
 * @property-read User|null $soldBy
 */
class Sale extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'opportunity_id', 'customer_id', 'lead_id', 'quotation_id',
        'reference', 'amount', 'currency', 'sold_by', 'sold_at', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'sold_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }
}
