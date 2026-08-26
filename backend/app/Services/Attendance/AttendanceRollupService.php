<?php

namespace App\Services\Attendance;

use App\Models\UserWorkSession;
use Illuminate\Support\Carbon;

/**
 * Turns raw activity pings into the active/idle/break totals GLOSSARY §2.3
 * defines (FR-ATT-03). Run once a session's story is finished - normal logout
 * and the stale-session sweep both close a session and then call this - so a
 * report never reads active/idle/break off a session still being decided.
 */
class AttendanceRollupService
{
    /**
     * Computes and persists active_seconds/idle_seconds/break_seconds for one
     * session (FR-ATT-03/04). Idempotent: the session's pings and its own
     * started_at/ended_at are the only inputs, so calling this again on an
     * already-rolled-up session reproduces the same numbers from the same
     * evidence rather than drifting.
     */
    public function rollup(UserWorkSession $session): UserWorkSession
    {
        $closesAt = $session->ended_at ?? now();

        // A session can close while somebody is still mid-break (forced
        // logout, the stale sweep). Closing that break out here rather than
        // leaving break_started_at dangling is what keeps that stretch of time
        // out of idle below - an open-ended break must not silently become
        // "no evidence of anything", which is what an idle read would imply.
        $this->finalizeOpenBreak($session, $closesAt);

        $active = $this->activeSeconds($session, $closesAt);
        $loggedIn = max(0, $session->started_at->diffInSeconds($closesAt));

        /*
         * Idle is the RESIDUAL (GLOSSARY §2.3: Logged-in − Active − Break), not
         * summed from ping gaps directly. That is what makes "explicit break
         * windows are excluded from idle" true for free: break_seconds is
         * already subtracted out here, so the gap math above never needs to
         * know a break happened partway through it.
         */
        $idle = max(0, $loggedIn - $active - $session->break_seconds);

        $session->forceFill([
            'active_seconds' => $active,
            'idle_seconds' => $idle,
        ])->save();

        return $session->fresh();
    }

    /**
     * Active time is the sum of gaps between consecutive pings, each capped at
     * the idle threshold - a gap longer than that is silence, not work, however
     * active the ping on either side of it looked (GLOSSARY §2.3). Time before
     * the first ping or after the last is not credited either: neither has a
     * later/earlier ping to prove activity across it, and crediting it would be
     * inventing evidence the same way CloseStaleWorkSessionsCommand refuses to.
     */
    private function activeSeconds(UserWorkSession $session, Carbon $closesAt): int
    {
        // config(), not SettingsService: FR-ATT-03's own spec names this exact
        // key, and the stale-session sweep this rollup runs alongside reads its
        // own threshold the same direct way.
        $thresholdSeconds = max(0, (int) config('crm.idle_threshold_minutes')) * 60;

        $occurredAt = $session->pings()->orderBy('occurred_at')->pluck('occurred_at')->values();

        $active = 0;

        for ($i = 1; $i < $occurredAt->count(); $i++) {
            $gap = $occurredAt[$i - 1]->diffInSeconds($occurredAt[$i]);
            $active += min($gap, $thresholdSeconds);
        }

        return $active;
    }

    private function finalizeOpenBreak(UserWorkSession $session, Carbon $closesAt): void
    {
        if ($session->break_started_at === null) {
            return;
        }

        $elapsed = max(0, $session->break_started_at->diffInSeconds($closesAt));

        $session->forceFill([
            'break_seconds' => $session->break_seconds + $elapsed,
            'break_started_at' => null,
        ])->save();
    }
}
