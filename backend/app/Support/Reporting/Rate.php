<?php

namespace App\Support\Reporting;

/**
 * A rate that carries its own denominator (FR-RPT-06).
 *
 * "Conversion rate: 34%" is ambiguous and will be misread. The requirement is
 * that every rate displays what it is a rate *of*, and the only way to make
 * that reliable is to make it structural: this object cannot be built without
 * naming the denominator, so no endpoint can return a bare percentage.
 *
 * A zero denominator yields `null`, never `0`. "No leads were assigned" and
 * "leads were assigned and none converted" are different facts, and a dashboard
 * that renders both as 0% is lying about one of them (GLOSSARY section 2.1).
 */
class Rate
{
    private function __construct(
        public readonly int $numerator,
        public readonly int $denominator,
        public readonly string $of,
    ) {}

    public static function of(int $numerator, int $denominator, string $of): self
    {
        return new self($numerator, $denominator, $of);
    }

    /** Null when there is nothing to divide by - the caller renders an em dash. */
    public function value(): ?float
    {
        return $this->denominator === 0
            ? null
            : round($this->numerator / $this->denominator * 100, 1);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'value' => $this->value(),
            'numerator' => $this->numerator,
            'denominator' => $this->denominator,
            // Rendered as "34.0% of leads assigned this period".
            'of' => $this->of,
        ];
    }
}
