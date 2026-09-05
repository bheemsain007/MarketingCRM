<?php

namespace Tests\Browser\Concerns;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;

/**
 * Every journey below needs a user holding a specific role before it can even
 * reach a page (route middleware checks `permission:*`, ADR-A). Shared here
 * rather than copied per test class, the way `tests/Feature/Web/WebCrmTest`
 * does it for the Feature suite (T-42).
 */
trait CreatesRoleUsers
{
    private function userWithRole(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }
}
