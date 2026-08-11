<?php

namespace App\Enums;

/**
 * Why a deal was lost (FR-SALE-05, BR-SALE-04).
 *
 * A closed enum rather than free text, because the requirement is that lost
 * sales are reportable BY REASON. Free text produces "price", "Price", "too
 * expensive" and "costly" as four separate answers to the same question, and
 * the report that was supposed to tell you why you are losing tells you
 * nothing.
 *
 * `Other` carries mandatory notes - see OpportunityService.
 */
enum LostReason: string
{
    case Price = 'price';
    case Competitor = 'competitor';
    case NoBudget = 'no_budget';
    case NoRequirement = 'no_requirement';
    case Timing = 'timing';
    case NoResponse = 'no_response';
    case Unreachable = 'unreachable';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Price => 'Price',
            self::Competitor => 'Lost to a competitor',
            self::NoBudget => 'No budget',
            self::NoRequirement => 'No requirement',
            self::Timing => 'Wrong timing',
            self::NoResponse => 'No response',
            self::Unreachable => 'Could not reach',
            self::Other => 'Other',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
