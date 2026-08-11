<?php

namespace App\Enums;

/**
 * Why the dialer passed over a lead (FR-CALL-07, BR-CALL-02).
 *
 * Every skip is recorded with one of these. A lead that quietly disappeared
 * from a run is indistinguishable from one that was never queued, and
 * "the dialer never rang this person" is exactly the complaint that needs an
 * answer six weeks later.
 *
 * They also separate the *compliance* skips from the *scheduling* ones:
 * `Suppressed` means we are not allowed to call, `Cooldown` means we chose not
 * to yet. Reporting the two together would hide how much of a list is legally
 * unreachable.
 */
enum DialerSkipReason: string
{
    case Suppressed = 'suppressed';
    case NoPhone = 'no_phone';
    case Cooldown = 'cooldown';
    case FollowUpScheduled = 'follow_up_scheduled';
    case OutsideCallingHours = 'outside_calling_hours';
    case ClaimedElsewhere = 'claimed_elsewhere';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Suppressed => 'On the do-not-contact list',
            self::NoPhone => 'No usable phone number',
            self::Cooldown => 'Contacted too recently',
            self::FollowUpScheduled => 'Follow-up already scheduled',
            self::OutsideCallingHours => 'Outside calling hours',
            self::ClaimedElsewhere => 'Being called by someone else',
            self::Archived => 'Lead archived',
        };
    }

    /**
     * True when the lead may become dialable again later in the same run -
     * a cooldown expires, calling hours reopen, a colleague finishes.
     *
     * Permanent skips (suppressed, no number) will never change, so a UI can
     * tell an operator which part of a list is recoverable and which is not.
     */
    public function isTemporary(): bool
    {
        return in_array($this, [
            self::Cooldown,
            self::FollowUpScheduled,
            self::OutsideCallingHours,
            self::ClaimedElsewhere,
        ], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
