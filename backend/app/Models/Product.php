<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $delivery_type
 * @property string $base_price
 * @property string $currency
 * @property bool $is_active
 * @property int $sort_order
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, LeadProduct> $leadProducts
 * @property-read Collection<int, Lead> $leads
 */
class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'description', 'delivery_type',
        'base_price', 'currency', 'is_active', 'sort_order',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function leadProducts(): HasMany
    {
        return $this->hasMany(LeadProduct::class);
    }

    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'lead_products')
            ->withPivot(['interest_status', 'temperature', 'score'])
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
