<?php

namespace App\Models;

use App\Models\Concerns\HasRolesAndPermissions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * CRM user (telecaller, manager, admin...).
 *
 * Roles and permissions arrive in Phase 4; this model currently carries only
 * identity, activity and scoping fields.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $phone_e164
 * @property string $timezone
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property int|null $team_id
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team|null $team
 * @property-read Collection<int, Lead> $assignedLeads
 * @property-read Collection<int, Call> $calls
 * @property-read Collection<int, FollowUp> $followUps
 * @property-read Collection<int, UserWorkSession> $workSessions
 * @property-read Collection<int, Notification> $crmNotifications
 * @property-read Collection<int, Role> $roles
 *
 * Not a column: the alias `withCount(['assignedLeads as open_lead_count'])`
 * adds when LeadAssignmentService asks who can take more work (BR-ASSIGN-02).
 * @property-read int|null $open_lead_count
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRolesAndPermissions, Notifiable, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'phone_e164',
        'timezone',
        'team_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** Leads currently owned by this user. */
    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'assigned_to');
    }

    /** Attendance sessions - the basis for active/idle time (FR-ATT-01). */
    public function workSessions(): HasMany
    {
        return $this->hasMany(UserWorkSession::class);
    }

    public function crmNotifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Count used by the load-balanced assignment rule (BR-ASSIGN-02). */
    public function openLeadCount(): int
    {
        return $this->assignedLeads()
            ->whereNotIn('status', ['converted', 'lost', 'not_interested'])
            ->count();
    }
}
