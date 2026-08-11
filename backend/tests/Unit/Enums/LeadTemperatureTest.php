<?php

namespace Tests\Unit\Enums;

use App\Enums\LeadTemperature;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Temperature derivation (BR-TEMP-02).
 *
 * The rule being protected: recency is a GATE, not a bonus. A lead with a high
 * score that nobody has touched in months is not Hot, and treating it as Hot
 * sends telecallers to stale leads first.
 */
class LeadTemperatureTest extends TestCase
{
    #[Test]
    public function high_score_with_recent_engagement_is_hot(): void
    {
        $this->assertSame(LeadTemperature::Hot, LeadTemperature::derive(75, 2));
        $this->assertSame(LeadTemperature::Hot, LeadTemperature::derive(70, 7));
    }

    #[Test]
    public function high_score_with_stale_engagement_is_not_hot(): void
    {
        // The core rule. Score alone must never produce Hot.
        $this->assertNotSame(LeadTemperature::Hot, LeadTemperature::derive(95, 20));
        $this->assertSame(LeadTemperature::Warm, LeadTemperature::derive(95, 20));
        $this->assertSame(LeadTemperature::Cold, LeadTemperature::derive(95, 60));
    }

    #[Test]
    public function mid_score_within_thirty_days_is_warm(): void
    {
        $this->assertSame(LeadTemperature::Warm, LeadTemperature::derive(50, 15));
        $this->assertSame(LeadTemperature::Warm, LeadTemperature::derive(40, 30));
    }

    #[Test]
    public function low_score_is_cold_however_recent(): void
    {
        $this->assertSame(LeadTemperature::Cold, LeadTemperature::derive(10, 1));
        $this->assertSame(LeadTemperature::Cold, LeadTemperature::derive(39, 5));
    }

    #[Test]
    public function no_engagement_for_over_ninety_days_is_dormant(): void
    {
        $this->assertSame(LeadTemperature::Dormant, LeadTemperature::derive(90, 91));
        $this->assertSame(LeadTemperature::Dormant, LeadTemperature::derive(100, 365));
    }

    #[Test]
    public function a_lead_that_never_engaged_is_dormant(): void
    {
        $this->assertSame(LeadTemperature::Dormant, LeadTemperature::derive(0, null));
    }

    #[Test]
    public function a_suppressed_lead_is_always_dormant(): void
    {
        // However hot it once was: we cannot contact it, so it must not sit at
        // the top of anyone's call list.
        $this->assertSame(
            LeadTemperature::Dormant,
            LeadTemperature::derive(100, 1, isSuppressed: true)
        );
    }

    #[Test]
    public function it_defines_the_four_required_temperatures(): void
    {
        $this->assertEqualsCanonicalizing(
            ['hot', 'warm', 'cold', 'dormant'],
            LeadTemperature::values()
        );
    }
}
