<?php

namespace App\Services\Reports;

use App\Enums\CallStatus;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\OpportunityStatus;
use App\Enums\PaymentStatus;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Opportunity;
use App\Models\Payment;
use App\Models\ReportDailyAggregate;
use App\Models\Sale;
use App\Support\Reporting\Rate;
use App\Support\Reporting\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Business reporting (Phase 27, FR-RPT-02/04/05/06).
 *
 * Every formula here is the one written in GLOSSARY Part 2, and the section is
 * cited on each method. That matters more than usual: most of these numbers
 * have a plausible-looking wrong version, and the wrong version is the one that
 * gets written by accident. Average call duration divided by *attempts* rather
 * than *connected* calls, for instance, silently punishes an agent working a
 * list of dead numbers.
 *
 * `summary()` and `revenue()` are read on every dashboard load, so FR-RPT-05
 * lets them read `report_daily_aggregates` instead of scanning calls/messages/
 * lead_status_history directly - but ONLY when the requested period is fully
 * past history and every day in it already has a row. `liveSummary()` and
 * `liveRevenue()` hold the original, unconditional query logic: `summary()`/
 * `revenue()` fall back to them whenever the aggregate path is not safely
 * usable, and `ReportAggregationService` calls them directly (never the smart
 * wrappers) to compute the one day it is about to write - a smart wrapper
 * reading its own not-yet-written row would be circular.
 *
 * Telecaller performance (FR-RPT-01) is deliberately NOT here. It needs the
 * attribution model decided, and attribution affects pay (T-24, Phase 26).
 */
class BusinessReportService
{
    /**
     * The dashboard tiles (FR-RPT-02), pre-aggregated where safe (FR-RPT-05).
     *
     * `conversion()` is called live unconditionally either way - it was not
     * part of the FR-RPT-05 field list and stays out of this task's bound,
     * alongside productPerformance()/sourcePerformance()/campaignPerformance()
     * (see class docblock).
     *
     * @return array<string, mixed>
     */
    public function summary(ReportPeriod $period): array
    {
        $agg = $this->periodAggregate($period);

        if ($agg === null) {
            return $this->liveSummary($period);
        }

        return [
            'leads' => [
                // Snapshots, not period figures - always live, aggregated or
                // not (see the migration's docblock for why).
                'total' => $this->leads()->count(),
                'new' => $agg['leads_new'],
                'contacted' => $agg['leads_contacted'],
                'interested' => $agg['leads_interested'],
                'hot' => $this->leads()->where('temperature', LeadTemperature::Hot->value)->count(),
                'interest_rate' => Rate::of(
                    $agg['leads_interested'],
                    $agg['leads_contacted'],
                    'leads contacted this period',
                )->toArray(),
            ],

            'calls' => [
                'attempts' => $agg['calls_attempts'],
                'connected' => $agg['calls_connected'],
                'connect_rate' => Rate::of($agg['calls_connected'], $agg['calls_attempts'], 'call attempts this period')->toArray(),
                'talk_time_seconds' => $agg['calls_talk_time_seconds'],
                'average_duration_seconds' => $agg['calls_connected'] === 0
                    ? null
                    // Weighted from the summed totals, never an average of
                    // daily averages - the same reason liveSummary() divides
                    // by connected calls and not attempts (GLOSSARY 2.2).
                    : round($agg['calls_talk_time_seconds'] / $agg['calls_connected'], 1),
                'ai_calls' => $agg['calls_ai_calls'],
            ],

            'follow_ups' => [
                'scheduled' => $agg['follow_ups_scheduled'],
                'completed' => $agg['follow_ups_completed'],
                'missed' => $agg['follow_ups_missed'],
            ],

            'messages' => [
                'total' => $agg['messages_total'],
                'by_channel' => $agg['messages_by_channel'],
            ],

            'sales' => [
                'won' => $agg['sales_count'],
                'opportunities_opened' => $agg['opportunities_opened'],
                'opportunities_lost' => $agg['opportunities_lost'],
            ],

            'revenue' => $this->revenueFromAggregate($agg),
            'conversion' => $this->conversion($period),
        ];
    }

    /**
     * The unconditional, always-live version of `summary()` (FR-RPT-05).
     *
     * This is the ONE place the query logic for these figures is written.
     * `ReportAggregationService` calls this for a single-day period to learn
     * what a day's row should hold, and `summary()` falls back to it whenever
     * the aggregate path is not safely usable.
     *
     * @return array<string, mixed>
     */
    public function liveSummary(ReportPeriod $period): array
    {
        [$from, $to] = $period->bounds();

        $newLeads = $this->leads()->whereBetween('created_at', [$from, $to])->count();

        // Contacted in period, for the interest rate's denominator. Measured by
        // the STATUS HISTORY event, not the lead's current status - a lead that
        // has since moved to Proposal was still contacted this month.
        $contacted = $this->leadsReaching([LeadStatus::Contacted], $from, $to);
        $interested = $this->leadsReaching([LeadStatus::Interested], $from, $to);

        $attempts = $this->calls($from, $to)->count();
        $connected = $this->calls($from, $to)->where('status', CallStatus::Connected->value)->count();

        return [
            'leads' => [
                // Total is a snapshot, not period-scoped - "how big is the
                // database" is a different question from "what happened this
                // month", and mixing them is how a dashboard becomes unreadable.
                'total' => $this->leads()->count(),
                'new' => $newLeads,
                'contacted' => $contacted,
                'interested' => $interested,
                'hot' => $this->leads()->where('temperature', LeadTemperature::Hot->value)->count(),
                'interest_rate' => Rate::of($interested, $contacted, 'leads contacted this period')->toArray(),
            ],

            'calls' => [
                'attempts' => $attempts,
                'connected' => $connected,
                'connect_rate' => Rate::of($connected, $attempts, 'call attempts this period')->toArray(),
                // Connected calls only. Ringing and failed attempts are not
                // talk time (GLOSSARY section 2.2).
                'talk_time_seconds' => (int) $this->calls($from, $to)
                    ->where('status', CallStatus::Connected->value)
                    ->sum('duration_seconds'),
                'average_duration_seconds' => $this->averageCallDuration($from, $to),
                // Populates at Phase 24; the tile exists so its absence is
                // visible rather than the metric being silently missing.
                'ai_calls' => $this->calls($from, $to)->where('dial_source', 'ai')->count(),
            ],

            'follow_ups' => [
                'scheduled' => FollowUp::whereBetween('created_at', [$from, $to])->count(),
                'completed' => FollowUp::where('status', 'completed')
                    ->whereBetween('completed_at', [$from, $to])->count(),
                'missed' => FollowUp::where('status', 'missed')
                    ->whereBetween('scheduled_at', [$from, $to])->count(),
            ],

            'messages' => $this->messageCounts($from, $to),

            'sales' => $this->salesSummary($period),
            'revenue' => $this->liveRevenue($period),
            'conversion' => $this->conversion($period),
        ];
    }

    /**
     * Revenue (GLOSSARY section 2.5), pre-aggregated where safe (FR-RPT-05).
     *
     * **Booked and collected are separate numbers and are never summed.** A
     * dashboard that added them would report money twice - once when the deal
     * was signed and again when it was paid.
     *
     * @return array<string, mixed>
     */
    public function revenue(ReportPeriod $period): array
    {
        $agg = $this->periodAggregate($period);

        return $agg === null ? $this->liveRevenue($period) : $this->revenueFromAggregate($agg);
    }

    /**
     * The unconditional, always-live version of `revenue()` (FR-RPT-05). See
     * `liveSummary()`'s docblock - same reasoning, same callers.
     *
     * @return array<string, mixed>
     */
    public function liveRevenue(ReportPeriod $period): array
    {
        [$from, $to] = $period->bounds();

        $booked = (float) Sale::whereBetween('sold_at', [$from, $to])->sum('amount');

        // By PAYMENT date, not sale date - money is collected when it arrives.
        $collected = (float) Payment::whereIn('status', [
            PaymentStatus::Paid->value,
            PaymentStatus::Partial->value,
        ])->whereBetween('paid_at', [$from, $to])->sum('amount');

        $refunded = (float) Payment::where('status', PaymentStatus::Refund->value)
            ->whereBetween('updated_at', [$from, $to])->sum('amount');

        $salesWon = Sale::whereBetween('sold_at', [$from, $to])->count();

        return [
            'booked' => round($booked, 2),
            'collected' => round($collected, 2),
            'refunded' => round($refunded, 2),
            'net' => round($collected - $refunded, 2),
            // Outstanding and overdue are snapshots of what is owed NOW, not
            // period figures - a debt does not belong to a month.
            'outstanding' => $this->outstanding(),
            'overdue' => $this->overdue(),
            'average_deal_size' => $salesWon === 0 ? null : round($booked / $salesWon, 2),
            'currency' => 'INR',
        ];
    }

    /**
     * Pipeline and conversion (GLOSSARY section 2.4).
     *
     * The headline rate is **cohort-based**: of the leads ASSIGNED in the
     * period, how many eventually converted. "Conversions this month over leads
     * this month" mixes two unrelated populations and produces nonsense in any
     * month where volume changed.
     *
     * @return array<string, mixed>
     */
    public function conversion(ReportPeriod $period): array
    {
        [$from, $to] = $period->bounds();

        $assigned = $this->leads()->whereBetween('assigned_at', [$from, $to])->count();

        // The cohort: those same leads, however long they took to convert.
        $convertedFromCohort = $this->leads()
            ->whereBetween('assigned_at', [$from, $to])
            ->where('status', LeadStatus::Converted->value)
            ->count();

        $closed = Opportunity::whereIn('status', [
            OpportunityStatus::Won->value,
            OpportunityStatus::Lost->value,
        ])->whereBetween('closed_at', [$from, $to]);

        $won = (clone $closed)->where('status', OpportunityStatus::Won->value)->count();
        $closedCount = $closed->count();

        return [
            'lead_to_sale' => Rate::of(
                $convertedFromCohort,
                $assigned,
                'leads assigned this period (cohort)',
            )->toArray(),

            'opportunity_win_rate' => Rate::of($won, $closedCount, 'opportunities closed this period')->toArray(),

            'average_sales_cycle_days' => $this->averageSalesCycle($from, $to),

            // A snapshot: open pipeline is what is live now, not what was
            // opened this month.
            'pipeline_value' => round(
                (float) Opportunity::where('status', OpportunityStatus::Open->value)->sum('value'),
                2,
            ),

            'loss_reasons' => $this->lossReasons($from, $to),
        ];
    }

    /**
     * Revenue and volume by product (FR-RPT-02, FR-PAY-04).
     *
     * @return array<int, array<string, mixed>>
     */
    public function productPerformance(ReportPeriod $period): array
    {
        [$from, $to] = $period->bounds();

        return DB::table('payments')
            ->join('products', 'products.id', '=', 'payments.product_id')
            ->whereIn('payments.status', [PaymentStatus::Paid->value, PaymentStatus::Partial->value])
            ->whereBetween('payments.paid_at', [$from, $to])
            ->groupBy('products.id', 'products.name')
            ->select([
                'products.id',
                'products.name',
                DB::raw('COUNT(*) as payment_count'),
                DB::raw('SUM(payments.amount) as collected'),
            ])
            ->orderByDesc('collected')
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->id,
                'name' => $row->name,
                'payments' => (int) $row->payment_count,
                'collected' => round((float) $row->collected, 2),
            ])
            ->all();
    }

    /**
     * Which sources actually produce business (FR-RPT-02).
     *
     * Leads and conversions per source, so marketing spend can be judged on
     * outcomes rather than volume - a source producing 400 leads and no sales
     * is worse than one producing 20 and five.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sourcePerformance(ReportPeriod $period): array
    {
        [$from, $to] = $period->bounds();

        return DB::table('leads')
            ->leftJoin('lead_sources', 'lead_sources.id', '=', 'leads.lead_source_id')
            ->whereNull('leads.deleted_at')
            ->whereBetween('leads.created_at', [$from, $to])
            ->groupBy('lead_sources.id', 'lead_sources.name')
            ->select([
                'lead_sources.id',
                DB::raw("COALESCE(lead_sources.name, 'Unattributed') as name"),
                DB::raw('COUNT(*) as lead_count'),
                DB::raw("SUM(CASE WHEN leads.status = 'converted' THEN 1 ELSE 0 END) as converted_count"),
            ])
            ->orderByDesc('lead_count')
            ->get()
            ->map(fn ($row) => [
                'source_id' => $row->id === null ? null : (int) $row->id,
                'name' => $row->name,
                'leads' => (int) $row->lead_count,
                'converted' => (int) $row->converted_count,
                'conversion_rate' => Rate::of(
                    (int) $row->converted_count,
                    (int) $row->lead_count,
                    'leads from this source in period',
                )->toArray(),
            ])
            ->all();
    }

    /**
     * Campaign performance (FR-RPT-02/05, BR-CAMP-*).
     *
     * The piece Phase 27 could not finish until the campaign engine existed
     * (Phase 18). Scoped to campaigns that **started** in the period - a draft
     * that never ran has no performance, and including it as a row of zeroes
     * only pads the report. The figures are the engine's own lifetime counters,
     * so this report and the campaign detail screen read the same numbers.
     *
     * Every ratio carries its denominator (FR-RPT-06). Two are worth naming:
     *   - `delivery_rate` is over messages *sent*, not targeted - a recipient
     *     skipped by the DNC gate was never sent to, and counting them against
     *     delivery would blame the campaign for obeying a suppression.
     *   - `skip_rate` is over *targeted*, because "how much of the audience did
     *     we refuse to contact" is exactly a question about the whole audience.
     *
     * Delivery figures depend on a provider webhook (FR-COMM-03); on the unkeyed
     * channels nothing confirms delivery, so `delivered` stays 0 and the rate
     * reads a truthful 0%, not a hidden gap.
     *
     * @return array<string, mixed>
     */
    public function campaignPerformance(ReportPeriod $period): array
    {
        [$from, $to] = $period->bounds();

        $campaigns = Campaign::query()
            ->whereBetween('started_at', [$from, $to])
            ->orderByDesc('started_at')
            ->get();

        $rows = $campaigns->map(fn (Campaign $c) => [
            'campaign_id' => $c->id,
            'name' => $c->name,
            'channel' => $c->channel->value,
            'channel_label' => $c->channel->label(),
            'status' => $c->status->value,
            'started_at' => $c->started_at?->toIso8601String(),
            'completed_at' => $c->completed_at?->toIso8601String(),

            'targeted' => $c->total_targeted,
            'queued' => $c->total_queued,
            'sent' => $c->total_sent,
            'delivered' => $c->total_delivered,
            'failed' => $c->total_failed,
            'skipped' => $c->total_skipped,

            'send_rate' => Rate::of($c->total_sent, $c->total_targeted, 'recipients targeted')->toArray(),
            'delivery_rate' => Rate::of($c->total_delivered, $c->total_sent, 'messages sent')->toArray(),
            'failure_rate' => Rate::of($c->total_failed, $c->total_sent, 'messages sent')->toArray(),
            'skip_rate' => Rate::of($c->total_skipped, $c->total_targeted, 'recipients targeted')->toArray(),

            'cost' => round((float) $c->cost, 2),
            'currency' => $c->currency,
        ])->all();

        return [
            'campaigns' => $rows,
            // Period roll-up, from the same rows - so the header and the table
            // can never disagree. Costs are summed; the outcome counts are not
            // rolled into a single rate, because averaging rates across
            // campaigns of different sizes is its own classic lie.
            'totals' => [
                'campaigns' => $campaigns->count(),
                'targeted' => (int) $campaigns->sum('total_targeted'),
                'sent' => (int) $campaigns->sum('total_sent'),
                'delivered' => (int) $campaigns->sum('total_delivered'),
                'failed' => (int) $campaigns->sum('total_failed'),
                'skipped' => (int) $campaigns->sum('total_skipped'),
                'cost' => round((float) $campaigns->sum(fn (Campaign $c) => (float) $c->cost), 2),
                'currency' => 'INR',
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // FR-RPT-05: pre-aggregation
    // -----------------------------------------------------------------------

    /**
     * Sums `report_daily_aggregates` over the period, or null if the aggregate
     * path is not safely usable (FR-RPT-05).
     *
     * Null whenever the period touches today/the future - a day still
     * accumulating has no finished row to read - or when any single day inside
     * a fully-past range has no row yet, e.g. a missed scheduler run. Either
     * way the caller falls back to the live query; correctness over speed, and
     * a missing aggregate must never silently under-count.
     *
     * @return array<string, mixed>|null
     */
    private function periodAggregate(ReportPeriod $period): ?array
    {
        $timezone = (string) config('crm.timezone', 'UTC');
        $today = Carbon::now($timezone)->toDateString();

        [$from, $to] = $period->bounds();
        $fromDate = $from->copy()->setTimezone($timezone)->toDateString();
        $toDate = $to->copy()->setTimezone($timezone)->toDateString();

        if ($toDate >= $today) {
            return null;
        }

        $expectedDays = (int) Carbon::parse($fromDate)->diffInDays(Carbon::parse($toDate)) + 1;

        $rows = ReportDailyAggregate::query()
            ->where('tenant_id', (int) config('crm.default_tenant_id', 0))
            ->whereBetween('aggregate_date', [$fromDate, $toDate])
            ->get();

        if ($rows->count() !== $expectedDays) {
            // A gap day - one or more calendar days in range never got a row
            // (a missed run, or a range predating this feature).
            return null;
        }

        $channelTotals = [];
        foreach ($rows as $row) {
            foreach ((array) ($row->messages_by_channel ?? []) as $channel => $count) {
                $channelTotals[$channel] = ($channelTotals[$channel] ?? 0) + (int) $count;
            }
        }

        return [
            'leads_new' => (int) $rows->sum('leads_new'),
            'leads_contacted' => (int) $rows->sum('leads_contacted'),
            'leads_interested' => (int) $rows->sum('leads_interested'),
            'calls_attempts' => (int) $rows->sum('calls_attempts'),
            'calls_connected' => (int) $rows->sum('calls_connected'),
            'calls_talk_time_seconds' => (int) $rows->sum('calls_talk_time_seconds'),
            'calls_ai_calls' => (int) $rows->sum('calls_ai_calls'),
            'follow_ups_scheduled' => (int) $rows->sum('follow_ups_scheduled'),
            'follow_ups_completed' => (int) $rows->sum('follow_ups_completed'),
            'follow_ups_missed' => (int) $rows->sum('follow_ups_missed'),
            'messages_total' => (int) $rows->sum('messages_total'),
            'messages_by_channel' => $channelTotals,
            'sales_count' => (int) $rows->sum('sales_count'),
            'opportunities_opened' => (int) $rows->sum('opportunities_opened'),
            'opportunities_lost' => (int) $rows->sum('opportunities_lost'),
            // Explicit float casts inside the callback, not on the sum: the
            // column is `decimal:2`-cast (a string), and summing strings
            // relies on PHP's numeric-string coercion rather than saying so.
            'revenue_booked' => (float) $rows->sum(fn (ReportDailyAggregate $r) => (float) $r->revenue_booked),
            'revenue_collected' => (float) $rows->sum(fn (ReportDailyAggregate $r) => (float) $r->revenue_collected),
            'revenue_refunded' => (float) $rows->sum(fn (ReportDailyAggregate $r) => (float) $r->revenue_refunded),
        ];
    }

    /**
     * Builds `revenue()`'s shape from a `periodAggregate()` result. Shared by
     * `revenue()` and `summary()` so a summary read costs one aggregates query,
     * not two (FR-RPT-05).
     *
     * @param  array<string, mixed>  $agg
     * @return array<string, mixed>
     */
    private function revenueFromAggregate(array $agg): array
    {
        return [
            'booked' => round($agg['revenue_booked'], 2),
            'collected' => round($agg['revenue_collected'], 2),
            'refunded' => round($agg['revenue_refunded'], 2),
            'net' => round($agg['revenue_collected'] - $agg['revenue_refunded'], 2),
            // Snapshots of what is owed NOW - always live (see periodAggregate()).
            'outstanding' => $this->outstanding(),
            'overdue' => $this->overdue(),
            'average_deal_size' => $agg['sales_count'] === 0
                ? null
                : round($agg['revenue_booked'] / $agg['sales_count'], 2),
            'currency' => 'INR',
        ];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The base lead query.
     *
     * Archived leads are excluded from EVERY metric (GLOSSARY section 2.1), so
     * the exclusion lives here rather than being remembered at each call site.
     */
    private function leads(): Builder
    {
        return Lead::query();
    }

    private function calls($from, $to): Builder
    {
        return Call::query()
            ->whereBetween('started_at', [$from, $to])
            // Archived leads leave every metric, and a call belongs to a lead.
            ->whereHas('lead');
    }

    /**
     * Leads that REACHED a status during the period, from the append-only
     * history rather than their current value.
     *
     * A lead contacted in March and now at Proposal was still contacted in
     * March; reading the current status would lose it from the denominator and
     * inflate every rate built on it.
     *
     * @param  array<int, LeadStatus>  $statuses
     */
    private function leadsReaching(array $statuses, $from, $to): int
    {
        return DB::table('lead_status_history')
            ->join('leads', 'leads.id', '=', 'lead_status_history.lead_id')
            ->whereNull('leads.deleted_at')
            ->whereIn('lead_status_history.to_status', array_map(fn (LeadStatus $s) => $s->value, $statuses))
            ->whereBetween('lead_status_history.created_at', [$from, $to])
            ->distinct()
            ->count('lead_status_history.lead_id');
    }

    /** GLOSSARY section 2.2: divided by CONNECTED calls, never by attempts. */
    private function averageCallDuration($from, $to): ?float
    {
        $connected = $this->calls($from, $to)->where('status', CallStatus::Connected->value);

        $count = (clone $connected)->count();

        return $count === 0 ? null : round((float) $connected->sum('duration_seconds') / $count, 1);
    }

    /** @return array<string, int> */
    private function messageCounts($from, $to): array
    {
        $rows = Message::whereBetween('created_at', [$from, $to])
            ->groupBy('channel')
            ->select('channel', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'channel');

        return [
            'total' => (int) $rows->sum(),
            'by_channel' => $rows->map(fn ($n) => (int) $n)->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function salesSummary(ReportPeriod $period): array
    {
        [$from, $to] = $period->bounds();

        return [
            'won' => Sale::whereBetween('sold_at', [$from, $to])->count(),
            'opportunities_opened' => Opportunity::whereBetween('created_at', [$from, $to])->count(),
            'opportunities_lost' => Opportunity::where('status', OpportunityStatus::Lost->value)
                ->whereBetween('closed_at', [$from, $to])->count(),
        ];
    }

    /** Σ (sale value − collected) for sales not fully paid. */
    private function outstanding(): float
    {
        $collectedPerSale = DB::table('payments')
            ->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::Partial->value])
            ->whereNull('deleted_at')
            ->groupBy('sale_id')
            ->select('sale_id', DB::raw('SUM(amount) as collected'));

        $total = DB::table('sales')
            ->leftJoinSub($collectedPerSale, 'p', 'p.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->select(DB::raw('SUM(GREATEST(sales.amount - COALESCE(p.collected, 0), 0)) as outstanding'))
            ->value('outstanding');

        return round((float) $total, 2);
    }

    private function overdue(): float
    {
        return round((float) Payment::where('status', PaymentStatus::Overdue->value)->sum('amount'), 2);
    }

    private function averageSalesCycle($from, $to): ?float
    {
        $days = DB::table('sales')
            ->join('leads', 'leads.id', '=', 'sales.lead_id')
            ->whereNull('sales.deleted_at')
            ->whereNull('leads.deleted_at')
            ->whereBetween('sales.sold_at', [$from, $to])
            ->select(DB::raw('AVG(DATEDIFF(sales.sold_at, leads.created_at)) as avg_days'))
            ->value('avg_days');

        return $days === null ? null : round((float) $days, 1);
    }

    /** @return array<int, array<string, mixed>> */
    private function lossReasons($from, $to): array
    {
        $rows = DB::table('opportunities')
            ->whereNull('deleted_at')
            ->where('status', OpportunityStatus::Lost->value)
            ->whereBetween('closed_at', [$from, $to])
            ->groupBy('lost_reason')
            ->select('lost_reason', DB::raw('COUNT(*) as total'))
            ->get();

        $totalLost = (int) $rows->sum('total');

        return $rows->map(fn ($row) => [
            'reason' => $row->lost_reason,
            'count' => (int) $row->total,
            'share' => Rate::of((int) $row->total, $totalLost, 'opportunities lost this period')->toArray(),
        ])->all();
    }
}
