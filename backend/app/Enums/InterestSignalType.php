<?php

namespace App\Enums;

/**
 * Everything that moves a lead's score (BR-SCORE-01, BR-INT-01/03).
 *
 * A closed set, because the score has to be **explainable**: a telecaller must
 * be able to see why a lead is Hot, and "why" is a list of these with their
 * points. Free-form signal names would make the breakdown unreadable and the
 * points table impossible to keep in step.
 *
 * The points themselves live in `config('crm.scoring')`, not here - BR-SCORE-01
 * is still a proposal awaiting sign-off (T-16), and re-weighting the model
 * should not be a deployment.
 */
enum InterestSignalType: string
{
    // Positive
    case CallConnected = 'call_connected';
    case InterestStated = 'interest_stated';
    case AiInterestDetected = 'ai_interest_detected';
    case InboundReply = 'inbound_reply';
    case EmailOpened = 'email_opened';
    case EmailClicked = 'email_clicked';
    case FollowUpCompleted = 'follow_up_completed';
    case ProposalSent = 'proposal_sent';
    case NegotiationEntered = 'negotiation_entered';
    case CallbackRequested = 'callback_requested';

    // Negative
    case CallNoAnswer = 'call_no_answer';
    case FollowUpMissed = 'follow_up_missed';
    case NotInterested = 'not_interested';

    public function label(): string
    {
        return match ($this) {
            self::CallConnected => 'Connected call',
            self::InterestStated => 'Interest stated',
            self::AiInterestDetected => 'AI detected interest',
            self::InboundReply => 'Inbound reply',
            self::EmailOpened => 'Email opened',
            self::EmailClicked => 'Link clicked',
            self::FollowUpCompleted => 'Follow-up completed on time',
            self::ProposalSent => 'Proposal sent',
            self::NegotiationEntered => 'Negotiation entered',
            self::CallbackRequested => 'Callback requested',
            self::CallNoAnswer => 'No answer',
            self::FollowUpMissed => 'Follow-up missed',
            self::NotInterested => 'Not interested',
        };
    }

    /** Base points before any AI-confidence weighting. */
    public function points(): float
    {
        return (float) config('crm.scoring.signals.'.$this->value, 0);
    }

    /**
     * The cumulative floor for repeat negative signals, if any.
     *
     * BR-SCORE-01 caps "no answer" at -10 total: ten unanswered calls to a
     * genuinely busy prospect should not bury a lead that is otherwise warm.
     */
    public function cumulativeCap(): ?float
    {
        $cap = config('crm.scoring.caps.'.$this->value);

        return $cap === null ? null : (float) $cap;
    }

    /**
     * Whether this signal means the lead expressed interest.
     *
     * These are the ones that move status, apply the label and can create a
     * follow-up (FR-INT-02). A connected call is worth points but is not by
     * itself interest - somebody answering the phone has not said yes.
     */
    public function indicatesInterest(): bool
    {
        return in_array($this, [
            self::InterestStated,
            self::AiInterestDetected,
            self::InboundReply,
            self::CallbackRequested,
        ], true);
    }

    /** AI signals are weighted by confidence and gated by a threshold (BR-INT-04). */
    public function isAiDetected(): bool
    {
        return $this === self::AiInterestDetected;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
