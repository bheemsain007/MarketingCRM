<?php

namespace App\Enums;

/**
 * The six roles (PROJECT_REQUIREMENTS §2, ROLE-01..06).
 *
 * PROPOSED - the brief required RBAC but named no roles. These are seeded data,
 * so changing the set later is a seeder change, not a migration (T-08).
 */
enum RoleName: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Manager = 'manager';
    case Telecaller = 'telecaller';
    case Accounts = 'accounts';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::Telecaller => 'Telecaller',
            self::Accounts => 'Accounts',
            self::Viewer => 'Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Full access including user management, provider credentials and system configuration.',
            self::Admin => 'All CRM operations and reports; cannot change provider credentials or system config.',
            self::Manager => 'Manages a team: assigns leads, views team reports, approves discounts, runs campaigns.',
            self::Telecaller => 'Works their own assigned leads: calls, notes, follow-ups, interest.',
            self::Accounts => 'Payments, refunds and revenue reporting; read-only on leads.',
            self::Viewer => 'Read-only dashboards and reports.',
        };
    }

    public function dataScope(): DataScope
    {
        return match ($this) {
            self::SuperAdmin, self::Admin, self::Accounts, self::Viewer => DataScope::All,
            self::Manager => DataScope::Team,
            self::Telecaller => DataScope::Own,
        };
    }

    /**
     * Super Admin bypasses permission checks entirely. Every other role - Admin
     * included - is bound by its granted permissions, so an accidental gap in a
     * seeder shows up as a 403 rather than silently granting access.
     */
    public function bypassesPermissionChecks(): bool
    {
        return $this === self::SuperAdmin;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
