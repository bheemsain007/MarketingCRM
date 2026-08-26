<?php

namespace App\Console\Commands;

use App\Models\ReportDailyAggregate;
use App\Services\Reports\ReportAggregationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pre-aggregates `report_daily_aggregates` for fully-past calendar days
 * (FR-RPT-05).
 *
 * Two passes, both bounded - "do not re-aggregate the entire history every
 * run" is the whole point of the table existing:
 *
 *   - TRAILING: the last `--trailing` past days are always recomputed, even if
 *     a row already exists. This is the self-heal - a run that failed or was
 *     skipped last night corrects itself tonight with no operator action.
 *   - GAP: further back, up to `--lookback` more days, any day with NO row at
 *     all gets backfilled. After the first successful run this is normally
 *     zero work - one cheap existence check against the tiny aggregates
 *     table, not against calls/messages/lead_status_history.
 *
 * NEVER touches today - see ReportAggregationService::aggregateDate().
 */
class AggregateDailyReportsCommand extends Command
{
    protected $signature = 'crm:aggregate-daily-reports
                            {--trailing=3 : Always re-aggregate this many of the most recent past days (self-heal)}
                            {--lookback=400 : How many further days back to check for a day with no row at all}';

    protected $description = 'Pre-aggregate daily dashboard metrics for fully-past calendar days (FR-RPT-05)';

    public function handle(ReportAggregationService $aggregator): int
    {
        $timezone = (string) config('crm.timezone', 'UTC');
        $yesterday = Carbon::now($timezone)->subDay()->startOfDay();

        $trailing = max(0, (int) $this->option('trailing'));
        $lookback = max(0, (int) $this->option('lookback'));

        // Unconditional - the whole self-heal property depends on these being
        // recomputed every run regardless of whether a row is already there.
        $trailingDates = $trailing > 0
            ? collect(range(0, $trailing - 1))->map(fn (int $i) => $yesterday->copy()->subDays($i)->toDateString())
            : collect();

        $gapDates = collect();

        if ($lookback > 0) {
            $windowEnd = $yesterday->copy()->subDays($trailing)->toDateString();
            $windowStart = $yesterday->copy()->subDays($trailing + $lookback - 1)->toDateString();

            if ($windowStart <= $windowEnd) {
                $candidates = collect();
                for ($cursor = Carbon::parse($windowStart, $timezone); $cursor->toDateString() <= $windowEnd; $cursor->addDay()) {
                    $candidates->push($cursor->toDateString());
                }

                $existing = ReportDailyAggregate::query()
                    ->where('tenant_id', (int) config('crm.default_tenant_id', 0))
                    ->whereBetween('aggregate_date', [$windowStart, $windowEnd])
                    ->pluck('aggregate_date')
                    ->map(fn (Carbon $d) => $d->toDateString());

                $gapDates = $candidates->diff($existing);
            }
        }

        $dates = $trailingDates->merge($gapDates)->unique()->sort()->values();

        foreach ($dates as $dateString) {
            $aggregator->aggregateDate(Carbon::parse($dateString, $timezone));
        }

        $this->info(sprintf(
            '%d day(s) aggregated (%d trailing self-heal, %d gap day(s) backfilled).',
            $dates->count(),
            $trailingDates->count(),
            $gapDates->count(),
        ));

        return self::SUCCESS;
    }
}
