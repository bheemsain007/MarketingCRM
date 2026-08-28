<?php

namespace App\Console\Commands;

use App\Enums\FollowUpStatus;
use App\Enums\InterestSignalType;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Services\Interest\InterestEngine;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Fires follow-up reminders and flags misses (FR-FUP-03, BR-FUP-02, BR-NOTIF-04).
 *
 * Runs every minute. Two jobs, deliberately in one command: a follow-up that
 * became due since the last tick needs its reminder *and* may already be past
 * the miss threshold, and splitting them across two schedules would let a
 * follow-up be marked missed by one before the other had reminded anybody.
 */
class ProcessFollowUpsCommand extends Command
{
    protected $signature = 'crm:process-follow-ups
                            {--dry-run : Report what would happen without writing}';

    protected $description = 'Send follow-up reminders and flag missed follow-ups';

    /**
     * How many follow-ups one miss batch flags and then reacts to.
     *
     * Chosen so the flagging stays a handful of UPDATE statements even after a
     * long outage, while the rows a batch holds in memory - and the signals it
     * writes before the next batch is read - stay bounded.
     */
    private const MISS_BATCH = 500;

    public function handle(NotificationService $notifications, InterestEngine $interest): int
    {
        $leadMinutes = (int) config('crm.follow_up.reminder_lead_minutes', 15);
        $dryRun = (bool) $this->option('dry-run');

        $reminded = $this->sendReminders($notifications, $leadMinutes, $dryRun);
        $missed = $this->flagMissed($notifications, $interest, $dryRun);

        $this->info(sprintf(
            '%s%d reminder(s) sent, %d follow-up(s) flagged missed.',
            $dryRun ? '[dry run] ' : '',
            $reminded,
            $missed,
        ));

        return self::SUCCESS;
    }

    /**
     * BR-NOTIF-04: one reminder at the configured lead time before due.
     *
     * `reminder_sent` is the guard. Without it a minute-by-minute schedule
     * re-notifies the same person every minute from the lead time until the
     * follow-up is due - which is how people learn to ignore notifications.
     */
    private function sendReminders(NotificationService $notifications, int $leadMinutes, bool $dryRun): int
    {
        $due = FollowUp::query()
            ->with(['lead:id,name'])
            ->where('status', FollowUpStatus::Open->value)
            ->where('reminder_sent', false)
            ->whereNotNull('assigned_to')
            ->where('scheduled_at', '<=', now()->addMinutes($leadMinutes))
            ->get();

        foreach ($due as $followUp) {
            if ($dryRun) {
                continue;
            }

            $owner = User::find($followUp->assigned_to);

            // A follow-up assigned to a deleted or disabled account still gets
            // marked as reminded - otherwise it is retried every minute for
            // ever, and the tick spends its life on a notification nobody can
            // receive.
            if ($owner !== null && $owner->is_active) {
                $notifications->notify(
                    $owner,
                    'follow_up_due',
                    'Follow-up due: '.($followUp->lead->name ?? 'lead'),
                    [
                        'body' => 'Scheduled for '.$followUp->scheduled_at->format('d M Y H:i'),
                        'reference' => $followUp,
                        'action_url' => '/leads/'.$followUp->lead_id,
                    ],
                );
            }

            $followUp->update(['reminder_sent' => true, 'reminder_sent_at' => now()]);
        }

        return $due->count();
    }

    /**
     * BR-FUP-02: past its due time and still open is `Missed`.
     *
     * Set by the scheduler and never by a user - the difference between "nobody
     * got to it" and "somebody decided not to" is the whole value of the missed
     * follow-up report.
     *
     * A miss is three things, not one: the flag, the owner being told
     * (BR-NOTIF-02 lists follow-up overdue among its triggers) and the lead's
     * score coming down (BR-FUP-02, BR-SCORE-01). Only the first can be said in
     * SQL, so the sweep works in batches instead of one statement over the
     * whole backlog: each batch is still a single UPDATE, and the other two are
     * driven off rows already in memory. A ten-thousand-row backlog costs
     * twenty updates rather than ten thousand.
     */
    private function flagMissed(NotificationService $notifications, InterestEngine $interest, bool $dryRun): int
    {
        // Pinned once. Re-reading the clock every batch would let follow-ups
        // that fall due mid-sweep extend the loop, and this runs every minute -
        // they belong to the next tick.
        $asOf = now();

        if ($dryRun) {
            return $this->overdue($asOf)->count();
        }

        $total = 0;

        do {
            $batch = $this->overdue($asOf)
                ->with(['assignee:id,name,is_active'])
                ->orderBy('id')
                ->limit(self::MISS_BATCH)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            FollowUp::whereIn('id', $batch->modelKeys())
                ->update(['status' => FollowUpStatus::Missed->value]);

            // Flagged first: the side effects below are the slow part, and a
            // crash halfway through them must not leave rows that get flagged -
            // and therefore notified and scored - a second time on the next
            // tick. No offset is needed for the same reason, since a flagged
            // row is no longer overdue.
            $this->reactToMisses($batch, $notifications, $interest);

            $total += $batch->count();
        } while ($batch->count() === self::MISS_BATCH);

        return $total;
    }

    /** @return Builder<FollowUp> */
    private function overdue(Carbon $asOf): Builder
    {
        return FollowUp::query()
            ->where('status', FollowUpStatus::Open->value)
            ->where('scheduled_at', '<', $asOf);
    }

    /**
     * Tells the owner and moves the lead's score, once per missed follow-up.
     *
     * Per follow-up rather than per lead: two commitments ignored on the same
     * lead are two misses, and BR-FUP-02 attaches the decrement to the
     * follow-up. The leads themselves are resolved in one query for the whole
     * batch - and in full, because `InterestEngine::recalculate()` reads
     * `last_engagement_at` and `is_suppressed` to derive temperature, so a
     * column-narrowed lead would recompute it from nulls.
     *
     * @param  Collection<int, FollowUp>  $batch
     */
    private function reactToMisses(Collection $batch, NotificationService $notifications, InterestEngine $interest): void
    {
        $leads = Lead::whereIn('id', $batch->pluck('lead_id')->unique()->all())->get()->keyBy('id');

        foreach ($batch as $followUp) {
            $lead = $leads->get($followUp->lead_id);
            $owner = $followUp->assignee;

            // Same rule as the reminder above: an account that is gone or
            // disabled cannot read anything, and writing the row anyway only
            // grows a table nobody opens.
            if ($owner !== null && $owner->is_active) {
                $notifications->notify(
                    $owner,
                    'follow_up_missed',
                    'Follow-up missed: '.($lead !== null ? $lead->name : 'lead'),
                    [
                        'body' => 'Was due '.$followUp->scheduled_at->format('d M Y H:i'),
                        'reference' => $followUp,
                        'action_url' => '/leads/'.$followUp->lead_id,
                    ],
                );
            }

            // An archived lead has nothing left to score.
            if ($lead === null) {
                continue;
            }

            // No actor: the scheduler noticed, nobody did this. `occurred_at`
            // is the time it was DUE rather than the time we swept, so the
            // score explanation reads as the history it actually is.
            $interest->record($lead, InterestSignalType::FollowUpMissed, null, [
                'evidence' => $followUp,
                'product_id' => $followUp->product_id,
                'occurred_at' => $followUp->scheduled_at,
            ]);
        }
    }
}
