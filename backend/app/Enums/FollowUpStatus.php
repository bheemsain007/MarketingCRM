<?php

namespace App\Enums;

/**
 * Follow-up lifecycle (FR-FUP-02/03, BR-FUP-01/02).
 *
 * `Missed` is set by the scheduler, never by a user - it is the difference
 * between "nobody got to it" and "somebody decided not to", and a telecaller
 * able to mark their own overdue follow-ups as anything they like would make
 * the missed-follow-up report worthless (BR-FUP-02).
 */
enum FollowUpStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Missed = 'missed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Completed => 'Completed',
            self::Missed => 'Missed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Only an open follow-up can be completed, rescheduled or cancelled. */
    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    /**
     * A missed follow-up is still actionable - it is late, not void. Completing
     * one late is exactly what should happen, and refusing would push people to
     * create a fresh follow-up and lose the miss from the record.
     */
    public function isActionable(): bool
    {
        return in_array($this, [self::Open, self::Missed], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
