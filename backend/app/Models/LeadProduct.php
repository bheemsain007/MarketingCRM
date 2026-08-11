<?php

namespace App\Models;

use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-product interest on a lead (BR-PROD-01).
 *
 * Updating one row must never affect another product's row for the same lead -
 * that independence is the whole point of this table.
 *
 * @property int $id
 * @property int $lead_id
 * @property int $product_id
 * @property LeadStatus $interest_status
 * @property LeadTemperature $temperature
 * @property int $score
 * @property string|null $quoted_value
 * @property string $currency
 * @property string|null $notes
 * @property Carbon|null $first_interest_at
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead|null $lead
 * @property-read Product|null $product
 */
class LeadProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id', 'product_id', 'interest_status', 'temperature',
        'score', 'quoted_value', 'currency', 'notes',
        'first_interest_at', 'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'interest_status' => LeadStatus::class,
            'temperature' => LeadTemperature::class,
            'score' => 'integer',
            'quoted_value' => 'decimal:2',
            'first_interest_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
