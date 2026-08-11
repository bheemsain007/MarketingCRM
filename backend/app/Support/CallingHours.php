<?php

namespace App\Support;

use App\Models\Lead;
use Carbon\CarbonImmutable;

/**
 * Permitted calling window (BR-CALL-04).
 *
 * Evaluated in the **lead's** timezone, not the office's. That distinction is
 * the whole point: a telecaller working late in Jaipur must not ring someone
 * whose local time is 22:40, and the CRM is the only thing that knows the
 * difference.
 *
 * Attempts outside the window are **deferred, never dropped** - the caller is
 * told when the lead becomes reachable, so the work reschedules itself instead
 * of disappearing.
 */
class CallingHours
{
    public function __construct(
        private readonly string $start,
        private readonly string $end,
        private readonly string $fallbackTimezone,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('crm.calling_hours.start'),
            (string) config('crm.calling_hours.end'),
            (string) config('crm.timezone'),
        );
    }

    /**
     * A lead with no timezone of its own falls back to the organisation's.
     *
     * Guessing from the phone number would be worse than the fallback: an
     * Indian mobile keeps its number when its owner moves, so the country code
     * says nothing about where they are right now.
     */
    public function timezoneFor(Lead $lead): string
    {
        return $lead->timezone ?: $this->fallbackTimezone;
    }

    public function isOpenFor(Lead $lead, ?CarbonImmutable $at = null): bool
    {
        $timezone = $this->timezoneFor($lead);
        $local = ($at ?? CarbonImmutable::now())->setTimezone($timezone);

        return $this->isWithinWindow($local);
    }

    /**
     * The next moment this lead may be called, in UTC.
     *
     * Returns now when the window is already open, so a caller can use this
     * unconditionally when scheduling.
     */
    public function nextOpeningFor(Lead $lead, ?CarbonImmutable $at = null): CarbonImmutable
    {
        $timezone = $this->timezoneFor($lead);
        $now = $at ?? CarbonImmutable::now();
        $local = $now->setTimezone($timezone);

        if ($this->isWithinWindow($local)) {
            return $now->utc();
        }

        [$startHour, $startMinute] = $this->parse($this->start);

        $opening = $local->setTime($startHour, $startMinute);

        // Past today's opening means the next one is tomorrow's.
        if ($local->greaterThanOrEqualTo($opening)) {
            $opening = $opening->addDay();
        }

        return $opening->utc();
    }

    /** Human-readable window, for an error a telecaller can act on. */
    public function describeFor(Lead $lead): string
    {
        return sprintf('%s–%s %s', $this->start, $this->end, $this->timezoneFor($lead));
    }

    private function isWithinWindow(CarbonImmutable $local): bool
    {
        [$startHour, $startMinute] = $this->parse($this->start);
        [$endHour, $endMinute] = $this->parse($this->end);

        $minutes = $local->hour * 60 + $local->minute;
        $from = $startHour * 60 + $startMinute;
        $to = $endHour * 60 + $endMinute;

        // A window that wraps past midnight (22:00-06:00) is not something this
        // business wants, but handling it costs one line and silently
        // misbehaving would cost a compliance complaint.
        return $from <= $to
            ? $minutes >= $from && $minutes < $to
            : $minutes >= $from || $minutes < $to;
    }

    /** @return array{0: int, 1: int} */
    private function parse(string $time): array
    {
        $parts = explode(':', $time);

        return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0)];
    }
}
