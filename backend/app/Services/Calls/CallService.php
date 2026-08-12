<?php

namespace App\Services\Calls;

use App\Enums\CallStatus;
use App\Enums\Channel;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Call;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Services\Dnc\DncService;
use App\Services\Leads\LeadService;
use App\Support\CallingHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Calling (Phase 9 — FR-CALL-01..05, FR-CALL-08, BR-CALL-01/04/05).
 *
 * Under ADR-B the Web CRM is the system of record and the orchestrator: it
 * decides whether a call is *allowed*, creates the record and the dial intent,
 * and ingests the outcome the device reports back. It does not place the call.
 *
 * That split is why the gates live here rather than in a controller or an app:
 * the Android client, the auto dialer (Phase 10) and AI calling (Phase 24) all
 * dial through this service, and a gate implemented in any one of them would
 * be missing from the other two.
 */
class CallService
{
    public function __construct(
        private readonly DncService $dnc,
        private readonly LeadService $leads,
    ) {}

    /**
     * Creates the call record and the dial intent (FR-CALL-03).
     *
     * The returned call has **no status**: it has been dialled and nothing is
     * yet known about how it went. The outcome arrives later through
     * `recordOutcome()`.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ApiException when the lead may not be called
     */
    public function initiate(Lead $lead, User $actor, array $options = []): Call
    {
        // AI calling dials the same lead through the same gate but on its own
        // channel (Phase 24): a lead suppressed for AI calls specifically must
        // be stopped here, not only one suppressed for human calls.
        $this->guardCallable($lead, $options['channel'] ?? Channel::Call);

        return DB::transaction(function () use ($lead, $actor, $options) {
            $call = Call::create([
                'tenant_id' => config('crm.default_tenant_id'),
                'lead_id' => $lead->id,
                'user_id' => $actor->id,
                'product_id' => $options['product_id'] ?? null,
                'direction' => 'outbound',
                // Null until the device reports back - see the migration note.
                'status' => null,
                'started_at' => now(),
                'dial_source' => $options['dial_source'] ?? 'manual',
                'auto_dialer_session_id' => $options['auto_dialer_session_id'] ?? null,
                'follow_up_id' => $options['follow_up_id'] ?? null,
                'external_call_id' => $options['external_call_id'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->leads->recordActivity(
                $lead,
                $actor->id,
                'call_started',
                'Call started',
                ['call_id' => $call->id, 'dial_source' => $call->dial_source],
            );

            return $call;
        });
    }

    /**
     * Records what happened, and everything that follows from it (BR-CALL-05).
     *
     * The side effects are not the caller's responsibility precisely because
     * they are the ones most easily forgotten: a wrong number that does not
     * suppress gets dialled again tomorrow, and a callback request that creates
     * no follow-up is a promise nobody keeps.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ApiException when the call already has an outcome
     */
    public function recordOutcome(Call $call, CallStatus $status, array $data = [], ?User $actor = null): Call
    {
        if ($call->status !== null) {
            /*
             * Write-once. A call record is evidence - it feeds talk time,
             * telecaller pay and any disputed conversion - so an outcome that
             * could be edited later is an outcome nobody can rely on. A
             * genuine mis-tap is corrected by a note, not by rewriting history.
             */
            throw new ApiException(
                ErrorCode::Conflict,
                'This call already has an outcome recorded.',
                context: ['current' => $call->status->value],
            );
        }

        $endedAt = isset($data['ended_at'])
            ? CarbonImmutable::parse($data['ended_at'])
            : CarbonImmutable::now();

        $duration = isset($data['duration_seconds'])
            ? max(0, (int) $data['duration_seconds'])
            : max(0, $endedAt->diffInSeconds($call->started_at ?? $endedAt));

        return DB::transaction(function () use ($call, $status, $data, $actor, $endedAt, $duration) {
            $call->forceFill([
                'status' => $status,
                'ended_at' => $endedAt,
                // Only connected calls carry meaningful talk time
                // (GLOSSARY §2.2); the rest are attempts, not conversations.
                'duration_seconds' => $status->isConnected() ? $duration : 0,
                'notes' => $data['notes'] ?? $call->notes,
                'external_call_id' => $data['external_call_id'] ?? $call->external_call_id,
            ])->save();

            $lead = $call->lead;

            // Any attempt counts as contact for recency and leakage reporting
            // (GLOSSARY §2.9) - "we tried" is the fact being recorded, and a
            // lead nobody reached is not a lead nobody touched.
            $lead->forceFill(['last_contacted_at' => now()])->save();

            $this->applySuppression($call, $lead, $status, $actor);
            $followUp = $this->applyFollowUp($call, $lead, $status, $data, $actor);

            $this->leads->recordActivity(
                $lead,
                $actor?->id,
                'call_completed',
                sprintf('Call: %s', $status->label()),
                array_filter([
                    'call_id' => $call->id,
                    'status' => $status->value,
                    'duration_seconds' => $call->duration_seconds,
                    'follow_up_id' => $followUp?->id,
                ]),
            );

            return $call->fresh();
        });
    }

    /**
     * Logs a call that has already happened, in one step.
     *
     * The common case for a telecaller who dialled from their handset and is
     * now writing it up.
     *
     * @param  array<string, mixed>  $data
     */
    public function log(Lead $lead, User $actor, CallStatus $status, array $data = []): Call
    {
        $call = $this->initiate($lead, $actor, $data);

        return $this->recordOutcome($call, $status, $data, $actor);
    }

    /**
     * Whether this lead may be dialled right now, and why not if not.
     *
     * Lets a UI grey out the call button with a reason instead of letting a
     * telecaller find out by being refused.
     *
     * @return array{callable: bool, reason: ?string, message: ?string, next_opening: ?string}
     */
    public function callability(Lead $lead): array
    {
        try {
            $this->guardCallable($lead);
        } catch (ApiException $e) {
            $context = is_array($e->context) ? $e->context : [];

            return [
                'callable' => false,
                'reason' => $e->errorCode->value,
                'message' => $e->getMessage(),
                'next_opening' => $context['next_opening'] ?? null,
            ];
        }

        return ['callable' => true, 'reason' => null, 'message' => null, 'next_opening' => null];
    }

    // -----------------------------------------------------------------------
    // Gates
    // -----------------------------------------------------------------------

    /**
     * Public gate check, so a caller that places the call elsewhere (AI calling
     * through Vaaad, Phase 24) can refuse a suppressed or out-of-hours lead
     * BEFORE spending a provider request - rather than placing the call and then
     * discovering it should not have.
     *
     * @throws ApiException
     */
    public function assertCallable(Lead $lead, Channel $channel = Channel::Call): void
    {
        $this->guardCallable($lead, $channel);
    }

    /**
     * Everything that must be true before a number is dialled.
     *
     * @throws ApiException
     */
    private function guardCallable(Lead $lead, Channel $channel = Channel::Call): void
    {
        if ($lead->trashed()) {
            throw new ApiException(ErrorCode::LeadArchived);
        }

        if (blank($lead->phone_e164)) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'This lead has no phone number to call.',
            );
        }

        /*
         * FR-CALL-08 / BR-CALL-01: the DNC check runs before EVERY dial, and it
         * reads dnc_entries through DncService - never `leads.is_suppressed`,
         * which is a list-filter cache that can lag (BR-DNC-01, ADR-E).
         *
         * This is the single most important line in the calling module. A
         * suppressed lead being dialled is a regulatory problem, not a bug
         * report.
         */
        if (! $this->dnc->canContact($lead, $channel)) {
            throw new ApiException(
                ErrorCode::DncSuppressed,
                'This lead is on the do-not-contact list for calls.',
            );
        }

        // BR-CALL-04: outside the window the attempt is deferred, not dropped -
        // so the refusal carries the time it becomes allowed.
        $hours = CallingHours::fromConfig();

        if (! $hours->isOpenFor($lead)) {
            throw new ApiException(
                ErrorCode::CallOutsideCallingHours,
                sprintf('Calling is only permitted between %s.', $hours->describeFor($lead)),
                context: [
                    'next_opening' => $hours->nextOpeningFor($lead)->toIso8601String(),
                    'lead_timezone' => $hours->timezoneFor($lead),
                ],
            );
        }
    }

    // -----------------------------------------------------------------------
    // Outcome side effects (BR-CALL-05)
    // -----------------------------------------------------------------------

    private function applySuppression(Call $call, Lead $lead, CallStatus $status, ?User $actor): void
    {
        if (! $status->triggersSuppression()) {
            return;
        }

        /*
         * BR-DNC-07. Not the telecaller's decision and not a prompt: a number
         * confirmed wrong or invalid must never be dialled again, and leaving
         * that to somebody remembering to tick a box is how it gets dialled
         * again. The reason decides which channels are blocked - a wrong phone
         * number leaves a valid email address contactable (BR-DNC-02).
         */
        $this->dnc->suppress(
            $lead,
            $status->suppressionReason(),
            channel: null,
            source: 'call_outcome',
            actorId: $actor?->id,
            note: sprintf('Call #%d outcome: %s', $call->id, $status->label()),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyFollowUp(Call $call, Lead $lead, CallStatus $status, array $data, ?User $actor): ?FollowUp
    {
        if (! $status->triggersFollowUp()) {
            return null;
        }

        /*
         * A callback request with no follow-up is a promise nobody keeps, so
         * the time is defaulted rather than the follow-up being skipped when
         * the caller forgets to supply one. The default is the next moment the
         * lead may legally be called (BR-CALL-04).
         */
        $scheduledAt = isset($data['callback_at'])
            ? CarbonImmutable::parse($data['callback_at'])
            : CallingHours::fromConfig()->nextOpeningFor($lead, CarbonImmutable::now()->addHours(2));

        $followUp = FollowUp::create([
            'tenant_id' => config('crm.default_tenant_id'),
            'lead_id' => $lead->id,
            'product_id' => $call->product_id,
            // Stays with the telecaller who took the call - the lead asked
            // *them* to ring back.
            'assigned_to' => $call->user_id ?? $lead->assigned_to,
            'channel' => Channel::Call,
            'scheduled_at' => $scheduledAt,
            'status' => 'open',
            'subject' => 'Callback requested',
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor?->id,
        ]);

        $call->forceFill(['follow_up_id' => $followUp->id])->save();

        return $followUp;
    }
}
