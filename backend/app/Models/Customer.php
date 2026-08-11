<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A lead that has bought at least once (BR-CUST-01).
 *
 * Created at the first Sale. The originating Lead is NOT converted in place -
 * it survives with status = converted, keeping its call history, campaign
 * attribution and source intact for reporting.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $origin_lead_id
 * @property string $name
 * @property string|null $company
 * @property string $phone_e164
 * @property string|null $email
 * @property string|null $billing_name
 * @property string|null $billing_address
 * @property string|null $city
 * @property string|null $state
 * @property string $country
 * @property string|null $postal_code
 * @property string|null $tax_id
 * @property string $status
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Lead|null $originLead
 * @property-read Collection<int, Lead> $leads
 */
class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'origin_lead_id', 'name', 'company', 'phone_e164', 'email',
        'billing_name', 'billing_address', 'city', 'state', 'country',
        'postal_code', 'tax_id', 'status', 'created_by', 'updated_by',
    ];

    /** The lead that produced the first sale. */
    public function originLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'origin_lead_id');
    }

    /** Every lead that contributed - one customer may arrive via several. */
    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'customer_leads')->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
