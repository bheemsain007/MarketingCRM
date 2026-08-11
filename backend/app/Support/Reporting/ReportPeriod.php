<?php

namespace App\Support\Reporting;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A reporting window (FR-RPT-04, GLOSSARY section 2.1).
 *
 * Rows are stored UTC; metrics are aggregated in the **organisation's**
 * timezone. Without that conversion a "March" figure in Asia/Kolkata silently
 * includes five and a half hours of February and excludes the same from March -
 * small enough to go unnoticed and large enough to make two dashboards
 * disagree.
 *
 * The boundaries are inclusive of the whole start day and the whole end day,
 * because a user asking for "1st to 31st" means the days, not the instants.
 */
class ReportPeriod
{
    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly string $timezone,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $timezone = (string) config('crm.timezone', 'UTC');

        $from = $request->filled('from')
            ? Carbon::parse((string) $request->query('from'), $timezone)
            // A default of "this month" rather than "all time": an unbounded
            // report over a growing table is the query that takes the dashboard
            // down (FR-RPT-05).
            : Carbon::now($timezone)->startOfMonth();

        $to = $request->filled('to')
            ? Carbon::parse((string) $request->query('to'), $timezone)
            : Carbon::now($timezone);

        return new self(
            $from->copy()->startOfDay()->utc(),
            $to->copy()->endOfDay()->utc(),
            $timezone,
        );
    }

    public static function between(Carbon $from, Carbon $to, ?string $timezone = null): self
    {
        return new self($from->copy()->utc(), $to->copy()->utc(), $timezone ?? (string) config('crm.timezone', 'UTC'));
    }

    /** @return array<int, Carbon> */
    public function bounds(): array
    {
        return [$this->from, $this->to];
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from->copy()->setTimezone($this->timezone)->toDateString(),
            'to' => $this->to->copy()->setTimezone($this->timezone)->toDateString(),
            // Stated explicitly so two people comparing dashboards can see they
            // are looking at the same window.
            'timezone' => $this->timezone,
        ];
    }
}
