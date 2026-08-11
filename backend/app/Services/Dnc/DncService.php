<?php

namespace App\Services\Dnc;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\DncEntry;
use App\Models\Lead;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The suppression gate (BR-DNC-01..07, ADR-E).
 *
 * **Every** outbound path - campaign job, individual send, human call, auto
 * dialer - asks this service whether a lead may be contacted. No channel
 * module may reimplement the decision, and nothing may gate a send on
 * `leads.is_suppressed`, which is a denormalised cache for list filtering and
 * nothing more.
 *
 * Built here, at Phase 7, rather than at Phase 19 as the roadmap has it: lead
 * status `Not Interested` must write suppression (BR-DNC-07), and status
 * changes land in this phase. The alternative was to write `dnc_entries`
 * directly from `LeadStatusService`, which is exactly the per-module
 * suppression ADR-E forbids. Phase 19 still owns the policy configuration,
 * admin UI, reporting and the full per-channel test matrix - see the
 * sequencing note in MODULE_STATUS and T-29.
 */
class DncService
{
    /**
     * Suppresses a lead, or returns the existing record if already suppressed
     * for the same reason and channel.
     *
     * Idempotent on purpose: `Not Interested` set twice, or a webhook
     * redelivering an opt-out, must not stack duplicate rows - a suppression
     * list that grows a row per retry is one nobody can audit.
     */
    public function suppress(
        Lead $lead,
        DncReason $reason,
        ?Channel $channel = null,
        string $source = 'manual',
        ?int $actorId = null,
        ?string $note = null,
    ): DncEntry {
        $existing = DncEntry::query()
            ->where('lead_id', $lead->id)
            ->where('reason', $reason->value)
            ->where('channel', $channel?->value)
            ->where('active', true)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($lead, $reason, $channel, $source, $actorId, $note) {
            $entry = DncEntry::create([
                'tenant_id' => config('crm.default_tenant_id'),
                'lead_id' => $lead->id,
                // Denormalised so a number stays suppressed even if the lead
                // record is later merged or removed (BR-DUP-04).
                'phone_e164' => $lead->phone_e164,
                'email' => $lead->email,
                'reason' => $reason->value,
                'channel' => $channel?->value,
                'source' => $source,
                'note' => $note,
                'created_by' => $actorId,
            ]);

            $this->syncSuppressionFlag($lead);

            return $entry;
        });
    }

    /**
     * Lifts a suppression (BR-DNC-06, FR-DNC-04).
     *
     * Deactivates rather than deletes: the record of who un-suppressed a lead,
     * when, and why is the whole point of the audit trail, and a DELETE would
     * destroy exactly the evidence a compliance question needs.
     *
     * Authority is the caller's business - `dnc.remove` is Manager+ and is in
     * `Permission::isAudited()`, so the middleware writes the access record
     * before this ever runs.
     *
     * @throws ApiException when the entry is already inactive
     */
    public function remove(DncEntry $entry, string $reason, ?int $actorId = null): DncEntry
    {
        if (! $entry->active) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'That suppression has already been removed.',
            );
        }

        return DB::transaction(function () use ($entry, $reason, $actorId) {
            $entry->forceFill([
                'active' => false,
                'removed_by' => $actorId,
                'removed_at' => now(),
                'removal_reason' => $reason,
            ])->save();

            // The lead may still be suppressed by a DIFFERENT entry, so the
            // flag is recomputed from the table rather than simply cleared.
            if ($entry->lead) {
                $this->syncSuppressionFlag($entry->lead);
            }

            return $entry->fresh();
        });
    }

    /**
     * The question every outbound path must ask (BR-DNC-01).
     *
     * Reads `dnc_entries`, never the cached flag. An entry with a null channel
     * blocks whatever its REASON blocks (BR-DNC-02) - so a wrong phone number
     * stops calls and SMS but leaves a perfectly good email address usable.
     */
    public function canContact(Lead $lead, Channel $channel): bool
    {
        foreach ($this->activeEntriesFor($lead) as $entry) {
            $blocked = $entry->channel !== null
                ? [$entry->channel]
                : $entry->reason->blockedChannels();

            if (in_array($channel, $blocked, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return Collection<int, DncEntry> */
    public function activeEntriesFor(Lead $lead)
    {
        return DncEntry::query()
            ->where('lead_id', $lead->id)
            ->where('active', true)
            ->get();
    }

    /**
     * Refreshes the denormalised list-filter flag on the lead.
     *
     * Derived from the entries, never set by hand: the moment the flag can be
     * written independently it starts disagreeing with the table it summarises,
     * and the disagreement is invisible until somebody calls a suppressed lead.
     */
    public function syncSuppressionFlag(Lead $lead): void
    {
        $suppressed = DncEntry::query()
            ->where('lead_id', $lead->id)
            ->where('active', true)
            ->exists();

        if ($lead->is_suppressed !== $suppressed) {
            $lead->forceFill(['is_suppressed' => $suppressed])->save();
        }
    }
}
