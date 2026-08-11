<?php

namespace App\Enums;

/**
 * Call outcomes (FR-CALL-01). Eleven values, exactly as specified.
 *
 * Two behaviours are attached here because they must fire consistently no
 * matter which client logged the call (BR-CALL-05, BR-DNC-07).
 */
enum CallStatus: string
{
    case Connected = 'connected';
    case NotConnected = 'not_connected';
    case Busy = 'busy';
    case NoAnswer = 'no_answer';
    case CallRejected = 'call_rejected';
    case SwitchedOff = 'switched_off';
    case NotReachable = 'not_reachable';
    case InvalidNumber = 'invalid_number';
    case CallBackRequested = 'call_back_requested';
    case NoResponse = 'no_response';
    case WrongNumber = 'wrong_number';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::NotConnected => 'Not Connected',
            self::Busy => 'Busy',
            self::NoAnswer => 'No Answer',
            self::CallRejected => 'Call Rejected',
            self::SwitchedOff => 'Switched Off',
            self::NotReachable => 'Not Reachable',
            self::InvalidNumber => 'Invalid Number',
            self::CallBackRequested => 'Call Back Requested',
            self::NoResponse => 'No Response',
            self::WrongNumber => 'Wrong Number',
        };
    }

    /**
     * Only connected calls count toward talk time and Average Call Duration
     * (GLOSSARY §2.2).
     */
    public function isConnected(): bool
    {
        return $this === self::Connected;
    }

    /**
     * Outcomes that automatically suppress the lead (BR-DNC-07). This is not
     * optional and not the telecaller's decision - a wrong number must never be
     * dialled again.
     */
    public function triggersSuppression(): bool
    {
        return in_array($this, [self::WrongNumber, self::InvalidNumber], true);
    }

    /** The DNC reason to record when triggersSuppression() is true. */
    public function suppressionReason(): ?DncReason
    {
        return match ($this) {
            self::WrongNumber => DncReason::WrongNumber,
            self::InvalidNumber => DncReason::InvalidNumber,
            default => null,
        };
    }

    /** Outcomes that should automatically create a follow-up (BR-CALL-05). */
    public function triggersFollowUp(): bool
    {
        return $this === self::CallBackRequested;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
