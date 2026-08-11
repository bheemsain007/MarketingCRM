<?php

namespace App\Policies;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Call;
use App\Models\User;

/**
 * Record-level authorisation for calls (SEC-AUTHZ-04).
 *
 * Call records carry notes about what a lead said and, later, recordings of
 * the conversation. They are gated as tightly as the lead itself.
 */
class CallPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::CallsView);
    }

    public function view(User $user, Call $call): bool
    {
        return $user->hasPermission(Permission::CallsView)
            && $this->withinScope($user, $call);
    }

    /**
     * Only the telecaller who made the call reports its outcome - or a
     * full-scope user cleaning up after a device that never reported back.
     *
     * A colleague writing an outcome onto somebody else's call would corrupt
     * the record that drives talk time and telecaller pay.
     */
    public function recordOutcome(User $user, Call $call): bool
    {
        if (! $user->hasPermission(Permission::CallsCreate)) {
            return false;
        }

        return $call->user_id === $user->id || $user->dataScope() === DataScope::All;
    }

    /**
     * Mirrors LeadPolicy: a call is visible to whoever may see its lead.
     * Team scope leans on the lead's owner, so the relation must be loaded.
     */
    private function withinScope(User $user, Call $call): bool
    {
        return match ($user->dataScope()) {
            DataScope::All => true,
            DataScope::Team => $call->user_id === null
                || $call->user?->team_id === $user->team_id,
            DataScope::Own => $call->user_id === $user->id,
        };
    }
}
