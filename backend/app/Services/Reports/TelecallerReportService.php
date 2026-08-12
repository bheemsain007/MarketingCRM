<?php

namespace App\Services\Reports;

use App\Enums\CallStatus;
use App\Enums\FollowUpStatus;
use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Models\Call;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Models\UserWorkSession;
use App\Services\Settings\SettingsService;
use App\Support\Reporting\Rate;
use App\Support\Reporting\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Telecaller performance (Phase 26, FR-RPT-01/04/06).
 *
 * Every formula is the one in GLOSSARY Part 2 and is cited on the method. That
 * matters more here than anywhere else in the system, because these numbers are
 * read as judgements about people - and most of them have a plausible-looking
 * wrong version that punishes the wrong person:
 *
 * - Average call duration divided by *attempts* rather than *connected* calls
 *   punishes whoever was handed a list of dead numbers.
 * - Idle time read as "not on a call" punishes whoever is writing notes.
 * - A conversion rate with no denominator hides that somebody converted 1 of 2.
 *
 * Attribution (GLOSSARY §2.6) decides who is credited for a conversion when a
 * lead changed hands. It is configuration, and it is **not settled** - see T-24.
 */
class TelecallerReportService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * One row per telecaller (FR-RPT-01).
     *
     * @return array<string, mixed>
     */
    public function leaderboard(ReportPeriod $period): array
    {
        [$from, $to] = $period->bounds();

        $users = User::query()
            ->whereHas('roles.permissions', fn ($q) => $q->where('name', Permission::CallsCreate->value))
            ->orderBy('name')
            ->get();

        $rows = $users->map(fn (User $user) => $this->forUser($user, $period))->all();

        return [
            'period' => $period->toArray(),
            'attribution' => $this->attributionModel(),
            'rows' => $rows,
        ];
    }

    /**
     * Everything for one telecaller.
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user, ReportPeriod $period): array
    {
        [$from, $to] = $period->bounds();

        $calls = fn () => Call::query()
            ->where('user_id', $user->id)
            ->whereBetween('started_at', [$from, $to]);

        $attempts = (clone $calls())->count();
        $connected = (clone $calls())->where('status', CallStatus::Connected->value)->count();

        // GLOSSARY §2.2: talk time counts CONNECTED calls only. Ringing is not
        // work done, and counting it would reward dialling into the void.
        $talkSeconds = (int) (clone $calls())
            ->where('status', CallStatus::Connected->value)
            ->sum('duration_seconds');

        $leadsAttempted = (clone $calls())->distinct()->count('lead_id');
        $leadsConnected = (clone $calls())
            ->where('status', CallStatus::Connected->value)
            ->distinct()
            ->count('lead_id');

        $time = $this->timeMetrics($user, $period);
        $converted = $this->conversions($user, $from, $to);

        return [
            'user' => ['id' => $user->id, 'name' => $user->name],

            'calls' => [
                'attempts' => $attempts,
                'connected' => $connected,
                // Call-level: how many dials got through.
                'connect_rate' => Rate::of($connected, $attempts, 'call attempts')->toArray(),
                // Lead-level, and a different question: how many PEOPLE were
                // reached. A run of redials to one number flatters the first
                // and leaves this one alone.
                'contact_rate' => Rate::of($leadsConnected, $leadsAttempted, 'leads attempted')->toArray(),
                'unique_leads_touched' => $leadsAttempted,
                'talk_time_seconds' => $talkSeconds,
                // Divided by CONNECTED, never by attempts (GLOSSARY §2.2).
                'average_call_seconds' => $connected === 0 ? null : (int) round($talkSeconds / $connected),
            ],

            'time' => $time,

            'follow_ups' => $this->followUps($user, $from, $to),

            'outcomes' => [
                'converted' => $converted['count'],
                'revenue' => $converted['revenue'],
                'conversion_rate' => Rate::of(
                    $converted['count'],
                    $leadsAttempted,
                    'leads attempted',
                )->toArray(),
            ],
        ];
    }

    /**
     * Time on shift (GLOSSARY §2.3).
     *
     * @return array<string, mixed>
     */
    private function timeMetrics(User $user, ReportPeriod $period): array
    {
        [$from, $to] = $period->bounds();

        $sessions = UserWorkSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('started_at', [$from, $to])
            ->get();

        $loggedIn = $sessions->sum(function (UserWorkSession $session) {
            // An open session is counted up to now, not skipped: a telecaller
            // halfway through a shift has still worked what they have worked.
            $end = $session->ended_at ?? now();

            // Ordered start->end, not end->start: Carbon returns a SIGNED
            // difference, so the other way round yields a negative that
            // max(0, ...) quietly flattens to zero - and every shift reads as
            // no time worked.
            return max(0, $session->started_at->diffInSeconds($end));
        });

        $active = (int) $sessions->sum('active_seconds');
        $break = (int) $sessions->sum('break_seconds');

        $talk = (int) Call::query()
            ->where('user_id', $user->id)
            ->whereBetween('started_at', [$from, $to])
            ->where('status', CallStatus::Connected->value)
            ->sum('duration_seconds');

        return [
            'logged_in_seconds' => (int) $loggedIn,
            'active_seconds' => $active,
            'break_seconds' => $break,
            /*
             * Idle = logged in − active − break. NOT "time not on a call":
             * somebody writing notes or updating a status is working, and a
             * report that calls that idle is measuring the wrong thing and will
             * be used to judge people.
             */
            'idle_seconds' => max(0, (int) $loggedIn - $active - $break),
            'occupancy' => Rate::of($talk, (int) $loggedIn, 'seconds logged in')->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function followUps(User $user, mixed $from, mixed $to): array
    {
        $due = FollowUp::query()
            ->where('assigned_to', $user->id)
            ->whereBetween('scheduled_at', [$from, $to]);

        $total = (clone $due)->count();
        $completed = (clone $due)->where('status', FollowUpStatus::Completed->value)->count();

        return [
            'due' => $total,
            'completed' => $completed,
            'missed' => (clone $due)->where('status', FollowUpStatus::Missed->value)->count(),
            'completion_rate' => Rate::of($completed, $total, 'follow-ups due')->toArray(),
        ];
    }

    /**
     * Conversions credited to this user (GLOSSARY §2.6, T-24).
     *
     * @return array{count: int, revenue: string}
     */
    private function conversions(User $user, mixed $from, mixed $to): array
    {
        $converted = Lead::query()
            ->where('status', LeadStatus::Converted->value)
            ->whereExists(function ($query) use ($from, $to) {
                $query->select(DB::raw(1))
                    ->from('lead_status_history')
                    ->whereColumn('lead_status_history.lead_id', 'leads.id')
                    ->where('to_status', LeadStatus::Converted->value)
                    ->whereBetween('lead_status_history.created_at', [$from, $to]);
            });

        $converted = $this->attributeTo($converted, $user);

        $leadIds = $converted->pluck('id');

        // Revenue follows the same attribution as the conversion: crediting the
        // count to one person and the money to another would make two reports
        // that disagree about the same deal.
        $revenue = $leadIds->isEmpty() ? '0.00' : (string) DB::table('payments')
            ->whereIn('lead_id', $leadIds)
            ->whereIn('status', ['partial', 'paid'])
            ->sum('amount');

        return ['count' => $leadIds->count(), 'revenue' => number_format((float) $revenue, 2, '.', '')];
    }

    /**
     * Applies the configured attribution model.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function attributeTo($query, User $user)
    {
        return match ($this->attributionModel()) {
            /*
             * Option B: whoever first moved the lead to Interested. Rewards
             * prospecting rather than closing.
             */
            'first_interest' => $query->whereExists(function ($sub) use ($user) {
                $sub->select(DB::raw(1))
                    ->from('lead_status_history as h')
                    ->whereColumn('h.lead_id', 'leads.id')
                    ->where('h.to_status', LeadStatus::Interested->value)
                    ->where('h.changed_by', $user->id)
                    // The FIRST such move, not any of them: a lead that went
                    // Interested twice under two owners has one prospector.
                    ->whereRaw('h.id = (select min(h2.id) from lead_status_history h2
                        where h2.lead_id = leads.id and h2.to_status = ?)', [LeadStatus::Interested->value]);
            }),

            // Option A (default): whoever owned it when it converted.
            default => $query->where('assigned_to', $user->id),
        };
    }

    private function attributionModel(): string
    {
        $model = (string) $this->settings->get('crm.attribution.model', 'last_owner');

        // An unrecognised value falls back rather than returning nobody's
        // numbers - a typo in a setting must not silently zero everyone's pay.
        return in_array($model, ['last_owner', 'first_interest'], true) ? $model : 'last_owner';
    }
}
