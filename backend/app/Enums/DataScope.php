<?php

namespace App\Enums;

/**
 * How much data a role may see (SEC-AUTHZ-03).
 *
 * This is separate from permissions on purpose. A permission answers "may this
 * user list leads?"; a scope answers "WHICH leads?". Conflating the two is how
 * a telecaller ends up able to read the whole database through a legitimate
 * endpoint - the classic CRM data-leak.
 *
 * Scope is enforced by query constraints on the server. It is never derived
 * from a client-supplied filter.
 */
enum DataScope: string
{
    case All = 'all';
    case Team = 'team';
    case Own = 'own';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All records',
            self::Team => 'Own team only',
            self::Own => 'Own records only',
        };
    }

    /** Higher wins when a user holds several roles. */
    public function rank(): int
    {
        return match ($this) {
            self::Own => 1,
            self::Team => 2,
            self::All => 3,
        };
    }

    public function isBroaderThan(self $other): bool
    {
        return $this->rank() > $other->rank();
    }
}
