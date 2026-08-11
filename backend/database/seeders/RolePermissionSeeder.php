<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Roles and permissions (ROLE-01..06).
 *
 * Idempotent and safe to re-run in production: it syncs the permission set for
 * each role without touching which USERS hold which roles. Re-running after a
 * phase adds that phase's new permissions to the roles that should have them,
 * and never silently revokes a role from a person.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionEnum::cases() as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission->value],
                [
                    'module' => $permission->module(),
                    'is_audited' => $permission->isAudited(),
                ],
            );
        }

        foreach (RoleName::cases() as $roleName) {
            $role = Role::updateOrCreate(
                ['tenant_id' => 0, 'name' => $roleName->value],
                [
                    'label' => $roleName->label(),
                    'description' => $roleName->description(),
                    'data_scope' => $roleName->dataScope()->value,
                    'is_system' => true,
                ],
            );

            $permissionIds = Permission::whereIn(
                'name',
                array_map(fn (PermissionEnum $p) => $p->value, PermissionEnum::defaultsFor($roleName)),
            )->pluck('id');

            // sync() on the role's permissions only - role_user is untouched.
            $role->permissions()->sync($permissionIds);
        }
    }
}
