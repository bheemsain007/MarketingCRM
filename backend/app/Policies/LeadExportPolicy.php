<?php

namespace App\Policies;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\LeadExport;
use App\Models\User;

/**
 * Record-level authorisation for lead exports (SEC-AUTHZ-04, SEC-PII-04).
 *
 * A generated export is a bulk PII file, the same class of data as the lead
 * list itself - so, like `LeadImportPolicy`, only the requester or a
 * full-scope user may read the job or download its file. Team scope is
 * deliberately NOT enough: an export's filters can reach across the whole
 * business, so "my team's exports" is not a meaningful boundary over what the
 * file actually contains.
 */
class LeadExportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::LeadsExport);
    }

    public function view(User $user, LeadExport $export): bool
    {
        return $user->hasPermission(Permission::LeadsExport)
            && ($user->dataScope() === DataScope::All || $export->user_id === $user->id);
    }
}
