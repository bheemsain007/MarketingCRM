<?php

namespace App\Policies;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Lead;
use App\Models\User;

/**
 * Record-level authorisation for leads (SEC-AUTHZ-04).
 *
 * The permission middleware answers "may this user touch leads at all?".
 * THIS answers "may they touch THIS lead?" - without it, a telecaller could
 * read any colleague's lead simply by changing the id in the URL, which is the
 * classic IDOR and the most likely real-world data leak in a CRM.
 *
 * Both layers are required: the middleware is the cheap first gate, the policy
 * is the one that actually protects the record.
 */
class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::LeadsView);
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->hasPermission(Permission::LeadsView)
            && $this->withinScope($user, $lead);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::LeadsCreate);
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->hasPermission(Permission::LeadsUpdate)
            && $this->withinScope($user, $lead);
    }

    public function archive(User $user, Lead $lead): bool
    {
        return $user->hasPermission(Permission::LeadsArchive)
            && $this->withinScope($user, $lead);
    }

    public function restore(User $user, Lead $lead): bool
    {
        return $user->hasPermission(Permission::LeadsArchive)
            && $this->withinScope($user, $lead);
    }

    /**
     * Reassignment is a supervisory act - a telecaller must not be able to push
     * a difficult lead onto a colleague, or claim someone else's.
     */
    public function assign(User $user, Lead $lead): bool
    {
        return $user->hasPermission(Permission::LeadsAssign);
    }

    /**
     * Class-level counterpart to assign(), for endpoints that operate on no
     * particular lead - e.g. listing who is eligible to receive work.
     */
    public function assignAny(User $user): bool
    {
        return $user->hasPermission(Permission::LeadsAssign);
    }

    public function addNote(User $user, Lead $lead): bool
    {
        return $user->hasPermission(Permission::LeadsUpdate)
            && $this->withinScope($user, $lead);
    }

    /**
     * Moving a lead through the pipeline is ordinary telecaller work
     * (BR-STAT-05). Which specific moves are legal is the matrix's business,
     * not this policy's - `LeadStatusService` owns that.
     */
    public function changeStatus(User $user, Lead $lead): bool
    {
        return $user->hasPermission(Permission::LeadsUpdate)
            && $this->withinScope($user, $lead);
    }

    /**
     * Reopening a closed lead is supervisory (BR-STAT-02).
     *
     * Without the separate permission, a telecaller could recycle their own
     * dead leads to flatter their conversion numbers - the service enforces
     * this too, since imports and webhooks never reach a policy.
     */
    public function reopen(User $user, Lead $lead): bool
    {
        return $user->hasPermission(Permission::LeadsReopen)
            && $this->withinScope($user, $lead);
    }

    /** Per-product interest travels with the right to update the lead. */
    public function manageProducts(User $user, Lead $lead): bool
    {
        return $user->hasPermission(Permission::LeadsUpdate)
            && $this->withinScope($user, $lead);
    }

    /**
     * Whether this user may dial THIS lead (Phase 9).
     *
     * Answers the authorisation question only. Whether the lead may be dialled
     * *at all* - suppression, calling hours, a missing number - is CallService's
     * business, and those refusals are not about who is asking.
     */
    public function call(User $user, Lead $lead): bool
    {
        return $user->hasPermission(Permission::CallsCreate)
            && $this->withinScope($user, $lead);
    }

    public function export(User $user): bool
    {
        // Bulk export is the highest-value insider-threat action in a CRM, so
        // it is a separate permission from viewing (SEC-PII-04).
        return $user->hasPermission(Permission::LeadsExport);
    }

    /**
     * Mirrors the query-level scope in HasRolesAndPermissions::applyDataScope().
     *
     * The two must agree: the query scope decides what appears in a LIST, this
     * decides what can be fetched DIRECTLY. A mismatch means a record hidden
     * from the list is still reachable by id.
     */
    private function withinScope(User $user, Lead $lead): bool
    {
        return match ($user->dataScope()) {
            DataScope::All => true,

            // Unassigned leads are the shared pool and MUST be visible at team
            // scope. Excluding them deadlocks the system: a manager cannot
            // assign a lead they are not allowed to see, so a new lead with no
            // owner would be unreachable by everyone.
            DataScope::Team => $lead->assigned_to === null
                || ($user->team_id !== null && $lead->assignedUser?->team_id === $user->team_id),

            // A telecaller sees only what is theirs - the unassigned pool is
            // not theirs to work until someone assigns it.
            DataScope::Own => $lead->assigned_to === $user->id,
        };
    }
}
