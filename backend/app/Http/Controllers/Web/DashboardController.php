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
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Web CRM dashboard (Phase 8).
 *
 * The counts are computed server-side rather than fetched by AJAX: they are
 * the first thing on the page, and a dashboard that renders empty and then
 * fills in reads as broken on a slow connection.
 *
 * The real reporting dashboard is Phase 26/27. This is a working landing page,
 * not a metrics product - every figure here is a direct count, and none of the
 * GLOSSARY Part 2 formulas are being approximated.
 */
class DashboardController extends Controller
{
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
        ]);
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
