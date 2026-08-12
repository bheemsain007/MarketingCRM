<?php

namespace App\Services\Calls;

use App\Enums\CallStatus;
use App\Enums\Channel;
use App\Enums\ErrorCode;
use App\Enums\InterestSignalType;
use App\Exceptions\ApiException;
use App\Models\Call;
use App\Models\Lead;
use App\Models\User;
use App\Services\Calls\AiCalling\VaaadClient;
use App\Services\Interest\InterestEngine;

/**
 * AI calling orchestration (Phase 24/25, FR-AI-01, BR-INT-04).
 *
 * The Web CRM decides whether the call is allowed and records it; Vaaad places
 * it and reports back. So this dials through the same `CallService` gate every
 * other channel uses (DNC on the AI-call channel, calling hours in the lead's
 * timezone) - the gate is not re-implemented here, only invoked on the right
 * channel before a provider request is spent.
 *
 * Optional by design: with no Vaaad key the feature refuses with a clear
 * message rather than half-working (SEC-CFG-04). Add the credential in Settings
 * and it works.
 */
class AiCallService
{
    public function __construct(
        private readonly CallService $calls,
        private readonly VaaadClient $vaaad,
        private readonly InterestEngine $interest,
    ) {}

    /**
     * Places an AI call for a lead and records the dial intent.
     *
     * @param  array<string, mixed>  $options  script, product_id
     *
     * @throws ApiException when AI calling is off, or the lead may not be called
     */
    public function initiate(Lead $lead, User $actor, array $options = []): Call
    {
        if (! $this->vaaad->isConfigured()) {
            // The "unkeyed" state made explicit: not a silent no-op, a refusal
            // that tells the operator exactly what is missing.
            throw new ApiException(
                ErrorCode::ProviderUnavailable,
                'AI calling is not configured. Add the Vaaad API key in Settings to enable it.',
            );
        }

        // Gate BEFORE placing the call, so a suppressed or out-of-hours lead
        // never costs a provider request and never leaves an orphan call at
        // Vaaad. The gate runs on the AI-call channel specifically.
        $this->calls->assertCallable($lead, Channel::AiCall);

        $externalId = $this->vaaad->placeCall($lead, $options['script'] ?? null);

        return $this->calls->initiate($lead, $actor, [
            'channel' => Channel::AiCall,
            'dial_source' => 'ai',
            'external_call_id' => $externalId,
            'product_id' => $options['product_id'] ?? null,
        ]);
    }

    /**
     * Ingests a Vaaad result (Phase 25, FR-AI-01, BR-INT-04).
     *
     * Two things arrive together and are applied separately: the call OUTCOME
     * (which feeds talk time and the write-once call record) and, when the model
     * heard interest, an AI interest SIGNAL. The signal is recorded with its
     * confidence and the InterestEngine decides what to do with it - below the
     * configured threshold it is kept as evidence but does not move the lead's
     * status (BR-INT-04). This service never second-guesses that threshold.
     *
     * @param  array<string, mixed>  $payload
     */
    public function ingestResult(Call $call, array $payload): void
    {
        // The write-once outcome. A redelivered webhook finds the call already
        // has a status and CallService refuses to overwrite it, so this is
        // naturally idempotent.
        if ($call->status === null) {
            $status = CallStatus::tryFrom((string) ($payload['outcome'] ?? $payload['status'] ?? ''))
                ?? CallStatus::NoResponse;

            $this->calls->recordOutcome($call, $status, [
                'duration_seconds' => isset($payload['duration_seconds'])
                    ? (int) $payload['duration_seconds']
                    : null,
                'notes' => $payload['summary'] ?? null,
                'external_call_id' => $call->external_call_id,
            ]);
        }

        $lead = $call->lead;

        if ($lead === null || ! array_key_exists('interest_confidence', $payload)) {
            return;
        }

        // The AI heard something. Record it as an AI signal with its confidence;
        // the engine applies BR-INT-04, not this method.
        $this->interest->record($lead, InterestSignalType::AiInterestDetected, null, [
            'channel' => Channel::AiCall->value,
            'source' => 'ai',
            'confidence' => (float) $payload['interest_confidence'],
            'excerpt' => $payload['summary'] ?? null,
            'product_id' => $payload['product_id'] ?? null,
        ]);
    }
}
