<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Tag;
use App\Models\User;

/**
 * Authorisation for the tag vocabulary (FR-LEAD-03, BR-INT-02).
 *
 * Two rules, and the second is the one worth writing down.
 *
 * Reading is gated like LEAD data, not like settings: tag names are rendered on
 * the lead list, the lead form and the timeline, so every role that can see a
 * lead can see the vocabulary it is labelled with. Changing that vocabulary is
 * administrative and takes `settings.manage`.
 *
 * System tags are refused to EVERYONE, including a Super Admin holding every
 * permission in the system. They are applied automatically by the Interest
 * Engine, which looks them up by name; renaming one silently stops the engine
 * finding it and deleting one takes the label off every lead that carries it.
 * The rule lives here rather than in the screen because a hidden button is not
 * a control - the API is what has to refuse.
 */
class TagPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::LeadsView);
    }

    public function view(User $user, Tag $tag): bool
    {
        return $user->hasPermission(Permission::LeadsView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::SettingsManage);
    }

    public function update(User $user, Tag $tag): bool
    {
        return $user->hasPermission(Permission::SettingsManage) && ! $tag->is_system;
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $user->hasPermission(Permission::SettingsManage) && ! $tag->is_system;
    }
}
