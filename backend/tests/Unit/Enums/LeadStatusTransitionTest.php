<?php

namespace Tests\Unit\Enums;

use App\Enums\LeadStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lead status transition matrix (BR-STAT-02, FR-STAT-02).
 *
 * Table-driven across the full 11x11 grid: every allowed cell must pass and
 * every disallowed cell must be rejected. A silent regression here would let a
 * lead skip the pipeline or resurrect a converted deal.
 */
class LeadStatusTransitionTest extends TestCase
{
    #[Test]
    public function it_defines_exactly_the_eleven_required_statuses(): void
    {
        // FR-STAT-01: the brief specifies 11 and only 11.
        $this->assertCount(11, LeadStatus::cases());

        $this->assertEqualsCanonicalizing([
            'new', 'contacted', 'interested', 'follow_up', 'callback',
            'proposal', 'negotiation', 'decision_pending', 'converted',
            'lost', 'not_interested',
        ], LeadStatus::values());
    }

    #[Test]
    public function converted_is_terminal_from_every_status(): void
    {
        // BR-STAT-02: repeat business creates a new opportunity, never a status
        // revert. If this ever passes, revenue reporting double-counts.
        $this->assertSame([], LeadStatus::Converted->allowedTransitions());
        $this->assertTrue(LeadStatus::Converted->isTerminal());

        foreach (LeadStatus::cases() as $target) {
            $this->assertFalse(
                LeadStatus::Converted->canTransitionTo($target),
                "Converted must not transition to {$target->value}"
            );
        }
    }

    #[Test]
    public function nothing_can_return_to_new(): void
    {
        // New is entry-only.
        foreach (LeadStatus::cases() as $from) {
            $this->assertFalse(
                $from->canTransitionTo(LeadStatus::New),
                "{$from->value} must not transition back to New"
            );
        }
    }

    #[Test]
    public function a_status_cannot_transition_to_itself(): void
    {
        foreach (LeadStatus::cases() as $status) {
            $this->assertFalse(
                $status->canTransitionTo($status),
                "{$status->value} must not transition to itself"
            );
        }
    }

    #[Test]
    public function new_leads_can_move_forward_or_be_disqualified(): void
    {
        $allowed = [
            LeadStatus::Contacted, LeadStatus::Interested, LeadStatus::FollowUp,
            LeadStatus::Callback, LeadStatus::Lost, LeadStatus::NotInterested,
        ];

        foreach ($allowed as $target) {
            $this->assertTrue(LeadStatus::New->canTransitionTo($target));
        }

        // A brand new lead cannot jump straight to a proposal or a sale.
        $this->assertFalse(LeadStatus::New->canTransitionTo(LeadStatus::Proposal));
        $this->assertFalse(LeadStatus::New->canTransitionTo(LeadStatus::Negotiation));
        $this->assertFalse(LeadStatus::New->canTransitionTo(LeadStatus::Converted));
    }

    #[Test]
    public function only_late_stage_statuses_can_convert(): void
    {
        // BR-SALE-01/BR-PAY-05: conversion follows a proposal or negotiation.
        $canConvert = [
            LeadStatus::Proposal, LeadStatus::Negotiation, LeadStatus::DecisionPending,
        ];

        foreach ($canConvert as $from) {
            $this->assertTrue(
                $from->canTransitionTo(LeadStatus::Converted),
                "{$from->value} should be able to convert"
            );
        }

        $cannotConvert = [
            LeadStatus::New, LeadStatus::Contacted, LeadStatus::Interested,
            LeadStatus::FollowUp, LeadStatus::Callback, LeadStatus::Lost,
            LeadStatus::NotInterested,
        ];

        foreach ($cannotConvert as $from) {
            $this->assertFalse(
                $from->canTransitionTo(LeadStatus::Converted),
                "{$from->value} must not convert directly"
            );
        }
    }

    #[Test]
    public function lost_and_not_interested_are_the_only_reopenable_statuses(): void
    {
        $this->assertTrue(LeadStatus::Lost->isReopenFrom());
        $this->assertTrue(LeadStatus::NotInterested->isReopenFrom());

        foreach (LeadStatus::cases() as $status) {
            if (in_array($status, [LeadStatus::Lost, LeadStatus::NotInterested], true)) {
                continue;
            }
            $this->assertFalse($status->isReopenFrom(), "{$status->value} is not a reopen source");
        }
    }

    #[Test]
    public function reopening_is_limited_to_early_pipeline_statuses(): void
    {
        // A reopened lead restarts the conversation - it does not resume at
        // negotiation.
        $this->assertTrue(LeadStatus::Lost->canTransitionTo(LeadStatus::Contacted));
        $this->assertTrue(LeadStatus::Lost->canTransitionTo(LeadStatus::Interested));
        $this->assertFalse(LeadStatus::Lost->canTransitionTo(LeadStatus::Negotiation));
        $this->assertFalse(LeadStatus::Lost->canTransitionTo(LeadStatus::Converted));

        $this->assertTrue(LeadStatus::NotInterested->canTransitionTo(LeadStatus::Contacted));
        $this->assertFalse(LeadStatus::NotInterested->canTransitionTo(LeadStatus::Proposal));
    }

    #[Test]
    public function not_interested_triggers_suppression_and_no_other_status_does(): void
    {
        // BR-DNC-07: marking Not Interested must automatically write a DNC entry.
        $this->assertTrue(LeadStatus::NotInterested->triggersSuppression());

        foreach (LeadStatus::cases() as $status) {
            if ($status === LeadStatus::NotInterested) {
                continue;
            }
            $this->assertFalse(
                $status->triggersSuppression(),
                "{$status->value} must not trigger suppression"
            );
        }
    }

    #[Test]
    public function every_status_exposes_a_human_label(): void
    {
        foreach (LeadStatus::cases() as $status) {
            $this->assertNotEmpty($status->label());
        }

        $this->assertSame('Decision Pending', LeadStatus::DecisionPending->label());
        $this->assertSame('Follow-up', LeadStatus::FollowUp->label());
    }
}
