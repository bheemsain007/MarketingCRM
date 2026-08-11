<?php

namespace App\Models\Concerns;

use App\Enums\DataScope;
use App\Enums\Permission as PermissionEnum;
use App\Enums\RoleName;
use App\Models\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Role, permission and data-scope resolution for User (SEC-AUTHZ-01..04).
 *
 * Permissions are cached per request, not across requests: a role change must
 * take effect on the user's very next request, not after a cache TTL. Access
 * control that lags behind a revocation is a security bug.
 */
trait HasRolesAndPermissions
{
    private ?Collection $cachedPermissions = null;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
    }

    public function hasRole(RoleName|string $role): bool
    {
        $name = $role instanceof RoleName ? $role->value : $role;

        // loadMissing rather than a bare relation read: permission checks run
        // from services and jobs where the model may not have been eager-loaded,
        // and preventLazyLoading would turn that into an exception.
        return $this->loadMissing('roles')->roles->contains('name', $name);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(RoleName::SuperAdmin);
    }

    /** @return Collection<int, string> */
    public function permissionNames(): Collection
    {
        return $this->cachedPermissions ??= $this->loadMissing('roles.permissions')
            ->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values();
    }

    public function hasPermission(PermissionEnum|string $permission): bool
    {
        // Super Admin bypasses checks entirely; every other role, Admin
        // included, is bound by its granted set so a seeder gap surfaces as a
        // 403 rather than silently granting access.
        if ($this->isSuperAdmin()) {
            return true;
        }

        $name = $permission instanceof PermissionEnum ? $permission->value : $permission;

        return $this->permissionNames()->contains($name);
    }

    /** @param array<int, PermissionEnum|string> $permissions */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The broadest scope across the user's roles.
     *
     * Defaults to Own when the user holds no roles at all - an unconfigured
     * account sees nothing rather than everything.
     */
    public function dataScope(): DataScope
    {
        return $this->loadMissing('roles')->roles
            ->map(fn (Role $role) => $role->data_scope)
            ->filter()
            ->reduce(
                fn (?DataScope $carry, DataScope $scope) => $carry === null || $scope->isBroaderThan($carry) ? $scope : $carry,
            ) ?? DataScope::Own;
    }

    /**
     * Constrain a query to what this user may see (SEC-AUTHZ-03).
     *
     * Applied server-side on every scoped listing. It is never derived from a
     * client-supplied filter - that would let a caller widen their own scope.
     *
     * @param  string  $ownerColumn  the column holding the owning user's id
     * @param  string  $ownerRelation  the relation to that user - Team scope
     *                                 resolves the owner's team through it, and
     *                                 not every scoped model calls it
     *                                 `assignedUser` (calls use `user`)
     */
    public function applyDataScope(
        Builder $query,
        string $ownerColumn = 'assigned_to',
        string $ownerRelation = 'assignedUser',
    ): Builder {
        return match ($this->dataScope()) {
            DataScope::All => $query,

            /*
             * Includes the unassigned pool - a manager must be able to see
             * leads with no owner in order to assign them. Mirrored in
             * LeadPolicy::withinScope(); the two must agree or a record hidden
             * from the list stays reachable by id.
             *
             * The `team_id !== null` guard is load-bearing, not defensive.
             * Laravel rewrites `where('team_id', null)` as `WHERE team_id IS
             * NULL`, so without it a Team-scoped user who has no team matched
             * every record owned by any other teamless user - which, in an
             * install where nobody has been put in a team yet, is every record
             * in the system (SEC-AUTHZ-03).
             *
             * `LeadPolicy::withinScope()` already had this guard, so the two
             * disagreed: the list leaked rows the policy then refused by id.
             */
            DataScope::Team => $query->where(
                fn (Builder $q) => $q
                    ->whereNull($ownerColumn)
                    ->when(
                        $this->team_id !== null,
                        fn (Builder $inner) => $inner->orWhereHas(
                            $ownerRelation,
                            fn (Builder $owner) => $owner->where('team_id', $this->team_id),
                        ),
                    ),
            ),

            // A user with no team would otherwise match every teamless record,
            // so Own falls back to strictly their own rows.
            DataScope::Own => $query->where($ownerColumn, $this->getKey()),
        };
    }

    /** Clears the per-request permission cache after a role change. */
    public function forgetPermissionCache(): void
    {
        $this->cachedPermissions = null;
        $this->unsetRelation('roles');
    }
}
