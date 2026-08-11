<?php

namespace App\Services\Calls;

use App\Enums\DialerSkipReason;
use App\Enums\DialerState;
use App\Enums\ErrorCode;
use App\Enums\LeadStatus;
use App\Enums\QueueItemState;
use App\Exceptions\ApiException;
use App\Models\AutoDialerQueueItem;
use App\Models\AutoDialerSession;
use App\Models\Call;
use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Auto dialer (Phase 10 — FR-CALL-06/07, BR-CALL-02/03).
 *
 * The dialer decides *which lead is next* and whether it may be called. It
 * does not place the call — that is the device's job under ADR-B — so nothing
 * here changes if the dialling mechanism is decided differently. It hands out
 * a dial intent, exactly as manual calling does, through the same
 * `CallService` gate.
 *
 * Two properties are load-bearing:
 *
 * - **Skips are recorded, never silent** (FR-CALL-07). A lead that vanished
 *   from a run is indistinguishable from one that was never queued.
 * - **No two telecallers are handed the same lead** (BR-CALL-03), enforced
 *   with a row lock rather than a Redis mutex, because the shared-hosting
 *   target has no Redis (DEPLOYMENT §3A).
 */
class AutoDialerService
{
    /** Lead fields a caller may narrow the queue by. */
    private const ALLOWED_FILTERS = ['status', 'lead_source_id', 'campaign_id', 'city', 'state', 'priority'];

    public function __construct(
        private readonly CallService $calls,
    ) {}

    // -----------------------------------------------------------------------
    // Session lifecycle (FR-CALL-06)
    // -----------------------------------------------------------------------

    /**
     * Builds a queue and opens a run.
     *
     * @param  array<string, mixed>  $filters
     *
     * @throws ApiException when the telecaller already has a run open
     */
    public function start(User $actor, array $filters = []): AutoDialerSession
    {
        if ($existing = $this->currentFor($actor)) {
            // One run at a time. Two open sessions would double-claim leads
            // and make "resume where I left off" meaningless.
            throw new ApiException(
                ErrorCode::Conflict,
                'You already have a dialling session open.',
                context: ['session_id' => $existing->id, 'state' => $existing->state->value],
            );
        }

        $leads = $this->buildQueue($actor, $filters);

        if ($leads->isEmpty()) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'No leads match these filters, so there is nothing to dial.',
            );
        }

        return DB::transaction(function () use ($actor, $filters, $leads) {
            $session = AutoDialerSession::create([
                'tenant_id' => config('crm.default_tenant_id'),
                'user_id' => $actor->id,
                'filters' => $this->sanitiseFilters($filters),
            ]);

            $session->forceFill([
                'state' => DialerState::Running,
                'started_at' => now(),
                'total_items' => $leads->count(),
            ])->save();

            $position = 0;

            foreach ($leads as $lead) {
                AutoDialerQueueItem::create([
                    'auto_dialer_session_id' => $session->id,
                    'lead_id' => $lead->id,
                    'position' => ++$position,
                    'state' => QueueItemState::Pending,
                ]);
            }

            return $session->fresh();
        });
    }

    /**
     * Hands out the next dialable lead, skipping and logging the rest.
     *
     * `lead` is null when the run is exhausted, and the session is completed
     * at that point rather than left open pretending there is more work. The
     * `skipped` list is returned either way: a run that ends *because*
     * everything left was skipped is precisely when the telecaller needs to
     * know why, and returning a bare null would throw that away.
     *
     * @return array{item: ?AutoDialerQueueItem, lead: ?Lead, call: ?Call, skipped: array<int, array<string, mixed>>, finished: bool}
     *
     * @throws ApiException when the session is not running
     */
    public function next(AutoDialerSession $session): array
    {
        $this->guardRunning($session);

        /*
         * Serialises next() for this session so a double-tapped button cannot
         * claim two leads. Uses the cache lock rather than Redis directly:
         * Laravel's database lock driver is the shared-hosting fallback, and
         * the code must not care which is configured.
         */
        $lock = Cache::lock('dialer:next:'.$session->id, 10);

        if (! $lock->get()) {
            throw new ApiException(
                ErrorCode::Conflict,
                'Another request is already fetching the next lead.',
            );
        }

        try {
            // Whatever was in progress is finished the moment the telecaller
            // asks for the next one. No cross-service callback needed, and a
            // dangling claim cannot outlive the run.
            $this->closeCurrentItem($session);

            $skipped = [];

            while ($item = $this->nextPendingItem($session)) {
                $lead = $item->lead;

                if ($reason = $this->skipReasonFor($lead, $session)) {
                    $this->skip($session, $item, $reason);

                    $skipped[] = [
                        'lead_id' => $lead->id,
                        'lead_name' => $lead->name,
                        'reason' => $reason->value,
                        'reason_label' => $reason->label(),
                        'is_temporary' => $reason->isTemporary(),
                    ];

                    continue;
                }

                $call = $this->claim($session, $item, $lead);

                return [
                    'item' => $item->fresh(),
                    'lead' => $lead,
                    'call' => $call,
                    'skipped' => $skipped,
                    'finished' => false,
                ];
            }

            $this->complete($session);

            return [
                'item' => null,
                'lead' => null,
                'call' => null,
                'skipped' => $skipped,
                'finished' => true,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Pauses without losing queue position (FR-CALL-06).
     *
     * The in-progress claim is released so a paused telecaller does not sit on
     * a lead nobody else can call.
     */
    public function pause(AutoDialerSession $session): AutoDialerSession
    {
        $this->guardRunning($session);

        $this->closeCurrentItem($session);

        $session->forceFill([
            'state' => DialerState::Paused,
            'paused_at' => now(),
        ])->save();

        return $session->fresh();
    }

    public function resume(AutoDialerSession $session): AutoDialerSession
    {
        if ($session->state !== DialerState::Paused) {
            throw new ApiException(
                ErrorCode::Conflict,
                'Only a paused session can be resumed.',
                context: ['state' => $session->state->value],
            );
        }

        $session->forceFill([
            'state' => DialerState::Running,
            'paused_at' => null,
        ])->save();

        return $session->fresh();
    }

    public function stop(AutoDialerSession $session, string $reason = 'stopped'): AutoDialerSession
    {
        if ($session->state->isFinished()) {
            return $session;
        }

        $this->closeCurrentItem($session);

        $session->forceFill([
            'state' => DialerState::Stopped,
            'ended_at' => now(),
            'end_reason' => $reason,
        ])->save();

        return $session->fresh();
    }

    public function currentFor(User $user): ?AutoDialerSession
    {
        return AutoDialerSession::query()
            ->where('user_id', $user->id)
            ->whereIn('state', [DialerState::Running->value, DialerState::Paused->value])
            ->latest('id')
            ->first();
    }

    // -----------------------------------------------------------------------
    // Skip rules (FR-CALL-07, BR-CALL-02)
    // -----------------------------------------------------------------------

    /**
     * Why this lead cannot be dialled right now, or null if it can.
     *
     * Ordered cheapest-and-most-permanent first: there is no point asking
     * whether we are inside calling hours for a lead that has no phone number.
     */
    public function skipReasonFor(Lead $lead, ?AutoDialerSession $session = null): ?DialerSkipReason
    {
        if ($lead->trashed()) {
            return DialerSkipReason::Archived;
        }

        /*
         * Suppression, calling hours and a usable number are all asked of
         * CallService, not re-implemented here. The dialer must not be able to
         * disagree with the manual-call gate about whether a lead may be
         * rung - if it could, the disagreement would only ever be discovered
         * by ringing somebody who is on the do-not-contact list.
         */
        $callability = $this->calls->callability($lead);

        if (! $callability['callable']) {
            return match ($callability['reason']) {
                ErrorCode::DncSuppressed->value => DialerSkipReason::Suppressed,
                ErrorCode::CallOutsideCallingHours->value => DialerSkipReason::OutsideCallingHours,
                ErrorCode::LeadArchived->value => DialerSkipReason::Archived,
                default => DialerSkipReason::NoPhone,
            };
        }

        // BR-CALL-02: recently contacted. Calling somebody twice in a day is
        // how a CRM turns a warm lead cold.
        $cooldownHours = (int) config('crm.contact_cooldown_hours');

        if ($cooldownHours > 0 && $lead->last_contacted_at !== null
            && $lead->last_contacted_at->gt(now()->subHours($cooldownHours))) {
            return DialerSkipReason::Cooldown;
        }

        // BR-CALL-02: an open follow-up in the future means somebody has
        // already agreed a time. The dialer must not pre-empt it.
        $hasFutureFollowUp = $lead->followUps()
            ->where('status', 'open')
            ->where('scheduled_at', '>', now())
            ->exists();

        if ($hasFutureFollowUp) {
            return DialerSkipReason::FollowUpScheduled;
        }

        // BR-CALL-03: somebody else is on this lead right now.
        if ($this->isClaimedElsewhere($lead, $session)) {
            return DialerSkipReason::ClaimedElsewhere;
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @return Collection<int, Lead>
     */
    private function buildQueue(User $actor, array $filters)
    {
        $query = Lead::query()
            ->with([])
            // Cheap pre-filters. The authoritative checks run per lead at dial
            // time, because a queue built now is worked over the next hour and
            // a lead can be suppressed in between.
            ->whereNotNull('phone_e164')
            ->where('phone_e164', '!=', '')
            ->where('is_suppressed', false)
            ->whereNotIn('status', [
                LeadStatus::Converted->value,
                LeadStatus::Lost->value,
                LeadStatus::NotInterested->value,
            ]);

        // Scope first, exactly as every list does: a telecaller's run contains
        // their own book, never the whole database (SEC-AUTHZ-03).
        $actor->applyDataScope($query);

        foreach ($this->sanitiseFilters($filters) as $field => $value) {
            $query->where($field, $value);
        }

        return $query
            // Highest priority first, then whoever has waited longest -
            // untouched leads sort ahead of everything (GLOSSARY §2.9).
            ->orderByDesc('priority')
            ->orderByRaw('last_contacted_at IS NULL DESC')
            ->orderBy('last_contacted_at')
            ->orderBy('id')
            ->limit(max(1, (int) config('crm.dialer.max_queue_size')))
            ->get();
    }

    private function nextPendingItem(AutoDialerSession $session): ?AutoDialerQueueItem
    {
        return $session->items()
            ->where('state', QueueItemState::Pending->value)
            ->with('lead')
            ->orderBy('position')
            ->first();
    }

    /**
     * Claims the lead and creates the dial intent.
     *
     * The claim and the call are written in one transaction: a claim without a
     * call would block the lead for nothing, and a call without a claim would
     * let a colleague dial the same person.
     */
    private function claim(AutoDialerSession $session, AutoDialerQueueItem $item, Lead $lead)
    {
        return DB::transaction(function () use ($session, $item, $lead) {
            $item->forceFill([
                'state' => QueueItemState::Dialling,
                'claimed_at' => now(),
            ])->save();

            // Runs the full gate again - suppression, calling hours, a usable
            // number. The pre-check above is for reporting the skip nicely;
            // THIS is the check that actually protects the lead.
            $call = $this->calls->initiate($lead, $session->user, [
                'dial_source' => 'auto_dialer',
                'auto_dialer_session_id' => $session->id,
            ]);

            $item->forceFill(['call_id' => $call->id])->save();

            AutoDialerSession::whereKey($session->id)->update([
                'dialled_items' => DB::raw('dialled_items + 1'),
                'updated_at' => now(),
            ]);

            return $call;
        });
    }

    private function skip(AutoDialerSession $session, AutoDialerQueueItem $item, DialerSkipReason $reason): void
    {
        DB::transaction(function () use ($session, $item, $reason) {
            $item->forceFill([
                'state' => QueueItemState::Skipped,
                'skip_reason' => $reason,
                'completed_at' => now(),
            ])->save();

            AutoDialerSession::whereKey($session->id)->update([
                'skipped_items' => DB::raw('skipped_items + 1'),
                'updated_at' => now(),
            ]);
        });
    }

    /** Closes whatever this session had in progress. */
    private function closeCurrentItem(AutoDialerSession $session): void
    {
        $session->items()
            ->where('state', QueueItemState::Dialling->value)
            ->get()
            ->each(function (AutoDialerQueueItem $item) {
                $item->forceFill([
                    'state' => QueueItemState::Dialled,
                    'completed_at' => now(),
                ])->save();
            });
    }

    /**
     * Is another session currently holding this lead? (BR-CALL-03)
     *
     * A row lock rather than a distributed mutex, so the guarantee holds on
     * the shared-hosting target where Redis is unavailable. Claims older than
     * the TTL are ignored - otherwise one closed laptop takes a lead out of
     * circulation permanently.
     */
    private function isClaimedElsewhere(Lead $lead, ?AutoDialerSession $session): bool
    {
        $cutoff = CarbonImmutable::now()->subMinutes((int) config('crm.dialer.claim_ttl_minutes'));

        return AutoDialerQueueItem::query()
            ->where('lead_id', $lead->id)
            ->where('state', QueueItemState::Dialling->value)
            ->when($session, fn ($query) => $query->where('auto_dialer_session_id', '!=', $session->id))
            ->where('claimed_at', '>', $cutoff)
            ->lockForUpdate()
            ->exists();
    }

    private function complete(AutoDialerSession $session): void
    {
        $session->forceFill([
            'state' => DialerState::Completed,
            'ended_at' => now(),
            'end_reason' => 'queue_exhausted',
        ])->save();
    }

    /**
     * @throws ApiException
     */
    private function guardRunning(AutoDialerSession $session): void
    {
        if ($session->state === DialerState::Running) {
            return;
        }

        throw new ApiException(
            ErrorCode::Conflict,
            $session->state === DialerState::Paused
                ? 'This session is paused. Resume it before dialling.'
                : 'This dialling session has ended.',
            context: ['state' => $session->state->value],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function sanitiseFilters(array $filters): array
    {
        return array_filter(
            array_intersect_key($filters, array_flip(self::ALLOWED_FILTERS)),
            fn ($value) => $value !== null && $value !== '',
        );
    }
}
