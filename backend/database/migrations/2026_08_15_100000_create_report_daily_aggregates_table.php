<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per calendar day of dashboard metrics (FR-RPT-05, GLOSSARY Part 3).
 *
 * `crm:aggregate-daily-reports` writes these; BusinessReportService reads them
 * instead of calls/messages/lead_status_history for any requested period that
 * is entirely FULLY PAST history - the tables a dashboard would otherwise scan
 * on every load, and the ones that only ever grow.
 *
 * Only genuinely PERIOD-scoped, additive figures live here. Two categories of
 * field from BusinessReportService::summary()/revenue() are deliberately
 * absent, not forgotten:
 *
 *   - Current-moment SNAPSHOTS (`leads.total`, `leads.hot`,
 *     `revenue.outstanding`, `revenue.overdue`) already answer "what is true
 *     right now", not "what happened on this day" - the live code computes
 *     them with no period bound at all. Summing a snapshot across days is not
 *     meaningful, and reading a stale one back would silently disagree with
 *     the live figure the moment new data arrives. These stay live queries in
 *     every code path, aggregated or not.
 *   - `messages.delivered` was in the FR-RPT-05 field-list sketch but
 *     BusinessReportService::messageCounts() never computes a delivered split
 *     (that lives in campaign reporting, out of scope here) - so there is
 *     nothing to store under that name. `messages_by_channel` exists instead,
 *     because messageCounts() DOES return a per-channel breakdown and the
 *     aggregate has to be able to reproduce it exactly.
 *
 * `conversion()` and productPerformance()/sourcePerformance()/
 * campaignPerformance() are not aggregated here either - out of this task's
 * bound, left as live queries (see BusinessReportService's class docblock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_daily_aggregates', function (Blueprint $table) {
            $table->id();

            // Reserved for future multi-tenancy (ADR-C), like every other
            // table. Never nullable - see crm.php's `default_tenant_id`.
            $table->unsignedBigInteger('tenant_id')->default(0);

            // The calendar day, in ORG_TIMEZONE - not the UTC day the rows
            // being summarised are stored in (GLOSSARY §2.1).
            $table->date('aggregate_date');

            // Leads (GLOSSARY §2.1/2.4 event-time metrics only).
            $table->unsignedInteger('leads_new')->default(0);
            $table->unsignedInteger('leads_contacted')->default(0);
            $table->unsignedInteger('leads_interested')->default(0);

            // Calls (GLOSSARY §2.2).
            $table->unsignedInteger('calls_attempts')->default(0);
            $table->unsignedInteger('calls_connected')->default(0);
            // Seconds can exceed a day's worth once summed across many
            // connected calls; unsignedInteger would wrap on a busy queue.
            $table->unsignedBigInteger('calls_talk_time_seconds')->default(0);
            $table->unsignedInteger('calls_ai_calls')->default(0);

            // Follow-ups (FR-FUP-03).
            $table->unsignedInteger('follow_ups_scheduled')->default(0);
            $table->unsignedInteger('follow_ups_completed')->default(0);
            $table->unsignedInteger('follow_ups_missed')->default(0);

            // Messages (FR-COMM-*). Total plus the per-channel breakdown the
            // dashboard actually renders - see class docblock.
            $table->unsignedInteger('messages_total')->default(0);
            $table->json('messages_by_channel')->nullable();

            // Sales/pipeline counts (`salesSummary()`). `sales_count` is the
            // same query as revenue()'s `average_deal_size` denominator - one
            // column serves both.
            $table->unsignedInteger('sales_count')->default(0);
            $table->unsignedInteger('opportunities_opened')->default(0);
            $table->unsignedInteger('opportunities_lost')->default(0);

            // Revenue (GLOSSARY §2.5). Booked and collected stay separate
            // columns for the same reason they stay separate numbers on the
            // dashboard - summing them would report the money twice.
            $table->decimal('revenue_booked', 12, 2)->default(0);
            $table->decimal('revenue_collected', 12, 2)->default(0);
            $table->decimal('revenue_refunded', 12, 2)->default(0);

            $table->timestamps();

            // One row per tenant per day - what the upsert in
            // ReportAggregationService keys on, and what lets the read side
            // count rows in a range to detect a gap day.
            $table->unique(['tenant_id', 'aggregate_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_daily_aggregates');
    }
};
