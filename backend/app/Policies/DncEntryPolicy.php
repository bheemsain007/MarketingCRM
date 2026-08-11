<?php

namespace App\Policies;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\DncEntry;
use App\Models\User;

/**
 * Authorisation for suppression records (BR-DNC-06, SEC-AUTHZ-04).
 *
 * Removal is the interesting one. `dnc.remove` is held by Manager and above -
 * a telecaller may add a suppression but never lift one, because the person
 * most motivated to un-suppress a lead is the one whose target it was.
 */
class DncEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::DncView);
    }

    public function view(User $user, DncEntry $entry): bool
    {
        return $user->hasPermission(Permission::DncView)
            && $this->withinScope($user, $entry);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::DncCreate);
    }

    /**
     * Lifting a suppression.
     *
     * `dnc.remove` satisfies BR-DNC-06's "Manager+" on its own, since that is
     * exactly the set of roles seeded with it. The stricter refinement in
     * `DncReason::requiresElevatedRemoval()` is NOT enforced here - no
     * business rule defines what authority it should demand, so the API
     * reports the flag and the UI warns on it (T-50).
     */
    public function remove(User $user, DncEntry $entry): bool
    {
        return $user->hasPermission(Permission::DncRemove)
            && $this->withinScope($user, $entry);
    }

    /**
     * Mirrors the query-level scope in DncController::index().
     *
     * An entry with no lead has no owner to scope by, so it is reachable only
     * at All scope - the same rule the list applies, because a record hidden
     * from the list must not be reachable by id.
     */
    private function withinScope(User $user, DncEntry $entry): bool
    {
        if ($user->dataScope() === DataScope::All) {
            return true;
        }

        $lead = $entry->lead;

        if ($lead === null) {
            return false;
        }

        return match ($user->dataScope()) {
            DataScope::Team => $lead->assigned_to === null
                || ($user->team_id !== null && $lead->assignedUser?->team_id === $user->team_id),

            DataScope::Own => $lead->assigned_to === $user->id,

            default => false,
        };
    }
}
