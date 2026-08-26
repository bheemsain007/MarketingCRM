<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One calendar day's dashboard figures (FR-RPT-05).
 *
 * Written by `crm:aggregate-daily-reports` / ReportAggregationService, read by
 * BusinessReportService whenever a requested period is fully-past history and
 * every day in it has a row - see that class for why the snapshot fields
 * (leads total/hot, revenue outstanding/overdue) are NOT columns here.
 *
 * @property int $id
 * @property int $tenant_id
 * @property Carbon $aggregate_date
 * @property int $leads_new
 * @property int $leads_contacted
 * @property int $leads_interested
 * @property int $calls_attempts
 * @property int $calls_connected
 * @property int $calls_talk_time_seconds
 * @property int $calls_ai_calls
 * @property int $follow_ups_scheduled
 * @property int $follow_ups_completed
 * @property int $follow_ups_missed
 * @property int $messages_total
 * @property array<string, int> $messages_by_channel
 * @property int $sales_count
 * @property int $opportunities_opened
 * @property int $opportunities_lost
 * @property string $revenue_booked
 * @property string $revenue_collected
 * @property string $revenue_refunded
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReportDailyAggregate extends Model
{
    protected $fillable = [
        'tenant_id', 'aggregate_date',
        'leads_new', 'leads_contacted', 'leads_interested',
        'calls_attempts', 'calls_connected', 'calls_talk_time_seconds', 'calls_ai_calls',
        'follow_ups_scheduled', 'follow_ups_completed', 'follow_ups_missed',
        'messages_total', 'messages_by_channel',
        'sales_count', 'opportunities_opened', 'opportunities_lost',
        'revenue_booked', 'revenue_collected', 'revenue_refunded',
    ];

    protected function casts(): array
    {
        return [
            'aggregate_date' => 'date',
            'messages_by_channel' => 'array',
            'revenue_booked' => 'decimal:2',
            'revenue_collected' => 'decimal:2',
            'revenue_refunded' => 'decimal:2',
        ];
    }
}
