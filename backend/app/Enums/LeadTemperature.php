<?php

namespace App\Enums;

/**
 * Lead temperature (BR-TEMP-01/02).
 *
 * DERIVED, never freely set. The Interest Engine (Phase 20) recalculates it from
 * score plus recency. Recency is a GATE, not a bonus: a high-scoring lead nobody
 * has touched in 60 days is not Hot.
 *
 * Bands are PROPOSED and awaiting sign-off (docs/TODO.md).
 */
enum LeadTemperature: string
{
    case Hot = 'hot';
    case Warm = 'warm';
    case Cold = 'cold';
    case Dormant = 'dormant';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Derive temperature from score and days since last engagement.
     *
     * @param  int  $score  0-100 (BR-SCORE-01)
     * @param  int|null  $daysSinceEngagement  null = never engaged
     */
    public static function derive(int $score, ?int $daysSinceEngagement, bool $isSuppressed = false): self
    {
        // Suppressed leads are dormant regardless of how warm they once were.
        if ($isSuppressed) {
            return self::Dormant;
        }

        $days = $daysSinceEngagement ?? PHP_INT_MAX;

        if ($days > 90) {
            return self::Dormant;
        }

        if ($score >= 70 && $days <= 7) {
            return self::Hot;
        }

        if ($score >= 40 && $days <= 30) {
            return self::Warm;
        }

        return self::Cold;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
