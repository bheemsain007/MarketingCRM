<?php

namespace App\Http\Controllers\Web;

use App\Enums\FollowUpStatus;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use App\Services\Reports\BusinessReportService;
use App\Support\Reporting\ReportPeriod;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Web CRM dashboard (Phase 8).
 *
 * The counts are computed server-side rather than fetched by AJAX: they are
 * the first thing on the page, and a dashboard that renders empty and then
 * fills in reads as broken on a slow connection. The revenue row follows the
 * same rule for the same reason, so it is computed here too rather than
 * fetched from /api/v1/reports/summary on load.
 *
 * This is still a landing page, not a metrics product. Every lead figure is a
 * direct count, and the money row approximates nothing either - it calls the
 * same BusinessReportService the reports screen uses, so a second
 * implementation of "collected" cannot drift away from the first.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly BusinessReportService $reports) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // Data scope FIRST, exactly as the API list does it. A dashboard that
        // counted outside the caller's scope would leak the shape of the
        // database to a telecaller (SEC-AUTHZ-03).
        $scoped = fn () => tap(Lead::query(), fn ($query) => $user->applyDataScope($query));

        $byStatus = $scoped()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [
            'total' => (int) $byStatus->sum(),
            'new' => (int) $byStatus->get(LeadStatus::New->value, 0),
            'working' => (int) collect([
                LeadStatus::Contacted, LeadStatus::Interested, LeadStatus::FollowUp,
                LeadStatus::Callback, LeadStatus::Proposal, LeadStatus::Negotiation,
                LeadStatus::DecisionPending,
            ])->sum(fn (LeadStatus $status) => $byStatus->get($status->value, 0)),
            'converted' => (int) $byStatus->get(LeadStatus::Converted->value, 0),
            'closed' => (int) collect([LeadStatus::Lost, LeadStatus::NotInterested])
                ->sum(fn (LeadStatus $status) => $byStatus->get($status->value, 0)),
        ];

        return view('dashboard', [
            'counts' => $counts,
            'byStatus' => $byStatus,
            // The leakage metric (GLOSSARY §2.9): leads nobody has contacted.
            'untouched' => $scoped()->untouched()->count(),
            'suppressed' => $scoped()->where('is_suppressed', true)->count(),
            'recent' => $scoped()->with(['assignedUser'])->latest('created_at')->limit(8)->get(),
            'work' => $this->workToday($user, $scoped),
            'revenue' => $this->revenueThisMonth($user),
        ]);
    }

    /**
     * The money picture for the current month (FR-RPT-02, GLOSSARY section 2.5).
     *
     * Null - and therefore undrawn - for anyone without `reports.business`.
     * That gate is the whole point of this method. Every other figure on this
     * page is inside the caller's data scope, but `BusinessReportService::
     * revenue()` is deliberately organisation-wide: it sums the company's
     * sales and payments, not the viewer's. The dashboard has no route-level
     * permission at all, so without this check a telecaller would read company
     * revenue off their landing page (SEC-AUTHZ-03).
     *
     * `reports.business` rather than `payments.view`, for two reasons. It is
     * the permission routes/web.php already puts in front of these exact
     * numbers on /reports, "the organisation-wide view" (FR-RPT-03, T-60) - so
     * the two screens are reachable by the same people. And `payments.view`'s
     * existing use on this page (payments_overdue below) is data-scoped to the
     * caller's own leads; reusing it for an unscoped company total would
     * quietly widen what holding it means.
     *
     * @return array<string, mixed>|null
     */
    private function revenueThisMonth(User $user): ?array
    {
        if (! $user->hasPermission(Permission::ReportsBusiness)) {
            return null;
        }

        // Bounded to this month, the same default the reports screen uses: an
        // unbounded all-time aggregate over a growing table is the query that
        // takes the dashboard down (FR-RPT-05). Built in the organisation's
        // timezone, or "this month" silently includes part of last one.
        $timezone = (string) config('crm.timezone', 'UTC');

        $period = ReportPeriod::between(
            Carbon::now($timezone)->startOfMonth()->startOfDay(),
            Carbon::now($timezone)->endOfDay(),
            $timezone,
        );

        return [
            // Stated on the page, so two people comparing dashboards can see
            // they were looking at the same window.
            'period' => $period->toArray(),
            'figures' => $this->reports->revenue($period),
        ];
    }

    /**
     * "What do I need to do today?"
     *
     * Added once follow-ups, payments and scoring existed - a landing page
     * showing only how many leads there are answers a question nobody opens
     * the CRM to ask.
     *
     * Every figure is either the caller's OWN work or inside their data scope,
     * and each block is gated on the permission that owns it: a telecaller sees
     * their follow-ups, a manager additionally sees the unassigned pool, and
     * only somebody with `payments.view` sees money owed.
     *
     * @param  Closure():Builder  $scoped
     * @return array<string, mixed>
     */
    private function workToday(User $user, Closure $scoped): array
    {
        $mine = fn () => FollowUp::query()->where('assigned_to', $user->getKey());

        $work = [
            // Due today rather than "open": a list including next month's
            // reminders is not a to-do list.
            'follow_ups_today' => $user->hasPermission(Permission::FollowUpsView)
                ? (clone $mine())
                    ->where('status', FollowUpStatus::Open->value)
                    ->whereDate('scheduled_at', '<=', now()->toDateString())
                    ->count()
                : null,

            // Late, not void - they still need doing (BR-FUP-02).
            'follow_ups_missed' => $user->hasPermission(Permission::FollowUpsView)
                ? (clone $mine())->where('status', FollowUpStatus::Missed->value)->count()
                : null,

            'hot_leads' => $scoped()->where('temperature', LeadTemperature::Hot->value)->count(),

            'unread_notifications' => $user->crmNotifications()->whereNull('read_at')->count(),
        ];

        // A manager's queue: leads with no owner cannot be worked by anybody.
        if ($user->hasPermission(Permission::LeadsAssign)) {
            $work['unassigned'] = $scoped()->whereNull('assigned_to')->count();
        }

        if ($user->hasPermission(Permission::PaymentsView)) {
            $work['payments_overdue'] = Payment::query()
                ->where('status', PaymentStatus::Overdue->value)
                ->whereHas('lead', fn ($query) => $user->applyDataScope($query))
                ->count();
        }

        return $work;
    }
}
