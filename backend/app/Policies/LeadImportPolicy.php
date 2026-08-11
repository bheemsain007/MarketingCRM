<?php

namespace App\Policies;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\LeadImport;
use App\Models\User;

/**
 * Record-level authorisation for lead imports (SEC-AUTHZ-04).
 *
 * An import report is not a neutral log entry: its rejected rows contain the
 * names, phone numbers and emails of everyone the file failed to create. That
 * makes it the same class of data as the lead list itself, and it is gated the
 * same way.
 */
class LeadImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::LeadsImport);
    }

    /**
     * Only a full-scope user reads somebody else's import.
     *
     * Team scope is deliberately NOT enough here. A team lead's file may hold
     * leads that end up assigned across the whole business, so "my team's
     * imports" is not a meaningful boundary over the file's contents - it is
     * the uploader's own work or nothing.
     */
    public function view(User $user, LeadImport $import): bool
    {
        return $user->hasPermission(Permission::LeadsImport)
            && ($user->dataScope() === DataScope::All || $import->uploaded_by === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::LeadsImport);
    }
}
