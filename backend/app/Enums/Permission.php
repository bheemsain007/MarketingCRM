<?php

namespace App\Enums;

/**
 * Permission keys, grouped by module.
 *
 * Each phase adds the permissions for the module it builds; this set covers what
 * exists through Phase 4 plus the near-term modules so the seeder and role map
 * stay in one place.
 *
 * Naming: `module.action`. Data breadth is NOT encoded here - that is DataScope
 * (SEC-AUTHZ-03). `leads.view` plus scope Own means "may list leads, sees only
 * their own", which is a different question from whether they may list at all.
 */
enum Permission: string
{
    // ---- Leads -------------------------------------------------------------
    case LeadsView = 'leads.view';
    case LeadsCreate = 'leads.create';
    case LeadsUpdate = 'leads.update';
    case LeadsArchive = 'leads.archive';
    case LeadsAssign = 'leads.assign';
    case LeadsImport = 'leads.import';
    case LeadsExport = 'leads.export';           // audited - SEC-PII-04
    case LeadsReopen = 'leads.reopen';           // Manager+ only - BR-STAT-02

    // ---- Products ----------------------------------------------------------
    case ProductsView = 'products.view';
    case ProductsManage = 'products.manage';

    // ---- Calling -----------------------------------------------------------
    case CallsView = 'calls.view';
    case CallsCreate = 'calls.create';
    case DialerUse = 'dialer.use';
    case RecordingsListen = 'recordings.listen'; // audited - SEC-FILE-04
    case RecordingsDelete = 'recordings.delete';

    // ---- Communication & campaigns -----------------------------------------
    case MessagesSend = 'messages.send';
    case TemplatesView = 'templates.view';
    case TemplatesManage = 'templates.manage';
    case CampaignsView = 'campaigns.view';
    case CampaignsManage = 'campaigns.manage';
    case CampaignsRun = 'campaigns.run';

    // ---- DNC ---------------------------------------------------------------
    case DncView = 'dnc.view';
    case DncCreate = 'dnc.create';
    case DncRemove = 'dnc.remove';               // elevated - BR-DNC-06

    // ---- Follow-ups --------------------------------------------------------
    case FollowUpsView = 'follow_ups.view';
    case FollowUpsManage = 'follow_ups.manage';

    // ---- Sales & payments --------------------------------------------------
    case SalesView = 'sales.view';
    case SalesManage = 'sales.manage';
    case DiscountsApprove = 'discounts.approve'; // BR-SALE-03
    case PaymentsView = 'payments.view';
    case PaymentsManage = 'payments.manage';
    case PaymentsRefund = 'payments.refund';

    // ---- Reports -----------------------------------------------------------
    case ReportsView = 'reports.view';
    case ReportsTelecaller = 'reports.telecaller';
    case ReportsBusiness = 'reports.business';

    // ---- Administration ----------------------------------------------------
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';           // Super Admin only - SEC-AUTHZ-05
    case SettingsManage = 'settings.manage';
    case CredentialsManage = 'credentials.manage'; // Super Admin only
    case AuditView = 'audit.view';

    public function module(): string
    {
        return explode('.', $this->value)[0];
    }

    /**
     * Permissions whose use must be written to the audit log (SEC-AUD-02).
     * Bulk export and recording access are the highest-value insider-threat
     * actions in a CRM, so they are recorded even when legitimately used.
     */
    public function isAudited(): bool
    {
        return in_array($this, [
            self::LeadsExport,
            self::RecordingsListen,
            self::RecordingsDelete,
            self::DncRemove,
            self::RolesManage,
            self::CredentialsManage,
            self::PaymentsRefund,
            self::DiscountsApprove,
        ], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The default permission set per role (PROJECT_REQUIREMENTS §2).
     *
     * Super Admin is absent deliberately - it bypasses checks entirely, so
     * listing permissions for it would imply a completeness the code does not
     * rely on.
     *
     * @return array<int, self>
     */
    public static function defaultsFor(RoleName $role): array
    {
        return match ($role) {
            RoleName::SuperAdmin => self::cases(),

            // Everything operational; not credentials or roles.
            RoleName::Admin => array_values(array_filter(
                self::cases(),
                fn (self $p) => ! in_array($p, [self::RolesManage, self::CredentialsManage], true),
            )),

            RoleName::Manager => [
                self::LeadsView, self::LeadsCreate, self::LeadsUpdate, self::LeadsArchive,
                self::LeadsAssign, self::LeadsImport, self::LeadsExport, self::LeadsReopen,
                self::ProductsView,
                self::CallsView, self::CallsCreate, self::DialerUse, self::RecordingsListen,
                self::MessagesSend, self::TemplatesView, self::TemplatesManage,
                self::CampaignsView, self::CampaignsManage, self::CampaignsRun,
                self::DncView, self::DncCreate, self::DncRemove,
                self::FollowUpsView, self::FollowUpsManage,
                self::SalesView, self::SalesManage, self::DiscountsApprove,
                self::PaymentsView,
                self::ReportsView, self::ReportsTelecaller, self::ReportsBusiness,
                self::UsersView,
            ],

            // No assign, no export, no reopen, no DNC removal - a telecaller
            // cannot un-suppress a lead they marked Not Interested, and cannot
            // bulk-export the lead database (SEC-PII-04).
            RoleName::Telecaller => [
                self::LeadsView, self::LeadsCreate, self::LeadsUpdate,
                self::ProductsView,
                self::CallsView, self::CallsCreate, self::DialerUse,
                self::MessagesSend, self::TemplatesView,
                self::DncView, self::DncCreate,
                self::FollowUpsView, self::FollowUpsManage,
                self::SalesView,
                self::ReportsView,
            ],

            RoleName::Accounts => [
                self::LeadsView,          // read-only: no leads.update granted
                self::ProductsView,
                self::SalesView,
                self::PaymentsView, self::PaymentsManage, self::PaymentsRefund,
                self::ReportsView, self::ReportsBusiness,
            ],

            RoleName::Viewer => [
                self::LeadsView, self::ProductsView, self::CallsView,
                self::CampaignsView, self::SalesView, self::PaymentsView,
                self::ReportsView, self::ReportsTelecaller, self::ReportsBusiness,
            ],
        };
    }
}
