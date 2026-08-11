<?php

namespace Tests\Unit\Enums;

use App\Enums\PaymentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Payment status matrix (BR-PAY-02) and the collected-vs-booked distinction
 * (GLOSSARY §2.5).
 */
class PaymentStatusTransitionTest extends TestCase
{
    #[Test]
    public function it_defines_the_six_required_states(): void
    {
        $this->assertEqualsCanonicalizing(
            ['pending', 'partial', 'paid', 'failed', 'overdue', 'refund'],
            PaymentStatus::values()
        );
    }

    #[Test]
    public function refund_is_terminal(): void
    {
        $this->assertSame([], PaymentStatus::Refund->allowedTransitions());

        foreach (PaymentStatus::cases() as $target) {
            $this->assertFalse(PaymentStatus::Refund->canTransitionTo($target));
        }
    }

    #[Test]
    public function partial_can_repeat_for_successive_instalments(): void
    {
        // Instalment plans are normal - Partial -> Partial must be legal.
        $this->assertTrue(PaymentStatus::Partial->canTransitionTo(PaymentStatus::Partial));
    }

    #[Test]
    public function a_failed_payment_can_be_retried(): void
    {
        $this->assertTrue(PaymentStatus::Failed->canTransitionTo(PaymentStatus::Pending));

        // But a failed payment cannot jump straight to paid without going
        // through a fresh attempt.
        $this->assertFalse(PaymentStatus::Failed->canTransitionTo(PaymentStatus::Paid));
    }

    #[Test]
    public function a_paid_payment_can_only_be_refunded(): void
    {
        $this->assertTrue(PaymentStatus::Paid->canTransitionTo(PaymentStatus::Refund));

        foreach ([PaymentStatus::Pending, PaymentStatus::Partial, PaymentStatus::Failed, PaymentStatus::Overdue] as $target) {
            $this->assertFalse(
                PaymentStatus::Paid->canTransitionTo($target),
                "Paid must not revert to {$target->value}"
            );
        }
    }

    #[Test]
    public function an_overdue_payment_can_still_be_settled(): void
    {
        $this->assertTrue(PaymentStatus::Overdue->canTransitionTo(PaymentStatus::Partial));
        $this->assertTrue(PaymentStatus::Overdue->canTransitionTo(PaymentStatus::Paid));
    }

    #[Test]
    public function only_partial_and_paid_count_as_collected_revenue(): void
    {
        // GLOSSARY §2.5: booked value and collected revenue are different
        // numbers. Counting pending or overdue as revenue would overstate cash.
        $this->assertTrue(PaymentStatus::Partial->countsAsCollected());
        $this->assertTrue(PaymentStatus::Paid->countsAsCollected());

        foreach ([PaymentStatus::Pending, PaymentStatus::Failed, PaymentStatus::Overdue, PaymentStatus::Refund] as $status) {
            $this->assertFalse(
                $status->countsAsCollected(),
                "{$status->value} must not count as collected revenue"
            );
        }
    }

    #[Test]
    public function conversion_requires_money_actually_received(): void
    {
        // BR-PAY-05: a lead is only Converted once a sale has a real payment.
        $this->assertTrue(PaymentStatus::Paid->satisfiesConversion());
        $this->assertTrue(PaymentStatus::Partial->satisfiesConversion());
        $this->assertFalse(PaymentStatus::Pending->satisfiesConversion());
        $this->assertFalse(PaymentStatus::Overdue->satisfiesConversion());
    }
}
