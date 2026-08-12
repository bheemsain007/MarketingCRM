<?php

namespace App\Models;

use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $phone_e164
 * @property LeadStatus $status
 * @property LeadTemperature $temperature
 * @property-read LeadSource|null $source
 * @property-read Campaign|null $campaign
 * @property-read User|null $assignedUser
 * @property-read User|null $creator
 * @property-read Collection<int, LeadProduct> $leadProducts
 * @property-read Collection<int, Product> $products
 * @property-read Collection<int, Tag> $tags
 * @property-read Collection<int, LeadNote> $notes
 * @property-read Collection<int, LeadStatusHistory> $statusHistory
 * @property-read Collection<int, LeadAssignment> $assignments
 * @property-read Collection<int, LeadActivity> $activities
 * @property-read Collection<int, Call> $calls
 * @property-read Collection<int, Message> $messages
 * @property-read Collection<int, InterestSignal> $interestSignals
 * @property-read Collection<int, Opportunity> $opportunities
 * @property-read Collection<int, Sale> $sales
 * @property-read Collection<int, FollowUp> $followUps
 * @property-read Collection<int, DncEntry> $dncEntries
 * @property-read Collection<int, CallRecording> $recordings
 */
class Lead extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Business-critical fields are deliberately EXCLUDED from mass assignment
     * (SEC-IN-06): status, temperature, score, is_suppressed and assigned_to
     * change only through their services, never from request input.
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'company',
        'phone_e164',
        'phone_raw',
        'alt_phone_e164',
        'email',
        'city',
        'state',
        'country',
        'timezone',
        'priority',
        'lead_source_id',
        'campaign_id',
        'created_by',
        'updated_by',
    ];

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'merged_into_id');
    }

    protected function casts(): array
    {
        return [
            'merged_at' => 'datetime',
            'status' => LeadStatus::class,
            'temperature' => LeadTemperature::class,
            'score' => 'integer',
            'priority' => 'integer',
            'is_suppressed' => 'boolean',
            'assigned_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'last_engagement_at' => 'datetime',
        ];
    }

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Per-product interest - independent state per product (BR-PROD-01). */
    public function leadProducts(): HasMany
    {
        return $this->hasMany(LeadProduct::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'lead_products')
            ->withPivot(['interest_status', 'temperature', 'score', 'quoted_value'])
            ->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'lead_tag')
            ->withPivot('tagged_by')
            ->withTimestamps();
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class)->latest();
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(LeadStatusHistory::class)->latest();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LeadAssignment::class)->latest('assigned_at');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest('occurred_at');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class)->latest('started_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->latest();
    }

    public function interestSignals(): HasMany
    {
        return $this->hasMany(InterestSignal::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    /** Suppression records - the source of truth for contactability. */
    public function dncEntries(): HasMany
    {
        return $this->hasMany(DncEntry::class);
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(CallRecording::class);
    }

    // -----------------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------------

    /**
     * Fast pre-filter using the denormalised flag.
     *
     * WARNING: this is a list-performance convenience, NOT an authorisation
     * check. Never decide whether to contact a lead from this scope - always
     * ask DncService, which reads dnc_entries (BR-DNC-01).
     */
    public function scopeNotSuppressed($query)
    {
        return $query->where('is_suppressed', false);
    }

    public function scopeStatus($query, LeadStatus|string $status)
    {
        return $query->where('status', $status instanceof LeadStatus ? $status->value : $status);
    }

    public function scopeTemperature($query, LeadTemperature|string $temperature)
    {
        return $query->where('temperature', $temperature instanceof LeadTemperature ? $temperature->value : $temperature);
    }

    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    /** Leads with no contact attempt at all - the leakage metric (GLOSSARY §2.9). */
    public function scopeUntouched($query)
    {
        return $query->whereNull('last_contacted_at');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Days since the last inbound signal; null if never engaged. */
    public function daysSinceEngagement(): ?int
    {
        return $this->last_engagement_at?->diffInDays(now());
    }

    public function isArchived(): bool
    {
        return $this->trashed();
    }
}
