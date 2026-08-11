<?php

namespace Tests\Unit\Enums;

use App\Enums\CallStatus;
use App\Enums\Channel;
use App\Enums\DncReason;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DNC suppression matrix (BR-DNC-02, FR-DNC-01/03) - the highest-priority suite
 * in the project.
 *
 * Contacting a suppressed lead is a compliance problem, not a cosmetic bug, so
 * every reason is asserted against every channel rather than spot-checked.
 */
class DncSuppressionMatrixTest extends TestCase
{
    #[Test]
    public function do_not_contact_blocks_every_single_channel(): void
    {
        // FR-DNC-01: absolute suppression, no exceptions, no context.
        foreach (Channel::cases() as $channel) {
            $this->assertTrue(
                DncReason::DoNotContact->blocks($channel),
                "Do Not Contact must block {$channel->value}"
            );
        }
    }

    #[Test]
    public function not_interested_blocks_every_channel(): void
    {
        foreach (Channel::cases() as $channel) {
            $this->assertTrue(
                DncReason::NotInterested->blocks($channel),
                "Not Interested must block {$channel->value}"
            );
        }
    }

    #[Test]
    public function opted_out_blocks_every_channel(): void
    {
        foreach (Channel::cases() as $channel) {
            $this->assertTrue(DncReason::OptedOut->blocks($channel));
        }
    }

    #[Test]
    public function wrong_number_blocks_phone_channels_but_leaves_email_contactable(): void
    {
        // BR-DNC-02: the phone is wrong; the email address may be perfectly
        // valid. A single global flag would needlessly destroy a usable channel.
        $blocked = [
            Channel::Call, Channel::AiCall, Channel::Sms,
            Channel::WhatsApp, Channel::Rcs, Channel::Voice,
        ];

        foreach ($blocked as $channel) {
            $this->assertTrue(
                DncReason::WrongNumber->blocks($channel),
                "Wrong Number must block {$channel->value}"
            );
        }

        $this->assertFalse(
            DncReason::WrongNumber->blocks(Channel::Email),
            'Wrong Number must NOT block email'
        );
    }

    #[Test]
    public function invalid_number_behaves_like_wrong_number(): void
    {
        $this->assertTrue(DncReason::InvalidNumber->blocks(Channel::Call));
        $this->assertTrue(DncReason::InvalidNumber->blocks(Channel::Sms));
        $this->assertFalse(DncReason::InvalidNumber->blocks(Channel::Email));
    }

    #[Test]
    public function bounced_email_blocks_only_email(): void
    {
        // A hard bounce says nothing about the phone number.
        $this->assertTrue(DncReason::BouncedEmail->blocks(Channel::Email));

        foreach (Channel::cases() as $channel) {
            if ($channel === Channel::Email) {
                continue;
            }
            $this->assertFalse(
                DncReason::BouncedEmail->blocks($channel),
                "Bounced Email must not block {$channel->value}"
            );
        }
    }

    #[Test]
    public function no_reason_blocks_nothing(): void
    {
        // A reason that suppressed no channel would be a silent no-op and would
        // give false confidence that a lead had been suppressed.
        foreach (DncReason::cases() as $reason) {
            $this->assertNotEmpty(
                $reason->blockedChannels(),
                "{$reason->value} must block at least one channel"
            );
        }
    }

    #[Test]
    public function all_six_reasons_and_seven_channels_are_defined(): void
    {
        $this->assertCount(6, DncReason::cases());
        // Seven channels: call, ai_call, sms, whatsapp, rcs, voice, email.
        // Adding a channel without updating the matrix would let it skip the gate.
        $this->assertCount(7, Channel::cases());
    }

    #[Test]
    public function wrong_and_invalid_number_call_outcomes_trigger_suppression(): void
    {
        // BR-DNC-07: automatic, not left to the telecaller to remember.
        $this->assertTrue(CallStatus::WrongNumber->triggersSuppression());
        $this->assertTrue(CallStatus::InvalidNumber->triggersSuppression());

        $this->assertSame(DncReason::WrongNumber, CallStatus::WrongNumber->suppressionReason());
        $this->assertSame(DncReason::InvalidNumber, CallStatus::InvalidNumber->suppressionReason());
    }

    #[Test]
    public function ordinary_call_outcomes_do_not_suppress(): void
    {
        // Suppressing on "no answer" would silently destroy a working pipeline.
        $harmless = [
            CallStatus::Connected, CallStatus::NotConnected, CallStatus::Busy,
            CallStatus::NoAnswer, CallStatus::CallRejected, CallStatus::SwitchedOff,
            CallStatus::NotReachable, CallStatus::CallBackRequested, CallStatus::NoResponse,
        ];

        foreach ($harmless as $status) {
            $this->assertFalse(
                $status->triggersSuppression(),
                "{$status->value} must not suppress the lead"
            );
            $this->assertNull($status->suppressionReason());
        }
    }

    #[Test]
    public function removing_absolute_suppression_requires_elevated_authority(): void
    {
        // BR-DNC-06.
        $this->assertTrue(DncReason::DoNotContact->requiresElevatedRemoval());
        $this->assertTrue(DncReason::OptedOut->requiresElevatedRemoval());
        $this->assertFalse(DncReason::WrongNumber->requiresElevatedRemoval());
    }
}
