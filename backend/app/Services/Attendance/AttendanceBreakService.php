<?php

namespace App\Services\Attendance;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\UserWorkSession;

/**
 * Explicit break start/stop (FR-ATT-04). Kept apart from the rollup that reads
 * break_seconds back out - a break is marked live, in real time, by the person
 * taking it, which is a different concern from deriving totals once a session
 * is over (GLOSSARY §2.3).
 */
class AttendanceBreakService
{
    /**
     * @throws ApiException when the caller has no open session, or is already
     *                       on a break - starting a second break on top of one
     *                       already running would silently discard when the
     *                       first one actually began.
     */
    public function start(User $user): UserWorkSession
    {
        $session = $this->openSessionFor($user);

        if ($session === null) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'You have no open work session to start a break on.',
            );
        }

        if ($session->isOnBreak()) {
            throw new ApiException(ErrorCode::ValidationFailed, 'A break is already in progress.');
        }

        $session->forceFill(['break_started_at' => now()])->save();

        return $session->fresh();
    }

    /**
     * @throws ApiException when the caller is not currently on a break
     */
    public function stop(User $user): UserWorkSession
    {
        $session = $this->openSessionFor($user);

        if ($session === null || ! $session->isOnBreak()) {
            throw new ApiException(ErrorCode::ValidationFailed, 'You are not currently on a break.');
        }

        $elapsed = max(0, $session->break_started_at->diffInSeconds(now()));

        $session->forceFill([
            'break_seconds' => $session->break_seconds + $elapsed,
            'break_started_at' => null,
        ])->save();

        return $session->fresh();
    }

    private function openSessionFor(User $user): ?UserWorkSession
    {
        return UserWorkSession::open()
            ->where('user_id', $user->id)
            ->latest('started_at')
            ->first();
    }
}
