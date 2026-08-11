<?php

namespace App\Models;

use App\Enums\DataScope;
use App\Enums\RoleName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $label
 * @property string|null $description
 * @property DataScope $data_scope
 * @property bool $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Permission> $permissions
 * @property-read Collection<int, User> $users
 */
class Role extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'name', 'label', 'description', 'data_scope', 'is_system'];

    protected function casts(): array
    {
        return [
            'data_scope' => DataScope::class,
            'is_system' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role')->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user')->withTimestamps();
    }

    public function isSuperAdmin(): bool
    {
        return $this->name === RoleName::SuperAdmin->value;
    }

    public function toEnum(): ?RoleName
    {
        return RoleName::tryFrom($this->name);
    }
}
