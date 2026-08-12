<?php

namespace App\Services\Reports;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Support\Reporting\ReportPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Suppression / skip reporting (Phase 19, BR-DNC-05, FR-DNC-03).
 *
 * The rule this report exists for: a send that was refused because the lead is
 * suppressed must be VISIBLE, not a silent nothing. Every skip is already
 * recorded as a message row with `status = skipped` and a `skip_reason`
 * (BR-DNC-05); this aggregates them so "how many people did we decline to
 * contact, and why" is one query rather than a manual count.
 *
 * Two different questions, deliberately kept apart:
 *   - **Skips** are period-scoped events: how many sends the gate stopped in the
 *     window, by channel and reason. This is the operational number.
 *   - **Active suppressions** are a snapshot of who is on the list right now, by
 *     reason. A debt does not belong to a month and neither does a standing
 *     opt-out, so it is never period-scoped.
 *
 * Calls skipped by the DNC gate are logged by the dialer with their own skip
 * reasons and are not folded in here - this is the outbound *message* view.
 */
class SuppressionReportService
{
    /** @return array<string, mixed> */
    public function report(ReportPeriod $period): array
    {
        [$from, $to] = $period->bounds();

        $skipped = fn () => DB::table('messages')
            ->where('status', 'skipped')
            ->whereBetween('created_at', [$from, $to]);

        $total = $skipped()->count();

        $byChannel = $skipped()
            ->groupBy('channel')
            ->select('channel', DB::raw('COUNT(*) as total'))
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'channel' => $row->channel,
                'channel_label' => Channel::from($row->channel)->label(),
                'total' => (int) $row->total,
            ])
            ->all();

        // Grouped on the raw reason string - 'suppressed' and
        // 'suppressed_after_queueing' are genuinely different events (the second
        // is BR-DNC-03: opted out while the message sat in the queue), and
        // collapsing them would hide the window this system exists to close.
        $byReason = $skipped()
            ->groupBy('skip_reason')
            ->select('skip_reason', DB::raw('COUNT(*) as total'))
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'reason' => $row->skip_reason,
                'total' => (int) $row->total,
            ])
            ->all();

        return [
            'skips' => [
                'total' => $total,
                'by_channel' => $byChannel,
                'by_reason' => $byReason,
            ],
            'active_suppressions' => $this->activeSuppressions(),
        ];
    }

    /**
     * A snapshot of the active suppression list by reason (BR-DNC-06). Only
     * `active` rows: a lifted suppression is kept for the audit trail, not
     * counted as though the person were still on the list.
     *
     * @return array<string, mixed>
     */
    private function activeSuppressions(): array
    {
        $rows = DB::table('dnc_entries')
            ->where('active', true)
            ->groupBy('reason')
            ->select('reason', DB::raw('COUNT(*) as total'))
            ->orderByDesc('total')
            ->get();

        return [
            'total' => (int) $rows->sum('total'),
            'by_reason' => $rows->map(fn ($row) => [
                'reason' => $row->reason,
                'reason_label' => DncReason::from($row->reason)->label(),
                'total' => (int) $row->total,
            ])->all(),
        ];
    }
}
