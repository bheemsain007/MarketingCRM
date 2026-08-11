<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One product line on an opportunity (FR-SALE-02).
 *
 * `unit_price` is snapshotted when the line is added. A product's list price
 * changing later must not silently rewrite the value of an open deal.
 *
 * @property int $id
 * @property int $opportunity_id
 * @property int $product_id
 * @property int $quantity
 * @property string $unit_price
 * @property string $line_total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Opportunity|null $opportunity
 * @property-read Product|null $product
 */
class OpportunityProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'opportunity_id', 'product_id', 'quantity', 'unit_price', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
