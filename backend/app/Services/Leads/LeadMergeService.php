<?php

namespace App\Services\Leads;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use App\Services\Dnc\DncService;
use Illuminate\Support\Facades\DB;

/**
 * Merging two leads (BR-DUP-04, T-64).
 *
 * The rule is short - "merging retains both leads' notes, calls, messages and
 * status history; suppression is union" - and the second half is the one that
 * matters. **A merge must never be able to un-suppress somebody.** Every other
 * mistake here is recoverable by hand; that one puts a person who asked not to
 * be contacted back on a dialling list.
 *
 * The duplicate is soft-deleted, never destroyed. Retaining history is the
 * whole point of the rule, and a merge is the one operation in this system
 * with no undo - so the evidence of what was merged has to outlive it.
 */
class LeadMergeService
{
    /** Tables whose lead rows move wholesale to the survivor. */
    private const REPOINTED = [
        'calls', 'call_recordings', 'messages', 'lead_notes', 'lead_activities',
        'lead_status_history', 'lead_assignments', 'follow_ups', 'interest_signals',
        'dnc_entries', 'sales', 'payments', 'lead_import_rows',
    ];

    /**
     * Tables with a unique constraint on (something, lead_id).
     *
     * Repointing blindly would violate it whenever both leads carry the same
     * product, tag or campaign - so the duplicate's row is dropped in favour of
     * the survivor's, which already holds the same fact.
     *
     * @var array<string, string>
     */
    private const DEDUPED = [
        'lead_products' => 'product_id',
        'lead_tag' => 'tag_id',
        'campaign_recipients' => 'campaign_id',
        'customer_leads' => 'customer_id',
        'auto_dialer_queue_items' => 'auto_dialer_session_id',
    ];

    public function __construct(private readonly DncService $dnc) {}

    /**
     * Merges `$duplicate` into `$survivor`.
     *
     * The survivor keeps its own id, phone and status. Nothing here advances a
     * status: transitions are authorised and audited (BR-STAT-05), and a merge
     * quietly moving a lead to `Converted` would be an unaudited status change
     * nobody chose. What the duplicate was is recorded on the timeline instead,
     * for a human to act on through the normal path.
     */
    public function merge(Lead $survivor, Lead $duplicate, ?User $actor = null, ?string $note = null): Lead
    {
        if ($survivor->id === $duplicate->id) {
            throw new ApiException(ErrorCode::ValidationFailed, 'A lead cannot be merged into itself.');
        }

        if ($duplicate->merged_into_id !== null || $duplicate->trashed()) {
            throw new ApiException(ErrorCode::ValidationFailed, 'That lead has already been merged.');
        }

        if ($survivor->merged_into_id !== null || $survivor->trashed()) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Cannot merge into a lead that was itself merged away. Use the lead it was merged into.',
            );
        }

        return DB::transaction(function () use ($survivor, $duplicate, $actor, $note) {
            $summary = $this->describe($duplicate);

            foreach (self::REPOINTED as $table) {
                DB::table($table)->where('lead_id', $duplicate->id)->update(['lead_id' => $survivor->id]);
            }

            foreach (self::DEDUPED as $table => $otherKey) {
                $this->repointWithoutCollision($table, $otherKey, $survivor->id, $duplicate->id);
            }

            // Customers created from the duplicate keep pointing at it as their
            // origin, deliberately: origin_lead_id records where a customer
            // actually came from, and rewriting it would make that a guess.
            $duplicate->forceFill([
                'merged_into_id' => $survivor->id,
                'merged_at' => now(),
                'updated_by' => $actor?->id,
            ])->save();

            $duplicate->delete();

            /*
             * Suppression is UNION (BR-DUP-04). The entries moved above, so
             * recomputing the survivor's flag from its own rows now includes
             * the duplicate's - which is what makes this a union rather than a
             * replacement. Recomputed rather than OR-ed by hand, so the flag
             * stays derived from the table it summarises.
             */
            $this->dnc->syncSuppressionFlag($survivor->refresh());

            LeadActivity::create([
                'lead_id' => $survivor->id,
                'user_id' => $actor?->id,
                'activity_type' => 'lead_merged',
                'title' => 'Duplicate merged in',
                'description' => trim($summary.($note !== null && $note !== '' ? ' — '.$note : '')),
                // Kept as a subject so the timeline entry still points at the
                // record it absorbed, which is soft-deleted rather than gone.
                'subject_type' => Lead::class,
                'subject_id' => $duplicate->id,
                'occurred_at' => now(),
            ]);

            /*
             * Audited separately from the timeline. The timeline is what a
             * telecaller reads; this is the compliance-grade record (SEC-AUD-04),
             * and a merge is the one lead operation with no undo - so it belongs
             * in the trail that outlives the lead's own history.
             */
            AuditLog::create([
                'user_id' => $actor?->id,
                'action' => 'lead_merged',
                'description' => sprintf('Lead #%d merged into #%d', $duplicate->id, $survivor->id),
                'new_values' => [
                    'survivor_id' => $survivor->id,
                    'merged_lead_id' => $duplicate->id,
                    'merged_phone' => $duplicate->phone_e164,
                    'note' => $note,
                ],
            ]);

            return $survivor->refresh();
        });
    }

    /**
     * Moves rows that would otherwise collide on a unique constraint.
     *
     * The survivor's row wins: both records assert the same fact - interested
     * in this product, tagged this way, targeted by this campaign - so keeping
     * the survivor's preserves the older, more-referenced row and drops a
     * genuine duplicate rather than inventing a merged one.
     */
    private function repointWithoutCollision(string $table, string $otherKey, int $survivorId, int $duplicateId): void
    {
        $existing = DB::table($table)
            ->where('lead_id', $survivorId)
            ->pluck($otherKey)
            ->all();

        if ($existing !== []) {
            DB::table($table)
                ->where('lead_id', $duplicateId)
                ->whereIn($otherKey, $existing)
                ->delete();
        }

        DB::table($table)->where('lead_id', $duplicateId)->update(['lead_id' => $survivorId]);
    }

    /**
     * A one-line record of what was absorbed.
     *
     * Written before anything moves, because afterwards the duplicate has no
     * calls, no messages and no status of its own to describe.
     */
    private function describe(Lead $duplicate): string
    {
        return sprintf(
            'Merged in lead #%d (%s, %s) — status %s, %d calls, %d messages, %d notes.',
            $duplicate->id,
            $duplicate->name,
            $duplicate->phone_e164,
            $duplicate->status->label(),
            DB::table('calls')->where('lead_id', $duplicate->id)->count(),
            DB::table('messages')->where('lead_id', $duplicate->id)->count(),
            DB::table('lead_notes')->where('lead_id', $duplicate->id)->count(),
        );
    }

    /**
     * Follows the merge chain to the lead that now holds the history.
     *
     * Loop-guarded: a cycle would be a bug rather than data, but a bug that
     * hangs a request is worse than one that returns the wrong lead.
     */
    public function resolve(Lead $lead): Lead
    {
        $seen = [];

        while ($lead->merged_into_id !== null && ! in_array($lead->id, $seen, true)) {
            $seen[] = $lead->id;
            $next = Lead::withTrashed()->find($lead->merged_into_id);

            if ($next === null) {
                break;
            }

            $lead = $next;
        }

        return $lead;
    }
}
