<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A priced offer at a point in time (FR-SALE-03/04).
 *
 * Items are COPIED from the opportunity rather than referenced, so the figures
 * on the customer's copy and the figures here cannot drift apart.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $opportunity_id
 * @property string $number
 * @property QuotationStatus $status
 * @property string $subtotal
 * @property string $discount_percent
 * @property string $discount_amount
 * @property string $total
 * @property string $currency
 * @property Carbon|null $valid_until
 * @property string|null $notes
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $rejection_reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Opportunity|null $opportunity
 * @property-read Collection<int, QuotationItem> $items
 * @property-read User|null $approvedBy
 */
class Quotation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'opportunity_id', 'number', 'status',
        'subtotal', 'discount_percent', 'discount_amount', 'total', 'currency',
        'valid_until', 'notes', 'approved_by', 'approved_at', 'rejection_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'subtotal' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'valid_until' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
