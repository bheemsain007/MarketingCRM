<?php

namespace App\Enums;

/**
 * Deal outcome (FR-SALE-01/05, BR-SALE-02/04).
 *
 * Deliberately three states, not a pipeline. The *stage* a deal is at is the
 * LEAD's status (Proposal, Negotiation, Decision Pending) and lives in
 * `LeadStatus` - duplicating it here would create two sources of truth for
 * where a deal stands, and they would disagree the first time someone updated
 * one and not the other.
 */
enum OpportunityStatus: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    /**
     * Both closed states are terminal.
     *
     * Reopening a lost deal would make "how many did we lose, and why?"
     * unanswerable after the fact. Repeat business is a NEW opportunity under
     * the same customer (BR-CUST-03), which is also what BR-SALE-02 says about
     * conversion.
     */
    public function isClosed(): bool
    {
        return $this !== self::Open;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
