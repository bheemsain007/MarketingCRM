<?php

namespace App\Enums;

/**
 * Lead statuses (BR-STAT-01, FR-STAT-01).
 *
 * The transition matrix (BR-STAT-02) lives here rather than in a service so
 * there is exactly one definition of what is legal. Phase 7 will enforce it via
 * a service; this enum is the authority it consults.
 *
 * NOTE: the matrix below is PROPOSED and awaiting stakeholder sign-off. It
 * encodes intent the original brief did not state - see docs/TODO.md.
 */
enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Interested = 'interested';
    case FollowUp = 'follow_up';
    case Callback = 'callback';
    case Proposal = 'proposal';
    case Negotiation = 'negotiation';
    case DecisionPending = 'decision_pending';
    case Converted = 'converted';
    case Lost = 'lost';
    case NotInterested = 'not_interested';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Interested => 'Interested',
            self::FollowUp => 'Follow-up',
            self::Callback => 'Callback',
            self::Proposal => 'Proposal',
            self::Negotiation => 'Negotiation',
            self::DecisionPending => 'Decision Pending',
            self::Converted => 'Converted',
            self::Lost => 'Lost',
            self::NotInterested => 'Not Interested',
        };
    }

    /**
     * Statuses this status may move to directly (BR-STAT-02).
     *
     * Forward skips are allowed where they reflect reality (New -> Interested on
     * a first connected call). Backward moves into the pipeline are not.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [
                self::Contacted, self::Interested, self::FollowUp,
                self::Callback, self::Lost, self::NotInterested,
            ],
            self::Contacted => [
                self::Interested, self::FollowUp, self::Callback,
                self::Proposal, self::DecisionPending, self::Lost, self::NotInterested,
            ],
            self::Interested => [
                self::Contacted, self::FollowUp, self::Callback, self::Proposal,
                self::Negotiation, self::DecisionPending, self::Lost, self::NotInterested,
            ],
            self::FollowUp => [
                self::Contacted, self::Interested, self::Callback, self::Proposal,
                self::Negotiation, self::DecisionPending, self::Lost, self::NotInterested,
            ],
            self::Callback => [
                self::Contacted, self::Interested, self::FollowUp, self::Proposal,
                self::Negotiation, self::DecisionPending, self::Lost, self::NotInterested,
            ],
            self::Proposal => [
                self::Interested, self::FollowUp, self::Callback, self::Negotiation,
                self::DecisionPending, self::Converted, self::Lost, self::NotInterested,
            ],
            self::Negotiation => [
                self::Interested, self::FollowUp, self::Callback, self::Proposal,
                self::DecisionPending, self::Converted, self::Lost, self::NotInterested,
            ],
            self::DecisionPending => [
                self::Interested, self::FollowUp, self::Callback, self::Proposal,
                self::Negotiation, self::Converted, self::Lost, self::NotInterested,
            ],

            // Converted is terminal. Repeat business creates a NEW opportunity
            // under the existing customer - it never reverts this status.
            self::Converted => [],

            // Reopens only. Manager+ authority and a reason are required; that
            // is enforced by the service layer, not here.
            self::Lost => [self::Contacted, self::Interested, self::NotInterested],
            self::NotInterested => [self::Contacted, self::Interested],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Reopen transitions require Manager+ and a documented reason (BR-STAT-02).
     * Reopening from NotInterested does NOT clear suppression - that is a
     * separate, separately-audited action (BR-DNC-06).
     */
    public function isReopenFrom(): bool
    {
        return in_array($this, [self::Lost, self::NotInterested], true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Converted;
    }

    /** Closed states - the lead is finished work, won or lost. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Converted, self::Lost, self::NotInterested], true);
    }

    /**
     * How far along the pipeline this status sits (BR-STAT-04).
     *
     * Used to answer "which of these is furthest advanced?" when deriving the
     * lead-level status from its products. Callback and Follow-up share a rank
     * deliberately: they are the same distance along the pipeline, differing
     * only in who initiates the next contact.
     *
     * Lost and Not Interested return -1. They are not *behind* New, they are
     * off the ladder entirely - ranking them as "less advanced" would let a
     * single interested product silently drag a lead the customer has
     * explicitly closed back into the pipeline.
     */
    public function pipelineRank(): int
    {
        return match ($this) {
            self::Lost, self::NotInterested => -1,
            self::New => 0,
            self::Contacted => 1,
            self::FollowUp, self::Callback => 2,
            self::Interested => 3,
            self::Proposal => 4,
            self::Negotiation => 5,
            self::DecisionPending => 6,
            self::Converted => 7,
        };
    }

    /** Statuses that automatically write a DNC entry (BR-DNC-07). */
    public function triggersSuppression(): bool
    {
        return $this === self::NotInterested;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
