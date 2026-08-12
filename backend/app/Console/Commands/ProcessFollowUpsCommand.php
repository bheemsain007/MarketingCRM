<?php

namespace App\Console\Commands;

use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;

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

    public function handle(NotificationService $notifications): int
    {
        $leadMinutes = (int) config('crm.follow_up.reminder_lead_minutes', 15);
        $dryRun = (bool) $this->option('dry-run');

        $reminded = $this->sendReminders($notifications, $leadMinutes, $dryRun);
        $missed = $this->flagMissed($dryRun);

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
     */
    private function flagMissed(bool $dryRun): int
    {
        $query = FollowUp::query()
            ->where('status', FollowUpStatus::Open->value)
            ->where('scheduled_at', '<', now());

        if ($dryRun) {
            return $query->count();
        }

        // A bulk update rather than a loop: this can run over a large backlog
        // after downtime, and the status change carries no per-row side effect
        // that would need one. Score decay (BR-SCORE-01) belongs to the
        // Interest Engine in Phase 20 and is deliberately not done here.
        return $query->update(['status' => FollowUpStatus::Missed->value]);
    }
}
