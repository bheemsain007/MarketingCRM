<?php

namespace App\Policies;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AutoDialerSession;
use App\Models\User;

/**
 * Auto-dialer sessions (SEC-AUTHZ-04).
 *
 * A dialling run is personal work: it holds claims on leads and it produces the
 * call records that drive a telecaller's numbers.
 */
class AutoDialerSessionPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::DialerUse);
    }

    public function view(User $user, AutoDialerSession $session): bool
    {
        return $user->hasPermission(Permission::DialerUse)
            && ($session->user_id === $user->id || $this->supervises($user, $session));
    }

    /**
     * Only the telecaller running a session drives it.
     *
     * A manager may watch a run - useful for a floor supervisor - but not take
     * the next lead on somebody else's behalf, which would claim a lead for a
     * person who is not on the phone.
     */
    public function drive(User $user, AutoDialerSession $session): bool
    {
        return $user->hasPermission(Permission::DialerUse) && $session->user_id === $user->id;
    }

    /**
     * A run can be stopped by its owner or by a supervisor - a telecaller who
     * has gone home mid-session should not leave leads claimed until the TTL
     * expires.
     */
    public function stop(User $user, AutoDialerSession $session): bool
    {
        return $this->drive($user, $session) || $this->supervises($user, $session);
    }

    private function supervises(User $user, AutoDialerSession $session): bool
    {
        return match ($user->dataScope()) {
            DataScope::All => true,
            DataScope::Team => $user->team_id !== null && $session->user?->team_id === $user->team_id,
            DataScope::Own => false,
        };
    }
}
