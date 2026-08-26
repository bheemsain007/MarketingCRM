<?php

namespace App\Services\Attendance;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Lead;
use App\Models\UserActivityPing;
use App\Models\UserWorkSession;

/**
 * Turns tracked user actions into attendance signals (FR-ATT-02, GLOSSARY §2.3).
 *
 * A ping is only ever written against a session already open for the acting
 * user. An action with no actor, or an actor with no open session - a webhook,
 * a queued campaign dispatch - is real work product but no evidence anyone was
 * at a desk, so it is left unping'd rather than inventing attendance for it.
 */
class AttendancePingService
{
    /**
     * Lead-timeline activity types FR-ATT-02 tracks (calls, notes, status
     * changes, sends, follow-ups), mapped to the user_activity_pings
     * action_type they count as. Anything not listed here - lead_created,
     * sale_recorded, payment_recorded, meta_lead_captured, ... - is a
     * record-keeping event rather than one of the five tracked actions, and
     * writes no ping.
     */
    private const TRACKED_ACTIVITY_TYPES = [
        'call_started' => 'call',
        'call_completed' => 'call',
        'note' => 'note',
        'status_changed' => 'status_change',
        'message_queued' => 'message',
        'message_skipped' => 'message',
        'follow_up_scheduled' => 'follow_up',
        'follow_up_rescheduled' => 'follow_up',
        'follow_up_completed' => 'follow_up',
        'follow_up_cancelled' => 'follow_up',
    ];

    /**
     * Called from LeadService::recordActivity() for every lead-timeline write,
     * so a tracked action cannot reach the timeline without also reaching
     * attendance - the same argument that puts every lead creation through one
     * service (FR-ATT-02).
     */
    public function recordForLeadActivity(?int $actorId, string $activityType, Lead $lead): void
    {
        $actionType = self::TRACKED_ACTIVITY_TYPES[$activityType] ?? null;

        if ($actionType === null || $actorId === null) {
            return;
        }

        $session = $this->openSessionFor($actorId);

        if ($session === null) {
            return;
        }

        UserActivityPing::create([
            'user_id' => $actorId,
            'work_session_id' => $session->id,
            'action_type' => $actionType,
            'reference_type' => Lead::class,
            'reference_id' => $lead->id,
            'occurred_at' => now(),
        ]);
    }

    /**
     * The client-side heartbeat (FR-ATT-02's page_view signal) - a generic
     * "user is present" signal with no lead action to hang a ping on, so unlike
     * recordForLeadActivity() a missing session is refused rather than silently
     * dropped: the caller asked specifically to record presence, and presence
     * with nowhere to record it is worth surfacing, not swallowing.
     *
     * @throws ApiException when the caller has no open work session
     */
    public function recordHeartbeat(int $actorId): UserActivityPing
    {
        $session = $this->openSessionFor($actorId);

        if ($session === null) {
            throw new ApiException(
                ErrorCode::NotFound,
                'You have no open work session to record activity against.',
            );
        }

        return UserActivityPing::create([
            'user_id' => $actorId,
            'work_session_id' => $session->id,
            'action_type' => 'page_view',
            'occurred_at' => now(),
        ]);
    }

    /**
     * The caller may hold more than one open session (web and Android both
     * signed in); the most recently opened one is where they are acting right
     * now, so that is the one a signal attaches to.
     */
    private function openSessionFor(int $userId): ?UserWorkSession
    {
        return UserWorkSession::open()
            ->where('user_id', $userId)
            ->latest('started_at')
            ->first();
    }
}
