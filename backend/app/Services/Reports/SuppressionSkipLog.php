<?php

namespace App\Services\Reports;

use App\Enums\CampaignSkipReason;
use App\Enums\Channel;
use App\Enums\DataScope;
use App\Enums\DialerSkipReason;
use App\Enums\DncReason;
use App\Models\Lead;
use App\Models\User;
use App\Support\Reporting\ReportPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The row-level skip log (BR-DNC-05, FR-DNC-03).
 *
 * `SuppressionReportService` answers "how many did we decline to contact";
 * this answers "WHICH ones, and why". Both are needed and neither substitutes
 * for the other: a compliance question is about a person, and a total cannot be
 * argued with or acted on.
 *
 * Two tables record a refused attempt, so this reads both:
 *   - `messages` with status = skipped - manual sends and campaign sends alike;
 *   - `auto_dialer_queue_items` with a skip_reason - the calls the dialer never
 *     placed (FR-CALL-07).
 * Unioned rather than reported separately, because "who did we not reach today"
 * is one question and a user should not have to ask it twice.
 */
class SuppressionSkipLog
{
    /**
     * @param  array<string, string>  $filters  already allow-listed by SkipLogRequest
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(
        User $viewer,
        ReportPeriod $period,
        array $filters = [],
        ?string $search = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $attempts = DB::query()->fromSub(
            $this->messageAttempts($viewer, $period)->unionAll($this->dialerAttempts($viewer, $period)),
            'attempts',
        );

        // Filtered on the unioned result rather than inside each branch, so a
        // filter cannot mean one thing for messages and another for calls.
        foreach ($filters as $field => $value) {
            $attempts->where($field, $value);
        }

        if ($search !== null && $search !== '') {
            $attempts->where(function (Builder $query) use ($search) {
                $query->where('lead_name', 'like', "%{$search}%")
                    ->orWhere('lead_phone', 'like', "%{$search}%");
            });
        }

        return $attempts
            ->orderByDesc('occurred_at')
            // Two attempts can share a timestamp to the second; without a
            // tiebreaker they could swap places between pages and one of them
            // would never be seen.
            ->orderBy('id')
            ->paginate($perPage)
            ->through(fn (object $row): array => $this->present($row));
    }

    /** Refused sends: `OutboundMessageService` and the campaign dispatcher both land here. */
    private function messageAttempts(User $viewer, ReportPeriod $period): Builder
    {
        [$from, $to] = $period->bounds();

        $query = DB::table('messages')
            ->join('leads', 'leads.id', '=', 'messages.lead_id')
            ->leftJoin('campaigns', 'campaigns.id', '=', 'messages.campaign_id')
            ->where('messages.status', 'skipped')
            ->whereNotNull('messages.skip_reason')
            ->whereBetween('messages.created_at', [$from, $to])
            ->select([
                // Ids collide across the two tables, so the key is composite.
                DB::raw("CONCAT('message-', messages.id) as id"),
                'messages.lead_id as lead_id',
                'leads.name as lead_name',
                'leads.phone_e164 as lead_phone',
                'messages.channel as channel',
                DB::raw("CASE WHEN messages.campaign_id IS NULL THEN 'manual' ELSE 'campaign' END as source"),
                'campaigns.name as campaign_name',
                'messages.skip_reason as reason',
                DB::raw($this->standingReasonSql('messages.lead_id').' as dnc_reason'),
                'messages.created_at as occurred_at',
            ]);

        $this->scope($query, $viewer, 'messages.lead_id');

        return $query;
    }

    /** Calls the dialer passed over (FR-CALL-07). */
    private function dialerAttempts(User $viewer, ReportPeriod $period): Builder
    {
        [$from, $to] = $period->bounds();

        $query = DB::table('auto_dialer_queue_items as items')
            ->join('leads', 'leads.id', '=', 'items.lead_id')
            ->whereNotNull('items.skip_reason')
            // A skip sets completed_at; the fallback covers rows written before
            // that was true rather than dropping them out of every window.
            ->whereRaw('COALESCE(items.completed_at, items.created_at) between ? and ?', [$from, $to])
            ->select([
                DB::raw("CONCAT('dialer-', items.id) as id"),
                'items.lead_id as lead_id',
                'leads.name as lead_name',
                'leads.phone_e164 as lead_phone',
                DB::raw("'".Channel::Call->value."' as channel"),
                DB::raw("'dialer' as source"),
                DB::raw('NULL as campaign_name'),
                'items.skip_reason as reason',
                DB::raw($this->standingReasonSql('items.lead_id').' as dnc_reason'),
                DB::raw('COALESCE(items.completed_at, items.created_at) as occurred_at'),
            ]);

        $this->scope($query, $viewer, 'items.lead_id');

        return $query;
    }

    /**
     * Why the lead is on the list at all - a scalar subquery, so a lead with
     * several active entries still produces exactly one row per attempt.
     *
     * The oldest active entry, because that is the standing instruction: a
     * later `Wrong Number` does not replace an explicit "do not contact".
     */
    private function standingReasonSql(string $leadColumn): string
    {
        return "(select entries.reason from dnc_entries entries
                 where entries.lead_id = {$leadColumn} and entries.active = 1
                 order by entries.id limit 1)";
    }

    /**
     * Scoped through the lead exactly as the DNC list is (SEC-AUTHZ-03).
     *
     * The aggregate report is deliberately unscoped - counts identify nobody -
     * but this log names people and phone numbers, so a telecaller sees their
     * own book and not the organisation's.
     */
    private function scope(Builder $query, User $viewer, string $leadColumn): void
    {
        if ($viewer->dataScope() === DataScope::All) {
            return;
        }

        $query->whereIn($leadColumn, $viewer->applyDataScope(Lead::query())->select('leads.id'));
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $channel = Channel::tryFrom((string) $row->channel);
        $standing = $row->dnc_reason !== null ? DncReason::tryFrom((string) $row->dnc_reason) : null;

        return [
            'id' => (string) $row->id,
            'lead' => [
                'id' => (int) $row->lead_id,
                'name' => (string) $row->lead_name,
                'phone' => (string) $row->lead_phone,
            ],
            'source' => (string) $row->source,
            'source_label' => $this->sourceLabel((string) $row->source),
            'campaign_name' => $row->campaign_name,
            'channel' => (string) $row->channel,
            'channel_label' => $channel?->label() ?? (string) $row->channel,
            'reason' => (string) $row->reason,
            'reason_label' => $this->reasonLabel((string) $row->reason, (string) $row->source),
            // Whether the DNC list itself caused this skip, as opposed to a
            // cooldown or a missing number. It is the difference between "we
            // are not allowed" and "we chose not to yet".
            'suppressed' => $this->isSuppression((string) $row->reason),
            'dnc_reason' => $row->dnc_reason,
            'dnc_reason_label' => $standing?->label(),
            'occurred_at' => Carbon::parse((string) $row->occurred_at)->toIso8601String(),
        ];
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'manual' => 'Manual send',
            'campaign' => 'Campaign',
            'dialer' => 'Auto-dialer',
            default => $source,
        };
    }

    /**
     * Skip reasons are written by three different writers, so the label comes
     * from whichever enum owns the value.
     *
     * `suppressed_after_queueing` belongs to no enum on purpose: it is
     * BR-DNC-03's post-queue window, and collapsing it into `suppressed` would
     * hide exactly the gap this system exists to close.
     */
    private function reasonLabel(string $reason, string $source): string
    {
        if ($reason === 'suppressed_after_queueing') {
            return 'Opted out while the message was queued';
        }

        $enum = $source === 'dialer'
            ? DialerSkipReason::tryFrom($reason)
            : CampaignSkipReason::tryFrom($reason);

        return $enum?->label() ?? $reason;
    }

    private function isSuppression(string $reason): bool
    {
        return in_array($reason, [
            CampaignSkipReason::Suppressed->value,
            'suppressed_after_queueing',
        ], true);
    }
}
