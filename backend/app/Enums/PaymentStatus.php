<?php

namespace App\Enums;

/**
 * Payment states and their transition matrix (BR-PAY-01/02).
 *
 * Partial -> Partial is intentional: successive instalments. Refund is terminal.
 * Failed -> Pending supports a retry.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Paid = 'paid';
    case Failed = 'failed';
    case Overdue = 'overdue';
    case Refund = 'refund';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Partial, self::Paid, self::Failed, self::Overdue],
            self::Partial => [self::Partial, self::Paid, self::Overdue, self::Refund],
            self::Paid => [self::Refund],
            self::Failed => [self::Pending],
            self::Overdue => [self::Partial, self::Paid, self::Failed],
            self::Refund => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Statuses that count toward collected revenue (GLOSSARY §2.5).
     * Booked value and collected revenue are separate numbers and must never be
     * summed together.
     */
    public function countsAsCollected(): bool
    {
        return in_array($this, [self::Partial, self::Paid], true);
    }

    /** A lead may only reach Converted once a sale has one of these (BR-PAY-05). */
    public function satisfiesConversion(): bool
    {
        return $this->countsAsCollected();
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
