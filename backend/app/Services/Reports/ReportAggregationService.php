<?php

namespace App\Services\Reports;

use App\Models\ReportDailyAggregate;
use App\Support\Reporting\ReportPeriod;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Writes one calendar day's worth of `report_daily_aggregates` (FR-RPT-05).
 *
 * The read side is BusinessReportService::summary()/revenue(), which use these
 * rows instead of the raw calls/messages/lead_status_history tables whenever a
 * requested period is fully past history and every day in it has one.
 */
class ReportAggregationService
{
    public function __construct(private readonly BusinessReportService $reports) {}

    /**
     * Computes and upserts the row for one calendar day, in ORG_TIMEZONE.
     *
     * Refuses today or later (FR-RPT-05): a day still accumulating has no
     * finished figure to freeze, and writing one anyway would serve a partial
     * count as if it were final. Calls `liveSummary()`/`liveRevenue()`
     * directly - never `summary()`/`revenue()` - so this never reads back the
     * very row it is about to overwrite.
     */
    public function aggregateDate(Carbon $date): ReportDailyAggregate
    {
        $timezone = (string) config('crm.timezone', 'UTC');
        $dateString = $date->copy()->setTimezone($timezone)->toDateString();
        $today = Carbon::now($timezone)->toDateString();

        if ($dateString >= $today) {
            throw new InvalidArgumentException(
                "Refusing to aggregate {$dateString}: not fully past yet (today is {$today} in {$timezone}).",
            );
        }

        $period = ReportPeriod::between(
            Carbon::parse($dateString, $timezone)->startOfDay(),
            Carbon::parse($dateString, $timezone)->endOfDay(),
            $timezone,
        );

        // The single source of truth for "what happened this day" - the exact
        // query logic the live dashboard uses for a one-day period, never a
        // second implementation that could quietly drift from it.
        $summary = $this->reports->liveSummary($period);

        return ReportDailyAggregate::query()->updateOrCreate(
            [
                'tenant_id' => (int) config('crm.default_tenant_id', 0),
                'aggregate_date' => $dateString,
            ],
            [
                'leads_new' => $summary['leads']['new'],
                'leads_contacted' => $summary['leads']['contacted'],
                'leads_interested' => $summary['leads']['interested'],
                'calls_attempts' => $summary['calls']['attempts'],
                'calls_connected' => $summary['calls']['connected'],
                'calls_talk_time_seconds' => $summary['calls']['talk_time_seconds'],
                'calls_ai_calls' => $summary['calls']['ai_calls'],
                'follow_ups_scheduled' => $summary['follow_ups']['scheduled'],
                'follow_ups_completed' => $summary['follow_ups']['completed'],
                'follow_ups_missed' => $summary['follow_ups']['missed'],
                'messages_total' => $summary['messages']['total'],
                'messages_by_channel' => $summary['messages']['by_channel'],
                'sales_count' => $summary['sales']['won'],
                'opportunities_opened' => $summary['sales']['opportunities_opened'],
                'opportunities_lost' => $summary['sales']['opportunities_lost'],
                'revenue_booked' => $summary['revenue']['booked'],
                'revenue_collected' => $summary['revenue']['collected'],
                'revenue_refunded' => $summary['revenue']['refunded'],
            ],
        );
    }
}
