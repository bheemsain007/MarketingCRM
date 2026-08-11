<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line on a quotation.
 *
 * `description` is copied, not joined - it records what was quoted, which a
 * product renamed two years later must not retroactively change.
 *
 * @property int $id
 * @property int $quotation_id
 * @property int|null $product_id
 * @property string $description
 * @property int $quantity
 * @property string $unit_price
 * @property string $line_total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Quotation|null $quotation
 * @property-read Product|null $product
 */
class QuotationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id', 'product_id', 'description', 'quantity', 'unit_price', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
