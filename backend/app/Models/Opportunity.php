<?php

namespace App\Models;

use App\Enums\LostReason;
use App\Enums\OpportunityStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A deal in progress against a lead (FR-SALE-01/02).
 *
 * Exists before a Customer does. BR-CUST-01 keeps lead and customer as separate
 * records, and the customer is created only when a sale is actually made.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $lead_id
 * @property int|null $customer_id
 * @property string $title
 * @property OpportunityStatus $status
 * @property string $value
 * @property string $currency
 * @property Carbon|null $expected_close_on
 * @property int|null $owner_id
 * @property LostReason|null $lost_reason
 * @property string|null $lost_notes
 * @property Carbon|null $closed_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Lead|null $lead
 * @property-read Customer|null $customer
 * @property-read User|null $owner
 * @property-read Collection<int, OpportunityProduct> $products
 * @property-read Collection<int, Quotation> $quotations
 * @property-read Sale|null $sale
 */
class Opportunity extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'lead_id', 'customer_id', 'title', 'status',
        'value', 'currency', 'expected_close_on', 'owner_id',
        'lost_reason', 'lost_notes', 'closed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => OpportunityStatus::class,
            'lost_reason' => LostReason::class,
            'value' => 'decimal:2',
            'expected_close_on' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(OpportunityProduct::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function sale(): HasOne
    {
        return $this->hasOne(Sale::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', OpportunityStatus::Open->value);
    }
}
