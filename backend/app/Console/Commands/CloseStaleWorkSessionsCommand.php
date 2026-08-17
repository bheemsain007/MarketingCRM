<?php

namespace App\Console\Commands;

use App\Models\UserWorkSession;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Closes work sessions abandoned without a logout (FR-ATT-01, Phase 26, T-24).
 *
 * A session opens on login and closes on logout. That covers the telecaller who
 * clicks "log out" and nobody else: close the browser, lose power, or get killed
 * by the OS and the row stays open for ever. `UserWorkSession::loggedInSeconds()`
 * counts an open session up to `now()`, so the abandoned row keeps accruing -
 * one closed laptop on a Friday reads as sixty hours worked by Monday. That
 * number feeds telecaller reports and therefore pay, which is why this is a
 * scheduler-owned sweep and not a screen somebody has to remember to use.
 *
 * **`ended_at` is the last activity ping, not the moment the sweep ran.** The
 * sweep time is a number we made up: it says the person was working until a cron
 * happened to notice, which is false and, on a nightly schedule, expensively so.
 * The last ping in `user_activity_pings` is the last moment we have evidence
 * anybody was at that desk, and evidence is the only thing an attendance record
 * should contain. With no pings at all the session ends where it started - a
 * login with no activity behind it evidences no work, and crediting any would be
 * inventing it.
 *
 * That choice is deliberately conservative: it can only ever REDUCE recorded
 * time. If the sweep is wrong about somebody, they are visibly short an hour and
 * say so, which is recoverable; the other direction silently overpays and nobody
 * reports it.
 *
 * Time-derived and system-owned, like `crm:mark-overdue-payments` - a user who
 * can decide when their own session ended can decide what they are paid.
 */
class CloseStaleWorkSessionsCommand extends Command
{
    protected $signature = 'crm:close-stale-work-sessions {--dry-run : Report without writing}';

    protected $description = 'Close work sessions abandoned without a logout';

    /**
     * `timeout` over `logout` or `forced` (the end_reason vocabulary is in the
     * user_work_sessions migration). It keeps the sweep's guess distinguishable
     * from the person's own action for anyone auditing the hours later, and a
     * row that claimed the telecaller logged out is invented evidence.
     */
    private const END_REASON = 'timeout';

    public function handle(): int
    {
        $minutes = (int) config('crm.attendance.stale_after_minutes');

        if ($minutes < 1) {
            // Fail closed and loudly. A zero or negative threshold would make
            // the cutoff `now()` or later and close every open session in the
            // system on the next tick, logging out every telecaller currently
            // working and truncating their shift.
            $this->error('crm.attendance.stale_after_minutes must be at least 1 minute; nothing was swept.');

            return self::FAILURE;
        }

        $cutoff = now()->subMinutes($minutes);

        $open = UserWorkSession::query()
            ->whereNull('ended_at')
            /*
             * Pre-filter, not the test itself. A session's last sign of life is
             * never earlier than its start, so one that STARTED after the cutoff
             * cannot possibly be stale - this keeps the sweep off every session
             * opened today rather than loading them to reject them.
             */
            ->where('started_at', '<', $cutoff)
            // One aggregate join rather than a query per session: this runs
            // against every open row in the system on a shared host (NFR-04).
            ->withMax('pings', 'occurred_at')
            ->get();

        $stale = $open->filter(fn (UserWorkSession $session) => $this->lastSeen($session)->lessThan($cutoff));

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                '[dry run] %d work session(s) would be closed as abandoned (silent since before %s).',
                $stale->count(),
                $cutoff->toDateTimeString(),
            ));

            return self::SUCCESS;
        }

        foreach ($stale as $session) {
            $session->update([
                'ended_at' => $this->lastSeen($session),
                'end_reason' => self::END_REASON,
            ]);
        }

        $this->info(sprintf('%d work session(s) closed as abandoned.', $stale->count()));

        return self::SUCCESS;
    }

    /**
     * The last moment we have evidence this person was present.
     *
     * `withMax` returns the aggregate as a raw column rather than a cast
     * attribute, so it is parsed here instead of being trusted to be a Carbon.
     */
    private function lastSeen(UserWorkSession $session): Carbon
    {
        $lastPing = $session->getAttribute('pings_max_occurred_at');

        return $lastPing === null
            ? $session->started_at
            : Carbon::parse((string) $lastPing);
    }
}
