<?php

namespace App\Services\Users;

use App\Enums\ErrorCode;
use App\Enums\RoleName;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * User administration (T-51, ROLE-01..07, SEC-AUTHZ-05).
 *
 * Role assignment is deliberately NOT part of `update()`. SEC-AUTHZ-05 makes it
 * Super Admin only and forbids anyone granting themselves a role, so it is a
 * separate method behind a separate permission - folding it into a general edit
 * would mean an Admin who can rename a user can also promote one.
 */
class UserService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): User
    {
        return DB::transaction(function () use ($data, $actor) {
            $user = User::create([
                'tenant_id' => config('crm.default_tenant_id'),
                'name' => $data['name'],
                'email' => mb_strtolower(trim($data['email'])),
                'password' => Hash::make($data['password']),
                'phone_e164' => $data['phone_e164'] ?? null,
                'timezone' => $data['timezone'] ?? config('crm.timezone', 'Asia/Kolkata'),
                'team_id' => $data['team_id'] ?? null,
                // `is_active` is deliberately outside mass assignment, so that
                // no request body can re-enable a disabled account. The column
                // defaults to true; enable() and disable() are the only things
                // that write it.
            ]);

            // Created with NO roles. An account with no role can sign in and do
            // nothing, which is the safe default - the alternative is guessing
            // at someone's authority, and the guess that gets shipped is
            // always the generous one.
            $this->audit($actor, 'user_created', $user);

            return $user->fresh();
        });
    }

    /**
     * Profile fields only. Roles are not reachable from here (SEC-AUTHZ-05).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data, ?User $actor = null): User
    {
        return DB::transaction(function () use ($user, $data, $actor) {
            $user->fill(array_filter([
                'name' => $data['name'] ?? null,
                'phone_e164' => $data['phone_e164'] ?? null,
                'timezone' => $data['timezone'] ?? null,
            ], fn ($v) => $v !== null));

            if (array_key_exists('team_id', $data)) {
                $user->team_id = $data['team_id'];
            }

            if (! empty($data['email'])) {
                $user->email = mb_strtolower(trim($data['email']));
            }

            $user->save();

            $this->audit($actor, 'user_updated', $user);

            return $user->fresh();
        });
    }

    /**
     * Replaces a user's roles (SEC-AUTHZ-05).
     *
     * Two guards, both non-negotiable:
     *
     *   - **Nobody may change their own roles**, including a Super Admin. The
     *     rule exists so that compromising one account is not the same as
     *     compromising every permission, and an exception for the most powerful
     *     account would defeat it entirely.
     *   - **Only `roles.manage` may call this at all**, enforced on the route.
     *     An Admin can create and rename users but cannot promote one.
     *
     * @param  array<int, string>  $roleNames
     *
     * @throws ApiException
     */
    public function setRoles(User $user, array $roleNames, User $actor): User
    {
        if ($user->is($actor)) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'You cannot change your own roles.',
            );
        }

        $roles = Role::whereIn('name', $roleNames)->get();

        if ($roles->count() !== count(array_unique($roleNames))) {
            throw new ApiException(ErrorCode::ValidationFailed, 'One or more roles do not exist.');
        }

        return DB::transaction(function () use ($user, $roles, $actor) {
            $before = $user->roles->pluck('name')->sort()->values()->all();

            $user->roles()->sync($roles->pluck('id'));
            // The per-request permission cache would otherwise answer from the
            // roles this user had a moment ago.
            $user->forgetPermissionCache();

            // A role change is the highest-value audit event in the system -
            // it is how an attacker makes a foothold permanent.
            $this->audit($actor, 'user_roles_changed', $user, [
                'from' => $before,
                'to' => $roles->pluck('name')->sort()->values()->all(),
            ]);

            return $user->fresh(['roles']);
        });
    }

    /**
     * Disables an account (ROLE-07, SEC-AUTH-*).
     *
     * Disabling revokes tokens and closes the open work session. Leaving either
     * behind means a "disabled" user keeps working from an app that never
     * re-authenticates, and keeps accruing attendance time.
     *
     * @throws ApiException
     */
    public function disable(User $user, User $actor): User
    {
        if ($user->is($actor)) {
            // Not paternalism - an admin who disables themselves may be the
            // only one who could re-enable the account.
            throw new ApiException(ErrorCode::Forbidden, 'You cannot disable your own account.');
        }

        $this->guardLastSuperAdmin($user);

        return DB::transaction(function () use ($user, $actor) {
            $user->forceFill(['is_active' => false])->save();

            $user->tokens()->delete();
            $user->workSessions()->whereNull('ended_at')->update([
                'ended_at' => now(),
                'end_reason' => 'forced',
            ]);

            $this->audit($actor, 'user_disabled', $user);

            return $user->fresh();
        });
    }

    public function enable(User $user, User $actor): User
    {
        $user->forceFill(['is_active' => true])->save();

        $this->audit($actor, 'user_enabled', $user);

        return $user->fresh();
    }

    /**
     * The system must always have somebody who can restore it.
     *
     * @throws ApiException
     */
    private function guardLastSuperAdmin(User $user): void
    {
        $isSuperAdmin = $user->roles->contains('name', RoleName::SuperAdmin->value);

        if (! $isSuperAdmin) {
            return;
        }

        $remaining = User::query()
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->whereHas('roles', fn ($q) => $q->where('name', RoleName::SuperAdmin->value))
            ->count();

        if ($remaining === 0) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'This is the last active Super Admin. Promote somebody else first.',
            );
        }
    }

    /** @param array<string, mixed> $context */
    private function audit(?User $actor, string $action, User $subject, array $context = []): void
    {
        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => $action,
            'description' => $subject->email,
            'new_values' => array_merge(['user_id' => $subject->id], $context),
        ]);
    }
}
